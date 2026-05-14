<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Users\PermissionService;

final readonly class CalendarService
{
    public function __construct(
        private CategoryService $categoryService,
        private Connection $conn,
        private Util $util,
        private PermissionService $permissionService,
        private UrlService $urlService,
    ) {
    }
    public function initializeCalendar(): void
    {
        $filter          = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
        $persistentCache = PersistentCacheRegistry::current();
        $template        = TemplateRegistry::current();
        $currentUser     = CurrentUser::get();
        $user            = $currentUser->rawAttributes;
        $page            = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $ctx = SectionContextRegistry::current();

        $innerSql = ' FROM ' . Tables::images();

        if ($ctx->section == 'categories') {
            $page['items'] = [];
            $innerSql .= '
INNER JOIN ' . Tables::imageCategory() . ' ON id = image_id';

            if ($ctx->category !== null) {
                $categoryIdRaw = $ctx->category['id'] ?? null;
                $subIds = array_diff(
                    $this->categoryService->getSubcatIds([is_numeric($categoryIdRaw) ? (int) $categoryIdRaw : 0]),
                    explode(',', is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : '')
                );

                if (empty($subIds)) {
                    return;
                }
                $innerSql .= '
WHERE category_id IN (' . implode(',', $subIds) . ')';
                $innerSql .= '
    ' . $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], 'AND', false);
            } else {
                $innerSql .= '
    ' . $this->permissionService->getSqlConditionFandF(
                    ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
                    'WHERE',
                    true
                );
            }
        } else {
            /** @psalm-var mixed $pageItems */
            $pageItems = $page['items'] ?? null;
            if (!is_array($pageItems) || empty($pageItems)) {
                return;
            }
            $items = [];
            foreach ($pageItems as $item) {
                if (is_int($item) || is_string($item)) {
                    $items[] = (string) (int) $item;
                }
            }
            $innerSql .= '
WHERE id IN (' . implode(',', $items) . ')';
        }

        $this->util->pwgDebug('start initialize_calendar');

        $fields = [
            'created' => ['label' => Lang::t('Creation date')],
            'posted'  => ['label' => Lang::t('Post date')],
        ];

        $styles = [
            'monthly' => ['view_calendar' => true,  'classname' => CalendarMonthly::class],
            'weekly'  => ['view_calendar' => false, 'classname' => CalendarWeekly::class],
        ];

        $views = [CAL_VIEW_LIST, CAL_VIEW_CALENDAR];

        $chronologyField = $ctx->chronologyField;
        isset($fields[$chronologyField]) or HtmlService::fatalError('bad chronology field');

        $chronologyStyleRaw = $page['chronology_style'] ?? null;
        $chronologyStyle = is_scalar($chronologyStyleRaw) ? (string) $chronologyStyleRaw : '';
        if (!isset($styles[$chronologyStyle])) {
            $page['chronology_style'] = 'monthly';
        }
        $calStyleRaw = $page['chronology_style'] ?? null;
        $calStyle = is_scalar($calStyleRaw) ? (string) $calStyleRaw : 'monthly';
        $calendar = match ($calStyle) {
            'monthly' => new CalendarMonthly(),
            default   => new CalendarWeekly(),
        };

        if (!isset($page['chronology_view']) or !in_array($page['chronology_view'], $views)) {
            $page['chronology_view'] = CAL_VIEW_LIST;
        }

        $styleEntry = $styles[$calStyle] ?? null;
        /** @var string $chronologyView */
        $chronologyView = $page['chronology_view'];
        if (CAL_VIEW_CALENDAR == $chronologyView and
              is_array($styleEntry) and
              !$styleEntry['view_calendar']) {
            $page['chronology_view'] = CAL_VIEW_LIST;
        }

        if (!isset($page['chronology_date']) || !is_array($page['chronology_date'])) {
            $page['chronology_date'] = [];
        }
        while (count($page['chronology_date']) > 3) {
            array_pop($page['chronology_date']);
        }

        $anyCount = 0;
        /** @var string $currentChronologyView */
        $currentChronologyView = $page['chronology_view'];
        for ($i = 0; $i < count($page['chronology_date']); $i++) {
            if ($page['chronology_date'][$i] == 'any') {
                if ($currentChronologyView == CAL_VIEW_CALENDAR) {
                    while ($i < count($page['chronology_date'])) {
                        array_pop($page['chronology_date']);
                    }
                    break;
                }
                $anyCount++;
            } elseif ($page['chronology_date'][$i] == '') {
                while ($i < count($page['chronology_date'])) {
                    array_pop($page['chronology_date']);
                }
            } else {
                $rawDate = $page['chronology_date'][$i];
                $page['chronology_date'][$i] = is_scalar($rawDate) ? (int) $rawDate : 0;
            }
        }
        if ($anyCount == 3) {
            array_pop($page['chronology_date']);
        }

        $calendar->initialize($innerSql);

        $mustShowList = true;
        if (StringUtil::scriptBasename() != 'picture') {
            if ($calendar->generateCategoryContent()) {
                $page['items']  = [];
                $mustShowList   = false;
            }

            $page['comment'] = '';
            $template->assign('FILE_CHRONOLOGY_VIEW', 'month_calendar.latte');

            foreach ($styles as $style => $styleData) {
                foreach ($views as $view) {
                    if ($styleData['view_calendar'] or $view != CAL_VIEW_CALENDAR) {
                        $selected         = false;
                        $chronologyDateAll = $page['chronology_date'];
                        if ($style != $calStyle) {
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

                        if ($style == $calStyle and $view == $page['chronology_view']) {
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
            $chronologyDateList = $page['chronology_date'];
            if ($ctx->superOrderBy) {
                $orderBy = Config::orderBy();
            } else {
                if (count($chronologyDateList) == 0 or in_array('any', $chronologyDateList)) {
                    $order = ' DESC, ';
                } else {
                    $order = ' ASC, ';
                }
                $orderBy = str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $calendar->date_field . $order,
                    Config::orderBy()
                );
            }

            if ('categories' == $ctx->section && $ctx->category === null
              && (count($chronologyDateList) == 0
                    or ($chronologyDateList[0] == 'any' && count($chronologyDateList) == 1))
            ) {
                $cacheUpdateTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
                $cacheKey        = $persistentCache->makeKey($currentUser->id . $cacheUpdateTime . $calendar->date_field . $orderBy);
            }

            if (!isset($cacheKey) || !$persistentCache->get($cacheKey, $page['items'])) {
                $query = 'SELECT DISTINCT id '
                  . $calendar->inner_sql . '
  ' . $calendar->getDateWhere() . '
  ' . $orderBy;
                $page['items'] = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
                if (isset($cacheKey)) {
                    $persistentCache->set($cacheKey, $page['items']);
                }
            }
        }
        $this->util->pwgDebug('end initialize_calendar');
    }
}
