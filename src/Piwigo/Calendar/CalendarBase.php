<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Piwigo\Template\Template;

/**
 * Base class for monthly and weekly calendar styles
 *
 * P23 batch 8f-4: the calendar view/chronology-date constants moved here
 * as class constants (formerly top-level define()s in the deleted
 * include/functions.inc.php, relocated there from
 * include/functions_calendar.inc.php in batch 8c). This shared base hosts
 * them; readers outside the hierarchy (CalendarRenderer) reference them
 * as CalendarBase::CAL_VIEW_* / CalendarBase::C*.
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
     * db column on which this calendar works
     *
     * @var string
     */
    public $date_field;

    /**
     * used for queries (INNER JOIN or normal)
     *
     * @var string
     */
    public $inner_sql;

    /**
     * used to store db fields
     *
     * @var array<int, array{sql: string, labels: array<int|string, string>|null}>
     */
    public $calendar_levels;

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    abstract public function generate_category_content();

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     * @return string
     */
    abstract public function get_date_where($max_levels = 3);

    /**
     * Initialize the calendar.
     *
     * @param string $inner_sql
     */
    public function initialize($inner_sql): void
    {
        /** @var array<string, mixed> $page */
        global $page;
        if ($page['chronology_field'] == 'posted') {
            $this->date_field = 'date_available';
        } else {
            $this->date_field = 'date_creation';
        }
        $this->inner_sql = $inner_sql;
    }

    /**
     * Returns the calendar title (with HTML).
     *
     * @return string
     */
    public function get_display_name()
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         */
        global $conf, $page;
        $res = '';

        // level_separator is documented as a character string
        // (see config_default.inc.php); see the identical pattern in
        // include/section_init.inc.php and admin/cat_list.php.
        $level_separator = is_string($conf['level_separator']) ? $conf['level_separator'] : ' / ';
        // chronology_date is always an array by the time calendar classes
        // run (see CalendarRenderer::render(),
        // which sanitizes it before this is ever called); see the identical
        // pattern in calendar_monthly.class.php.
        $page_chronology_date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];

        for ($i = 0; $i < count($page_chronology_date); $i++) {
            $res .= $level_separator;
            $date_component = $page_chronology_date[$i];
            $date_component = is_int($date_component) || is_string($date_component) ? (string) $date_component : '';
            if (isset($page_chronology_date[$i + 1])) {
                $chronology_date_slice = array_slice($page_chronology_date, 0, $i + 1);
                $url = duplicate_index_url(
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
            $label = l10n('All');
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
                if ($res != '') {
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
     * @param array<int|string, mixed> $items - hash of items to put in the bar (e.g. 2005,2006)
     * @param bool $show_any - adds any link to the end of the bar
     * @param bool $show_empty - shows all labels even those without items
     * @param array<int|string, mixed>|null $labels - optional labels for items (e.g. Jan,Feb,...)
     * @return array<int, array<string, mixed>>
     */
    protected function get_nav_bar_from_items(
        array $date_components,
        array $items,
        $show_any,
        $show_empty = false,
        $labels = null
    ) {
        /** @var array<string, mixed> $conf */
        global $conf, $page, $template;

        $nav_bar_datas = [];

        if ((bool) $conf['calendar_show_empty'] and $show_empty and ! empty($labels)) {
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
            if ($nb_images == -1) {
                $tmp_datas = [
                    'LABEL' => $label,
                ];
            } else {
                $url = duplicate_index_url(
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

        if ((bool) $conf['calendar_show_any'] and $show_any and count($items) > 1 and
              count($date_components) < count($this->calendar_levels) - 1) {
            $url = duplicate_index_url(
                [
                    'chronology_date' => array_merge($date_components, ['any']),
                ],
                ['start']
            );
            $nav_bar_datas[] = [
                'LABEL' => l10n('All'),
                'URL' => $url,
            ];
        }

        return $nav_bar_datas;
    }

    /**
     * Creates a calendar navigation bar for a given level.
     *
     * @param int $level - 0-year, 1-month/week, 2-day
     * @param array<int|string, mixed>|null $labels
     */
    protected function build_nav_bar($level, ?array $labels = null): void
    {
        /**
         * @var Template $template
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         */
        global $template, $conf, $page;

        $query = '
SELECT DISTINCT(' . $this->calendar_levels[$level]['sql'] . ') as period,
  COUNT(DISTINCT id) as nb_images' .
$this->inner_sql .
$this->get_date_where($level) . '
  GROUP BY period;';

        $level_items = \Piwigo\Db\MysqliDb::query2Array($query, 'period', 'nb_images');

        // chronology_date is always an array by the time calendar classes
        // run (see CalendarRenderer::render(),
        // which sanitizes it before initialize()/generate_category_content()
        // are ever called); see the identical pattern in
        // calendar_monthly.class.php.
        $page_chronology_date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];

        if (count($level_items) == 1 and
             count($page_chronology_date) < count($this->calendar_levels) - 1) {
            if (! isset($page_chronology_date[$level])) {
                [$key] = array_keys($level_items);
                assert(is_array($page['chronology_date']));
                $page['chronology_date'][$level] = (int) $key;
                $page_chronology_date = $page['chronology_date'];

                if ($level < count($page_chronology_date) and
                     $level != count($this->calendar_levels) - 1) {
                    return;
                }
            }
        }

        $dates = [];
        foreach ($page_chronology_date as $date_part) {
            if (is_int($date_part) || is_string($date_part)) {
                $dates[] = $date_part;
            }
        }
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

        $template->append(
            'chronology_navigation_bars',
            [
                'items' => $nav_bar,
            ]
        );
    }

    /**
     * Assigns the next/previous link to the template with regards to
     * the currently choosen date.
     */
    protected function build_next_prev(): void
    {
        /**
         * @var Template $template
         * @var array<string, mixed> $page
         */
        global $template, $page;

        $prev = $next = null;
        if (empty($page['chronology_date'])) {
            return;
        }

        // chronology_date is always an array by the time calendar classes
        // run (see CalendarRenderer::render(),
        // which sanitizes it before this is ever called); see the identical
        // pattern in calendar_monthly.class.php.
        $page_chronology_date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];

        $sub_queries = [];
        $nb_elements = count($page_chronology_date);
        for ($i = 0; $i < $nb_elements; $i++) {
            if ($page_chronology_date[$i] === 'any') {
                $sub_queries[] = '\'any\'';
            } else {
                $sub_queries[] = \Piwigo\Db\MysqliDb::castToText($this->calendar_levels[$i]['sql']);
            }
        }
        $query = 'SELECT ' . \Piwigo\Db\MysqliDb::concatWs($sub_queries, '-') . ' AS period';
        $query .= $this->inner_sql . '
AND ' . $this->date_field . ' IS NOT NULL
GROUP BY period';

        $current = implode('-', array_filter($page_chronology_date, is_string(...)));
        // period is a concatenation of non-null date parts (enforced by the
        // "date_field IS NOT NULL" clause above), but \Piwigo\Db\MysqliDb::query2Array()'s generic
        // signature still types each element as string|null, so filter for real.
        $upper_items = array_values(array_filter(\Piwigo\Db\MysqliDb::query2Array($query, null, 'period'), is_string(...)));

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
            $prev = $upper_items[$current_rank - 1];
            $chronology_date = explode('-', $prev);
            $tpl_var['previous'] =
              [
                  'LABEL' => $this->get_date_nice_name($prev),
                  'URL' => duplicate_index_url(
                      [
                          'chronology_date' => $chronology_date,
                      ],
                      ['start']
                  ),
              ];
        }

        if ($current_rank < count($upper_items) - 1) { // has next
            $next = $upper_items[$current_rank + 1];
            $chronology_date = explode('-', $next);
            $tpl_var['next'] =
              [
                  'LABEL' => $this->get_date_nice_name($next),
                  'URL' => duplicate_index_url(
                      [
                          'chronology_date' => $chronology_date,
                      ],
                      ['start']
                  ),
              ];
        }

        if (! empty($tpl_var)) {
            // Smarty::getTemplateVars() is declared to return mixed (see
            // vendor/smarty/smarty/src/Data.php); every write to
            // 'chronology_navigation_bars' (build_nav_bar() above, and the
            // append() below) stores a list of arrays, so narrow with
            // is_array() rather than trusting the vendor signature.
            $existing = $template->smarty->getTemplateVars('chronology_navigation_bars');
            if (is_array($existing) && ! empty($existing)) {
                $last_index = count($existing) - 1;
                $last_item = $existing[$last_index] ?? null;
                $last_item = is_array($last_item) ? $last_item : [];
                $existing[$last_index] = array_merge($last_item, $tpl_var);
                $template->assign('chronology_navigation_bars', $existing);
            } else {
                $template->append('chronology_navigation_bars', $tpl_var);
            }
        }
    }
}
