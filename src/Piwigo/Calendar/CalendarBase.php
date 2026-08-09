<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permission\SqlCondition;

/**
 * Base class for monthly and weekly calendar styles.
 *
 * Hosts the calendar view/chronology-date constants as class constants;
 * readers outside the hierarchy (CalendarRenderer) reference them as
 * CalendarBase::CAL_VIEW_* / CalendarBase::C*.
 */
abstract class CalendarBase
{
    /**
     * URL keyword for list view
     */
    public const string CAL_VIEW_LIST = 'list';

    /**
     * URL keyword for calendar view
     */
    public const string CAL_VIEW_CALENDAR = 'calendar';

    // Chronology-date array indexes used throughout CalendarMonthly/
    // CalendarWeekly -- CWEEK and CMONTH intentionally share index 1 --
    // CalendarMonthly only ever uses CYEAR/CMONTH/CDAY and CalendarWeekly
    // only ever uses CYEAR/CWEEK/CDAY, never both in the same
    // $chronology_date array.
    /**
     * level of year view
     */
    public const int CYEAR = 0;

    /**
     * level of week view in weekly view
     */
    public const int CWEEK = 1;

    /**
     * level of month view in monthly view
     */
    public const int CMONTH = 1;

    /**
     * level of day view
     */
    public const int CDAY = 2;

    /**
     * db column on which this calendar works -- raw SQL column name, used
     * only by {@see CalendarRepository::findImageIds()}'s own raw-DBAL
     * path (see its own docblock for why it alone stays off DQL).
     *
     * @var string
     */
    public $date_field;

    /**
     * DQL property-path equivalent of $date_field (e.g. `i.dateAvailable`)
     * -- used by every other query in this hierarchy, all of which are
     * real DQL.
     */
    public string $date_field_dql = '';

    /**
     * used for queries (INNER JOIN or normal)
     */
    public CalendarQueryScope $scope;

    /**
     * used to store db fields -- 'sql' is the raw SQL expression (feeds
     * {@see CalendarRepository::findImageIds()}'s own raw-DBAL path via
     * get_date_where()), 'dql' is the DQL property-path equivalent (feeds
     * every other query).
     *
     * @var array<int, array{sql: string, dql: string, labels: array<int|string, string>|null}>
     */
    public $calendar_levels;

    /**
     * chronology field ('posted' or 'created'); set by CalendarRenderer
     * before initialize() is called.
     *
     * @var string
     */
    public $chronology_field = '';

    /**
     * mutable chronology-date state, narrowed in place by
     * generate_category_content() and its build_*_calendar() helpers as
     * the requested period gets resolved down to a single year/month/day.
     *
     * @var array<int, int|string>
     */
    public $chronology_date = [];

    /**
     * chronology view (CAL_VIEW_LIST or CAL_VIEW_CALENDAR); set by
     * CalendarRenderer before generate_category_content() is called.
     *
     * @var string
     */
    public $chronology_view = '';

    /**
     * `month_calendar.tpl`'s own `chronology_navigation_bars` data --
     * formerly shared between build_nav_bar()/build_next_prev() via the
     * live Smarty template variable itself (an append-then-read-modify-write
     * round trip through Template::append()/get_template_vars()/assign()).
     * A private instance property instead: both methods are declared on
     * this same class, so no subclass access is needed; CalendarRenderer
     * (an unrelated class) reads the final result via
     * {@see self::getChronologyNavigationBars()} once every
     * build_nav_bar()/build_next_prev() call for this render has run.
     *
     * @var list<array<string, mixed>>
     */
    private array $chronologyNavigationBars = [];

    public function __construct(
        protected readonly Lang $lang,
        protected readonly CalendarRepository $calendarRepository,
        protected readonly UrlServiceInterface $urlService,
        protected readonly CurrentConfig $currentConfig,
        protected readonly ImageStdParams $imageStdParams,
    ) {}

