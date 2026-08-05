<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Piwigo\Category\CategoryService;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * P23 batch 8c: ported from `initialize_calendar()`
 * (`include/functions_calendar.inc.php`, deleted) -- the `$page`/
 * `$template`-coupled half of that function (chronology style/view
 * resolution, calendar object construction, the view-switcher UI links)
 * that {@see CalendarService}'s own docblock had already documented as
 * staying outside the DB-query-builder split. Single real caller:
 * `SectionPopulator::populate()`.
 *
 * Legacy Coupling Retirement Track A batch A5.2e: render() took explicit
 * inputs and returns a {@see CalendarRenderResult} instead of reading/
 * writing `global $page;` directly -- SectionPopulator calls this from
 * *inside* its own section-context-building method, before any
 * SectionContext exists yet to read via SectionContextRegistry (an
 * "in-flight collaborator", not a downstream reader, per the batch's own
 * adversarial-validation findings). CalendarBase/CalendarMonthly/
 * CalendarWeekly got the same treatment (their own `global $page;` reads/
 * writes of `chronology_field`/`chronology_date`/`chronology_view` became
 * plain mutable instance properties on the calendar object, set by this
 * class before calling into them) since they're called synchronously from
 * within this method's own render chain and shared the identical coupling.
 */
final readonly class CalendarRenderer
{
    public function __construct(
        private Lang $lang,
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
        private UrlServiceInterface $urlService,
        private \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
        private \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private \Piwigo\Lang\Translator $translator,
        private \Piwigo\Core\FilterState $filterState,
        private \Piwigo\Image\ImageStdParams $imageStdParams,
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
        string $section,
        ?array $category,
        array $items,
        string $chronologyField,
        ?string $chronologyStyle,
        ?string $chronologyView,
        array $chronologyDate,
        bool $superOrderBy,
    ): CalendarRenderResult {
        $template = $this->template;

        // ------------------ initialize the condition on items to take into account ---
        $conn = DbConnection::build();
        $permissionService = new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class), new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build($conn), $this->currentConfig), $this->currentUser, $this->filterState);
        $calendarService = new CalendarService(
            $permissionService,
            new CategoryService($this->lang, new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build($conn), $this->currentConfig), $permissionService, $this->currentConfig, $this->eventDispatcher, $this->translator)
        );

        if ($section === 'categories') { // we will regenerate the items by including subcats elements
            $items = [];

            $category_id = isset($category['id'])
                && (is_int($category['id']) || is_string($category['id']))
                ? $category['id']
                : null;
            $forbidden_categories = $this->currentUser->get()
                ->forbiddenCategories;

            $scope = $calendarService->buildInnerSql('categories', $category !== null, $category_id, $forbidden_categories, []);

            if ($scope === null) {
                return new CalendarRenderResult($items, '', $chronologyDate, $chronologyStyle, $chronologyView); // nothing to do
            }
        } else {
            $scope = $calendarService->buildInnerSql('items', false, null, '', $items);

            if ($scope === null) {
                return new CalendarRenderResult($items, '', $chronologyDate, $chronologyStyle, $chronologyView); // nothing to do
            }
        }

        // -------------------------------------- initialize the calendar parameters ---
        \Piwigo\Core\TimingHelper::debug('start initialize_calendar');

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

        $calendar = new $classname($this->lang, new CalendarRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), $this->urlService, $this->currentConfig, $this->imageStdParams);
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
        if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'picture') { // basename without file extention
            if ($calendar->generate_category_content($template)) {
                $items = [];
                $must_show_list = false;
            }

            $comment = '';
            $template->assign('FILE_CHRONOLOGY_VIEW', 'month_calendar.tpl');

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

                        $template->append(
                            'chronology_views',
                            [
                                'VALUE' => $url,
                                'CONTENT' => $this->lang->t('chronology_' . $style . '_' . $view),
                                'SELECTED' => $selected,
                            ]
                        );
                    }
                }
            }
            $url = $this->urlService->duplicateIndexUrl(
                [],
                ['start', 'chronology_date']
            );
            $calendar_title = '<a href="' . $url . '">'
                . $fields[$chronologyField]['label'] . '</a>';
            $calendar_title .= $calendar->get_display_name();
            $template->assign(
                'chronology',
                [
                    'TITLE' => $calendar_title,
                ]
            );
        } // end category calling

        if ($must_show_list) {
            $conf_order_by = $this->currentConfig->orderBy();

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

            if ($section === 'categories' && $category === null
              && (count($page_chronology_date) === 0
                    or ($page_chronology_date[0] === 'any' && count($page_chronology_date) === 1))
            ) {
                $user = $this->currentUser->get();
                $cache_item = \Piwigo\Cache\CachePools::calendarNav()
                    ->getItem('nav_' . $user->id->value . '_' . md5($calendar->date_field . $order_by));
            }

            $cache_item ??= null;
            $cached_items = $cache_item?->isHit() === true ? $cache_item->get() : null;

            if (is_array($cached_items)) {
                /** @var list<int> $cached_items */
                $items = $cached_items;
            } else {
                $items = new CalendarRepository(\Piwigo\Db\EntityManagerFactory::build($conn))
                    ->findImageIds(
                        $calendar->scope->rawSqlFromWhere,
                        $calendar->get_date_where(),
                        $order_by,
                        $calendar->scope,
                        $calendar->get_date_where(forDql: true)
                    );
                if ($cache_item instanceof \Psr\Cache\CacheItemInterface) {
                    $cache_item->set($items);
                    \Piwigo\Cache\CachePools::calendarNav()->save($cache_item);
                }
            }
        }
        \Piwigo\Core\TimingHelper::debug('end initialize_calendar');

        return new CalendarRenderResult($items, $comment, $page_chronology_date, $chronology_style, $chronology_view);
    }
}
