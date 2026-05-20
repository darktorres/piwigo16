<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Latte\Runtime\Html;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DebugCollector;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\OrderByService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\Cache\CacheItemPoolInterface;

final class CalendarService
{
    /** @var array<int,int|string> */
    private array $chronologyDate = [];
    private string $chronologyStyle = '';
    private string $chronologyView  = '';
    private string $comment         = '';
    /** @var list<string> */
    private array $items = [];

    public function __construct(
        private readonly CalendarRepository $calRepo,
        private readonly CategoryService $categoryService,
        private readonly DebugCollector $debugCollector,
        private readonly PermissionService $permissionService,
        private readonly UrlService $urlService,
        private readonly CacheItemPoolInterface $pool,
        private readonly OrderByService $orderByService,
    ) {
    }

    /** @return array<int,int|string> */
    public function getChronologyDate(): array
    {
        return $this->chronologyDate;
    }

    public function getChronologyStyle(): string
    {
        return $this->chronologyStyle;
    }

    public function getChronologyView(): string
    {
        return $this->chronologyView;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    /** @return list<string> */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param array<int,int|string> $chronologyDate
     * @param list<string>          $sectionItems
     * @param array<mixed>|null     $category
     */
    public function initializeCalendar(
        Section $section,
        ?array $category,
        bool $superOrderBy,
        string $chronologyField,
        ?string $chronologyStyle,
        ?string $chronologyView,
        array $chronologyDate,
        array $sectionItems,
    ): void {
        $template    = TemplateRegistry::current();
        $currentUser = CurrentUser::get();
        $user        = $currentUser->rawAttributes;

        $this->chronologyDate  = $chronologyDate;
        $this->items           = $sectionItems;
        $this->comment         = '';
        // Preserve URL-parsed values on early return; the resolution code
        // below overwrites these with normalised defaults when reached.
        $this->chronologyStyle = $chronologyStyle ?? '';
        $this->chronologyView  = $chronologyView ?? '';

        $innerSql = ' FROM ' . Tables::images();

        if ($section === Section::Categories) {
            $this->items = [];
            $innerSql .= '
INNER JOIN ' . Tables::imageCategory() . ' ON id = image_id';

            if ($category !== null) {
                $categoryIdRaw = $category['id'] ?? null;
                $forbiddenRaw  = $user['forbidden_categories'] ?? null;
                $forbidden     = is_array($forbiddenRaw)
                    ? array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $forbiddenRaw)
                    : [];
                $subIds = array_diff(
                    $this->categoryService->getSubcatIds([is_numeric($categoryIdRaw) ? (int) $categoryIdRaw : 0]),
                    $forbidden,
                );

                if (empty($subIds)) {
                    return;
                }
                $innerSql .= '
WHERE category_id IN (' . implode(',', $subIds) . ')';
                $innerPerm = $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], 'AND', false);
                $innerSql .= '
    ' . $innerPerm->where;
            } else {
                $innerPerm = $this->permissionService->getSqlConditionFandF(
                    ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
                    'WHERE',
                    true
                );
                $innerSql .= '
    ' . $innerPerm->where;
            }
            $innerParams = $innerPerm->params;
            $innerTypes  = $innerPerm->types;
        } else {
            if (empty($sectionItems)) {
                return;
            }
            $items = [];
            foreach ($sectionItems as $item) {
                $items[] = (string) (int) $item;
            }
            $innerSql .= '
WHERE id IN (' . implode(',', $items) . ')';
            $innerParams = [];
            $innerTypes  = [];
        }

        $this->debugCollector->collect('start initialize_calendar');

        $fields = [
            'created' => ['label' => Lang::t('Creation date')],
            'posted'  => ['label' => Lang::t('Post date')],
        ];

        $styles = [
            'monthly' => ['view_calendar' => true,  'classname' => CalendarMonthly::class],
            'weekly'  => ['view_calendar' => false, 'classname' => CalendarWeekly::class],
        ];

        $views = [CAL_VIEW_LIST, CAL_VIEW_CALENDAR];

        isset($fields[$chronologyField]) or HtmlService::fatalError('bad chronology field');

        $calStyle = (is_string($chronologyStyle) && isset($styles[$chronologyStyle])) ? $chronologyStyle : 'monthly';
        $this->chronologyStyle = $calStyle;
        $calendar = match ($calStyle) {
            'monthly' => new CalendarMonthly($this->calRepo),
            default   => new CalendarWeekly($this->calRepo),
        };

        $resolvedView = (is_string($chronologyView) && in_array($chronologyView, $views, true)) ? $chronologyView : CAL_VIEW_LIST;
        if (CAL_VIEW_CALENDAR === $resolvedView && !$styles[$calStyle]['view_calendar']) {
            $resolvedView = CAL_VIEW_LIST;
        }
        $this->chronologyView = $resolvedView;

        $cd = $this->chronologyDate;
        while (count($cd) > 3) {
            array_pop($cd);
        }

        $anyCount = 0;
        for ($i = 0; $i < count($cd); $i++) {
            if ($cd[$i] === 'any') {
                if ($resolvedView === CAL_VIEW_CALENDAR) {
                    while ($i < count($cd)) {
                        array_pop($cd);
                    }
                    break;
                }
                $anyCount++;
            } elseif ($cd[$i] === '' || $cd[$i] === 0) {
                while ($i < count($cd)) {
                    array_pop($cd);
                }
            } elseif (is_string($cd[$i])) {
                $cd[$i] = (int) $cd[$i];
            }
        }
        if ($anyCount === 3) {
            array_pop($cd);
        }
        $this->chronologyDate = $cd;

        $calendar->chronologyField = $chronologyField;
        $calendar->chronologyView  = $resolvedView;
        $calendar->chronologyDate  = $this->chronologyDate;
        $calendar->initialize($innerSql, $innerParams, $innerTypes);

        $mustShowList = true;
        if (StringUtil::scriptBasename() !== 'picture') {
            if ($calendar->generateCategoryContent()) {
                $this->items  = [];
                $mustShowList = false;
            }
            // calendar->generateCategoryContent() may have collapsed single-item levels
            $this->chronologyDate = $calendar->chronologyDate;

            $template->assign('FILE_CHRONOLOGY_VIEW', 'month_calendar.latte');

            foreach ($styles as $style => $styleData) {
                foreach ($views as $view) {
                    if ($styleData['view_calendar'] or $view != CAL_VIEW_CALENDAR) {
                        $selected         = false;
                        $chronologyDateAll = $this->chronologyDate;
                        if ($style !== $calStyle) {
                            $chronologyDate = [];
                            if (isset($chronologyDateAll[0])) {
                                $chronologyDate[] = $chronologyDateAll[0];
                            }
                        } else {
                            $chronologyDate = $chronologyDateAll;
                        }
                        $url = $this->urlService->duplicateIndexUrl([
                            'chronology_style' => $style,
                            'chronology_view'  => $view,
                            'chronology_date'  => $chronologyDate,
                        ]);

                        if ($style === $calStyle && $view === $resolvedView) {
                            $selected = true;
                        }

                        $template->append('chronology_views', [
                            'VALUE'    => $url,
                            'CONTENT'  => Lang::t('chronology_' . $style . '_' . $view),
                            'SELECTED' => $selected,
                        ]);
                    }
                }
            }
            $url           = $this->urlService->duplicateIndexUrl([], ['start', 'chronology_date']);
            $calendarTitle = '<a href="' . $url . '">' . $fields[$chronologyField]['label'] . '</a>';
            $calendarTitle .= $calendar->getDisplayName();
            $template->assign('chronology', ['TITLE' => new Html($calendarTitle)]);
        }

        if ($mustShowList) {
            $chronologyDateList = $this->chronologyDate;
            $configOrderBy = $this->orderByService->buildOrderByClause(Config::orderBy());
            if ($superOrderBy) {
                $orderBy = $configOrderBy;
            } else {
                if (count($chronologyDateList) === 0 || in_array('any', $chronologyDateList, true)) {
                    $order = ' DESC, ';
                } else {
                    $order = ' ASC, ';
                }
                $orderBy = str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $calendar->date_field . $order,
                    $configOrderBy
                );
            }

            $cacheItem = null;
            if (Section::Categories === $section && $category === null
              && (count($chronologyDateList) === 0
                    || ($chronologyDateList[0] === 'any' && count($chronologyDateList) === 1))
            ) {
                $cacheUpdateTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
                $cacheKey        = md5($currentUser->id . $cacheUpdateTime . $calendar->date_field . $orderBy . AppInfo::VERSION);
                $cacheItem       = $this->pool->getItem($cacheKey);
            }

            if ($cacheItem !== null && $cacheItem->isHit()) {
                $cachedItems   = $cacheItem->get();
                $this->items   = is_array($cachedItems) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $cachedItems)) : [];
            } else {
                $ids = $this->calRepo->findImageIdsForCalendar($calendar->inner_sql, $calendar->getDateWhere(), $orderBy, $calendar->inner_params, $calendar->inner_types);
                $this->items = array_map(static fn (int $v): string => (string) $v, $ids);
                if ($cacheItem !== null) {
                    $cacheItem->set($this->items);
                    $cacheItem->expiresAfter(86400);
                    $this->pool->save($cacheItem);
                }
            }
        }
        $this->debugCollector->collect('end initialize_calendar');
    }
}