    /**
     * The `chronology_navigation_bars` rows accumulated by every
     * build_nav_bar()/build_next_prev() call made so far during this
     * render -- read once generate_category_content() returns.
     *
     * @return list<array<string, mixed>>
     */
    public function getChronologyNavigationBars(): array
    {
        return $this->chronologyNavigationBars;
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    // Called via CalendarRenderer.php's CalendarBase-typed $calendar --
    // polymorphic dispatch the tool doesn't trace back to this declaration.
    // @phpstan-ignore shipmonk.deadMethod
    abstract public function generate_category_content(TemplateInterface $template);

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     * @param bool $forDql when true, reads $date_field_dql/calendar_levels[$level]['dql']
     *   instead of $date_field/calendar_levels[$level]['sql'] -- every real caller except
     *   {@see CalendarRenderer::render()}'s own findImageIds() feed passes true, since every
     *   other CalendarRepository method is real DQL (see CalendarRepository's own docblock).
     */
    abstract public function get_date_where($max_levels = 3, bool $forDql = false): SqlCondition;

    /**
     * Initialize the calendar.
     */
    public function initialize(CalendarQueryScope $scope): void
    {
        if ($this->chronology_field === 'posted') {
            $this->date_field = 'date_available';
            $this->date_field_dql = 'i.dateAvailable';
        } else {
            $this->date_field = 'date_creation';
            $this->date_field_dql = 'i.dateCreation';
        }
        $this->scope = $scope;
    }

    /**
     * Returns the calendar title (with HTML).
     *
     * @return string
     */
    public function get_display_name()
    {
        $res = '';

        // level_separator is documented as a character string
        // (see config_default.inc.php); see the identical pattern in
        // include/section_init.inc.php and admin/cat_list.php.
        $level_separator = $this->currentConfig->levelSeparator;
        $page_chronology_date = $this->chronology_date;

        for ($i = 0; $i < count($page_chronology_date); $i++) {
            $res .= $level_separator;
            $date_component = (string) $page_chronology_date[$i];
            if (isset($page_chronology_date[$i + 1])) {
                $chronology_date_slice = array_slice($page_chronology_date, 0, $i + 1);
                $url = $this->urlService->duplicateIndexUrl(
                    [
                        'chronology_date' => $chronology_date_slice,
                    ],
                    ['start']
                );
                $res .=
                  '<a href="' . $url . '">'
                  . $this->get_date_component_label($i, $date_component)
                  . '</a>';
            } else {
                $res .=
                  '<span class="calInHere">'
                  . $this->get_date_component_label($i, $date_component)
                  . '</span>';
            }
        }
        return $res;
    }

    /**
     * Returns a display name for a date component optionally using labels.
     *
     * @return string
     */
    protected function get_date_component_label(int $level, string $date_component)
    {
        $label = $date_component;
        if (isset($this->calendar_levels[$level]['labels'][$date_component])) {
            $label = $this->calendar_levels[$level]['labels'][$date_component];
        } elseif ($date_component === 'any') {
            $label = $this->lang->t('All');
        }
        return $label;
    }

    /**
     * Gets a nice display name for a date to be shown in previous/next links
     *
     * @param string $date
     * @return string
     */
    protected function get_date_nice_name($date)
    {
        $date_components = explode('-', $date);
        $res = '';
        for ($i = count($date_components) - 1; $i >= 0; $i--) {
            if ($date_components[$i] !== 'any') {
                $label = $this->get_date_component_label($i, $date_components[$i]);
                if ($res !== '') {
                    $res .= ' ';
                }
                $res .= $label;
            }
        }
        return $res;
    }

    /**
     * Creates a calendar navigation bar.
     *
     * @param array<int, int|string> $date_components
     * @param array<int|string, int> $items - hash of items to put in the bar (e.g. 2005,2006), -1 sentinel means "no items"
     * @param bool $show_any - adds any link to the end of the bar
     * @param bool $show_empty - shows all labels even those without items
     * @param array<int|string, int|string>|null $labels - optional labels for items (e.g. Jan,Feb,... or day numbers)
     * @return array<int, array<string, mixed>>
     */
    protected function get_nav_bar_from_items(
        array $date_components,
        array $items,
        $show_any,
        $show_empty = false,
        $labels = null
    ) {
        $nav_bar_datas = [];

        if ($this->currentConfig->calendarShowEmpty and $show_empty and $labels !== null and $labels !== []) {
            foreach ($labels as $item => $label) {
                if (! isset($items[$item])) {
                    $items[$item] = -1;
                }
            }
            ksort($items);
        }

        foreach ($items as $item => $nb_images) {
            $label = $item;
            if (isset($labels[$item])) {
                $label = $labels[$item];
            }
            if ($nb_images === -1) {
                $tmp_datas = [
                    'LABEL' => $label,
                ];
            } else {
                $url = $this->urlService->duplicateIndexUrl(
                    [
                        'chronology_date' => array_merge($date_components, [$item]),
                    ],
                    ['start']
                );
                $tmp_datas = [
                    'LABEL' => $label,
                    'URL' => $url,
                ];
            }
            if ($nb_images > 0) {
                $tmp_datas['NB_IMAGES'] = $nb_images;
            }
            $nav_bar_datas[] = $tmp_datas;

        }

        if ($this->currentConfig->calendarShowAny and $show_any and count($items) > 1 and
              count($date_components) < count($this->calendar_levels) - 1) {
            $url = $this->urlService->duplicateIndexUrl(
                [
                    'chronology_date' => array_merge($date_components, ['any']),
                ],
                ['start']
            );
            $nav_bar_datas[] = [
                'LABEL' => $this->lang->t('All'),
                'URL' => $url,
            ];
        }

        return $nav_bar_datas;
    }

    /**
     * Creates a calendar navigation bar for a given level.
     *
     * @param int $level - 0-year, 1-month/week, 2-day
     * @param array<int|string, int|string>|null $labels get_nav_bar_from_items()
     *   only ever passes each label straight through to the template as a
     *   display value -- CalendarMonthly's own day-number labels are ints,
     *   not strings.
     */
    protected function build_nav_bar($level, ?array $labels): void
    {
        $levelDql = $this->calendar_levels[$level]['dql'];
        $scope = $this->scope;
        $dateWhere = $this->get_date_where($level, forDql: true);

        $rows = $this->calendarRepository->countGroupedByLevel($levelDql, $scope, $dateWhere);
        $level_items = [];
        foreach ($rows as $row) {
            $keyRaw = $row['period'] ?? null;
            $key = is_int($keyRaw) || is_string($keyRaw) ? $keyRaw : '';
            // nb_images is a COUNT(...) aggregate; DBAL row values are mixed
            // (native int or numeric string depending on driver), guard
            // either way, same idiom as CalendarMonthly's own build_*_calendar().
            $level_items[$key] = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
        }

        $page_chronology_date = $this->chronology_date;

        if (count($level_items) === 1 and
             count($page_chronology_date) < count($this->calendar_levels) - 1) {
            if (! isset($page_chronology_date[$level])) {
                [$key] = array_keys($level_items);
                $this->chronology_date[$level] = (int) $key;
                $page_chronology_date = $this->chronology_date;

                if ($level < count($page_chronology_date) and
                     $level !== count($this->calendar_levels) - 1) {
                    return;
                }
            }
        }

        $dates = array_values($page_chronology_date);
        while ($level < count($dates)) {
            array_pop($dates);
        }

        $nav_bar = $this->get_nav_bar_from_items(
            $dates,
            $level_items,
            true,
            true,
            $labels ?? $this->calendar_levels[$level]['labels']
        );

        $this->chronologyNavigationBars[] = [
            'items' => $nav_bar,
        ];
    }

    /**
     * Assigns the next/previous link to the template with regards to
     * the currently choosen date.
     */
    protected function build_next_prev(): void
    {
        $prev = $next = null;
        if ($this->chronology_date === []) {
            return;
        }

        $page_chronology_date = $this->chronology_date;

        $sub_queries = [];
        $nb_elements = count($page_chronology_date);
        for ($i = 0; $i < $nb_elements; $i++) {
            if ($page_chronology_date[$i] === 'any') {
                $sub_queries[] = '\'any\'';
            } else {
                $sub_queries[] = $this->calendar_levels[$i]['dql'];
            }
        }
        $scope = $this->scope;

        // $current must mirror the SQL side's own per-element treatment
        // above (every component included, whether 'any' or a real date
        // part) to ever match a real row's 'period' value -- filtering to
        // is_string() only silently dropped every int-typed component,
        // and CalendarRenderer::render() (the only real caller) always
        // sanitizes non-'any', non-empty chronology_date tokens into real
        // ints before setting this property, so $current came back ''
        // (empty string, matching no real period) on every real request.
        // implode() itself already string-casts int elements correctly.
        $current = implode('-', $page_chronology_date);
        $upper_items = $this->calendarRepository->findAdjacentPeriods($sub_queries, $scope, $this->date_field_dql);

        $version_compare_2arg = static fn (string $a, string $b): int => version_compare($a, $b);

        usort($upper_items, $version_compare_2arg);
        $upper_items_rank = array_flip($upper_items);
        if (! isset($upper_items_rank[$current])) {
            $upper_items[] = $current; // just in case (external link)
            usort($upper_items, $version_compare_2arg);
            $upper_items_rank = array_flip($upper_items);
        }
        $current_rank = $upper_items_rank[$current];

        $tpl_var = [];

        if ($current_rank > 0) { // has previous
            $prev = $upper_items[$current_rank - 1] ?? null;
            if ($prev !== null) {
                $chronology_date = explode('-', $prev);
                $tpl_var['previous'] =
                  [
                      'LABEL' => $this->get_date_nice_name($prev),
                      'URL' => $this->urlService->duplicateIndexUrl(
                          [
                              'chronology_date' => $chronology_date,
                          ],
                          ['start']
                      ),
                  ];
            }
        }

        if ($current_rank < count($upper_items) - 1) { // has next
            $next = $upper_items[$current_rank + 1] ?? null;
            if ($next !== null) {
                $chronology_date = explode('-', $next);
                $tpl_var['next'] =
                  [
                      'LABEL' => $this->get_date_nice_name($next),
                      'URL' => $this->urlService->duplicateIndexUrl(
                          [
                              'chronology_date' => $chronology_date,
                          ],
                          ['start']
                      ),
                  ];
            }
        }

        if ($tpl_var !== []) {
            // Patches the *last* row build_nav_bar() accumulated so far
            // this render (real cross-method shared state -- see this
            // property's own docblock), rather than adding a new row.
            if ($this->chronologyNavigationBars !== []) {
                $last_index = count($this->chronologyNavigationBars) - 1;
                $this->chronologyNavigationBars[$last_index] = array_merge($this->chronologyNavigationBars[$last_index], $tpl_var);
            } else {
                $this->chronologyNavigationBars[] = $tpl_var;
            }
        }
    }
}
