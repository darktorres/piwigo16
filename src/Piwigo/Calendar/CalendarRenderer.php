<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CalendarNavCachePool;
use Piwigo\Calendar\Projection\CalendarChronologyPageContext;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SortRenderer;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Psr\Cache\CacheItemInterface;

/**
 * The `$page`/`$template`-coupled half of category-page calendar
 * rendering: chronology style/view resolution, calendar object
 * construction, and the view-switcher UI links. Single real caller:
 * `SectionPopulator::populate()`.
 *
 * render() takes explicit inputs and returns a {@see CalendarRenderResult}
 * rather than reading/writing `global $page;` directly. SectionPopulator
 * calls this from inside its own section-context-building method, before
 * any SectionContext exists to read via SectionContextRegistry, so this
 * class cannot rely on that registry. CalendarBase/CalendarMonthly/
 * CalendarWeekly's `chronology_field`/`chronology_date`/`chronology_view`
 * are plain mutable instance properties on the calendar object, set by
 * this class before calling into them.
 */
final readonly class CalendarRenderer
{
    public function __construct(
        private Lang $lang,
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
        private UrlServiceInterface $urlService,
        private CurrentUser $currentUser,
        private readonly CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private Translator $translator,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
        private CalendarNavCachePool $calendarNavCachePool,
    ) {}

    /**
     * @param array<string, mixed>|null $category current category row
     * @param list<int|string> $items current section item ids (only used
     *   when $section !== 'categories'; the categories branch always
     *   recomputes its own item list)
     * @param list<int|string> $chronologyDate raw, unsanitized chronology
     *   date tokens as parsed from the URL
     */
    public function render(
        Section $section,
        ?array $category,
        array $items,
        string $chronologyField,
        ?string $chronologyStyle,
        ?string $chronologyView,
        array $chronologyDate,
        bool $superOrderBy,
    ): CalendarRenderResult {
        $template = $this->template;

        $accessLevelChecker = new AccessLevelChecker($this->currentUser, $this->currentConfig);
        $calendarService = new CalendarService(
            $this->permissionService,
            new CategoryService($this->lang, new CategoryRepository($this->entityManager, $this->currentConfig), $this->permissionService, $this->currentConfig, $this->eventDispatcher, $this->translator, $accessLevelChecker, new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig))
        );

        if ($section === Section::Categories) { // we will regenerate the items by including subcats elements
            $items = [];

            $category_id = isset($category['id'])
                && (is_int($category['id']) || is_string($category['id']))
                ? $category['id']
                : null;
            $forbidden_categories = $this->currentUser->get()
                ->forbiddenCategories;

            $scope = $calendarService->buildInnerSql('categories', $category !== null, $category_id, $forbidden_categories, []);

            if (! $scope instanceof CalendarQueryScope) {
                return new CalendarRenderResult($items, '', $chronologyDate, $chronologyStyle, $chronologyView); // nothing to do
            }
        } else {
            $scope = $calendarService->buildInnerSql('items', false, null, '', $items);

            if (! $scope instanceof CalendarQueryScope) {
                return new CalendarRenderResult($items, '', $chronologyDate, $chronologyStyle, $chronologyView); // nothing to do
            }
        }

        TimingHelper::debug('start initialize_calendar', $this->pageState);

        $fields = [
            // Created
            'created' => [
                'label' => $this->lang->t('Creation date'),
            ],
            // Posted
            'posted' => [
                'label' => $this->lang->t('Post date'),
            ],
        ];

        $styles = [
            // Monthly style
            'monthly' => [
                'view_calendar' => true,
                'classname' => CalendarMonthly::class,
            ],
            // Weekly style
            'weekly' => [
                'view_calendar' => false,
                'classname' => CalendarWeekly::class,
            ],
        ];

        $views = [CalendarBase::CAL_VIEW_LIST, CalendarBase::CAL_VIEW_CALENDAR];

        // Retrieve calendar field
        isset($fields[$chronologyField]) or $this->htmlRenderer->fatalError('bad chronology field');

        // Retrieve style
        $chronology_style = $chronologyStyle;
        if ($chronology_style === null || ! isset($styles[$chronology_style])) {
            $chronology_style = 'monthly';
        }
        $cal_style = $chronology_style;
        $classname = $styles[$cal_style]['classname'];

        $calendar = new $classname($this->lang, new CalendarRepository($this->entityManager), $this->urlService, $this->currentConfig, $this->imageStdParams);
        $calendar->chronology_field = $chronologyField;

        // Retrieve view
        $chronology_view = $chronologyView;

        if ($chronology_view === null or
             ! in_array($chronology_view, $views, true)) {
            $chronology_view = CalendarBase::CAL_VIEW_LIST;
        }

        if ($chronology_view === CalendarBase::CAL_VIEW_CALENDAR and
              ! $styles[$cal_style]['view_calendar']) {

            $chronology_view = CalendarBase::CAL_VIEW_LIST;
        }

        $calendar->chronology_view = $chronology_view;

        // perform a sanity check on $requested
        $page_chronology_date = $chronologyDate;
        while (count($page_chronology_date) > 3) {
            array_pop($page_chronology_date);
        }

        $any_count = 0;
        for ($i = 0; $i < count($page_chronology_date); $i++) {
            if ($page_chronology_date[$i] === 'any') {
                if ($chronology_view === CalendarBase::CAL_VIEW_CALENDAR) {// we dont allow any in calendar view
                    while ($i < count($page_chronology_date)) {
                        array_pop($page_chronology_date);
                    }
                    break;
                }
                $any_count++;
            } elseif ($page_chronology_date[$i] === '') {
                while ($i < count($page_chronology_date)) {
                    array_pop($page_chronology_date);
                }
            } else {
                $chronology_date_part = $page_chronology_date[$i];
                $page_chronology_date[$i] = is_numeric($chronology_date_part) ? (int) $chronology_date_part : 0;
            }
        }
        if ($any_count === 3) {
            array_pop($page_chronology_date);
        }

        $calendar->chronology_date = $page_chronology_date;

        $calendar->initialize($scope);

        // echo ('<pre>'. var_export($calendar, true) . '</pre>');

        $comment = '';
        $must_show_list = true; // true until calendar generates its own display
        if (PageFilterHelper::scriptBasename($this->currentConfig) !== 'picture') { // basename without file extention
            if ($calendar->generateCategoryContent($template)) {
                $items = [];
                $must_show_list = false;
            }

            $comment = '';

            $chronology_views = null;
            foreach ($styles as $style => $style_data) {
                foreach ($views as $view) {
                    if ($style_data['view_calendar'] or $view !== CalendarBase::CAL_VIEW_CALENDAR) {
                        $selected = false;

                        if ($style !== $cal_style) {
                            $chronology_date = [];
                            if (isset($page_chronology_date[0])) {
                                $chronology_date[] = $page_chronology_date[0];
                            }
                        } else {
                            $chronology_date = $page_chronology_date;
                        }
                        $url = $this->urlService->duplicateIndexUrl(
                            [
                                'chronology_style' => $style,
                                'chronology_view' => $view,
                                'chronology_date' => $chronology_date,
                            ]
                        );

                        if ($style === $cal_style and $view === $chronology_view) {
                            $selected = true;
                        }

                        $chronology_views ??= [];
                        $chronology_views[] = [
                            'VALUE' => $url,
                            'CONTENT' => $this->lang->t('chronology_' . $style . '_' . $view),
                            'SELECTED' => $selected,
                        ];
                    }
                }
            }
            $url = $this->urlService->duplicateIndexUrl(
                [],
                ['start', 'chronology_date']
            );
            $calendar_title = '<a href="' . $url . '">'
                . $fields[$chronologyField]['label'] . '</a>';
            $calendar_title .= $calendar->getDisplayName();
            $template->assignContext(new CalendarChronologyPageContext(
                fileChronologyView: 'month_calendar.latte',
                chronologyTitle: $calendar_title,
                chronologyNavigationBars: $calendar->getChronologyNavigationBars(),
                chronologyViews: $chronology_views,
            ));
        } // end category calling

        if ($must_show_list) {
            $conf_order_by = new SortRenderer($this->entityManager->getConnection())
                ->toSql($this->currentConfig->orderBy);

            if ($superOrderBy) {
                $order_by = $conf_order_by;
            } else {
                if (count($page_chronology_date) === 0
                     or in_array('any', $page_chronology_date, true)) {// selected period is very big so we show newest first
                    $order = ' DESC, ';
                } else {// selected period is small (month,week) so we show oldest first
                    $order = ' ASC, ';
                }
                $order_by = str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $calendar->date_field . $order,
                    $conf_order_by
                );
            }

            if ($section === Section::Categories && $category === null
              && (count($page_chronology_date) === 0
                    or ($page_chronology_date[0] === 'any' && count($page_chronology_date) === 1))
            ) {
                $user = $this->currentUser->get();
                $cache_item = $this->calendarNavCachePool
                    ->getItem('nav_' . $user->id->value . '_' . md5($calendar->date_field . $order_by));
            }

            $cache_item ??= null;
            $cached_items = $cache_item?->isHit() === true ? $cache_item->get() : null;

            if (is_array($cached_items)) {
                /** @var list<int> $cached_items */
                $items = $cached_items;
            } else {
                // findImageIds()'s raw-DBAL path still wants one combined
                // FROM+WHERE fragment (see its own docblock) -- CalendarQueryScope
                // itself carries the FROM/JOIN text and the WHERE condition
                // as two separate fields (see that class's own docblock for
                // why), so this recombines them the same way
                // CalendarService::buildInnerSql() used to before returning.
                $rawSqlWhereClause = $calendar->scope->rawSqlWhere->toWhereClause();
                $fromWhere = SqlCondition::fromRawSql(
                    $calendar->scope->rawSqlFrom . ($rawSqlWhereClause === '' ? '' : "\n    " . $rawSqlWhereClause),
                    $calendar->scope->rawSqlWhere->parameters,
                    $calendar->scope->rawSqlWhere->types,
                );

                $items = new CalendarRepository($this->entityManager)
                    ->findImageIds(
                        $fromWhere,
                        $calendar->getDateWhere(),
                        $order_by,
                        $calendar->scope,
                        $calendar->getDateWhere(forDql: true)
                    );
                if ($cache_item instanceof CacheItemInterface) {
                    $cache_item->set($items);
                    $this->calendarNavCachePool->save($cache_item);
                }
            }
        }
        TimingHelper::debug('end initialize_calendar', $this->pageState);

        return new CalendarRenderResult($items, $comment, $page_chronology_date, $chronology_style, $chronology_view);
    }
}
