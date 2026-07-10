<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once PHPWG_ROOT_PATH . 'include/calendar_base.class.php';

/**
 * Monthly calendar style (composed of years/months and days)
 */
class CalendarMonthly extends CalendarBase
{
    /**
     * Initialize the calendar.
     * @param string $inner_sql
     */
    #[\Override]
    public function initialize($inner_sql): void
    {
        parent::initialize($inner_sql);
        /** @var array<string, mixed> $lang */
        global $lang;
        // $lang['month'] is a labels array (numeric month => translated name)
        // defined by every language file as array<int, string>; filter to
        // string values to match CalendarBase::$calendar_levels' declared
        // element shape (array<int|string, string>|null) instead of a bare
        // mixed cast.
        $month_labels = is_array($lang['month']) ? array_filter($lang['month'], is_string(...)) : null;
        $this->calendar_levels = [
            [
                'sql' => pwg_db_get_year($this->date_field),
                'labels' => null,
            ],
            [
                'sql' => pwg_db_get_month($this->date_field),
                'labels' => $month_labels,
            ],
            [
                'sql' => pwg_db_get_dayofmonth($this->date_field),
                'labels' => null,
            ],
        ];
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    public function generate_category_content(): bool
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         */
        global $conf, $page;

        $view_type = $page['chronology_view'];
        if ($view_type == CAL_VIEW_CALENDAR) {
            /** @var \Template $template */
            global $template;
            $tpl_var = [];
            $nb_date_parts = is_array($page['chronology_date']) ? count($page['chronology_date']) : 0;
            if ($nb_date_parts == 0) {// case A: no year given - display all years+months
                if ($this->build_global_calendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                    return true;
                }
            }

            // build_global_calendar() may have just narrowed the current
            // selection down to a single year (see its own doc comment), so
            // chronology_date must be re-read from $page, not cached above.
            $nb_date_parts = is_array($page['chronology_date']) ? count($page['chronology_date']) : 0;
            if ($nb_date_parts == 1) {// case B: year given - display all days in given year
                if ($this->build_year_calendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                    $this->build_nav_bar(CYEAR); // years
                    return true;
                }
            }

            // same reasoning: build_year_calendar() may have narrowed down
            // to a single month.
            $nb_date_parts = is_array($page['chronology_date']) ? count($page['chronology_date']) : 0;
            if ($nb_date_parts == 2) {// case C: year+month given - display a nice month calendar
                if ($this->build_month_calendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                }
                $this->build_next_prev();
                return true;
            }
        }

        $nb_date_parts = is_array($page['chronology_date']) ? count($page['chronology_date']) : 0;
        if ($view_type == CAL_VIEW_LIST or $nb_date_parts == 3) {
            if ($nb_date_parts == 0) {
                $this->build_nav_bar(CYEAR); // years
            }
            if ($nb_date_parts == 1) {
                $this->build_nav_bar(CMONTH); // month
            }
            if ($nb_date_parts == 2) {
                // $nb_date_parts can only be 2 if $page['chronology_date'] is
                // already an array (see its computation above).
                $chronology_date = $page['chronology_date'];
                $year = $chronology_date[CYEAR] ?? null;
                $year = is_int($year) || is_string($year) ? $year : 0;
                $month = $chronology_date[CMONTH] ?? null;
                $month = is_int($month) || is_string($month) ? $month : 0;
                $day_labels = range(1, $this->get_all_days_in_month($year, $month));
                array_unshift($day_labels, 0);
                unset($day_labels[0]);
                $this->build_nav_bar(CDAY, $day_labels); // days
            }
            $this->build_next_prev();
        }
        return false;
    }

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     */
    public function get_date_where($max_levels = 3): string
    {
        /** @var array<string, mixed> $page */
        global $page;

        // chronology_date is always an array: set to [] by
        // functions_calendar.inc.php::init_calendar_chronology() and to a
        // list of int|string tokens by the URL router (functions_url.inc.php)
        // or feed.php/picture.php (see get_all_days_in_month()'s own doc
        // comment for the same invariant already documented in this class).
        $date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];
        while (count($date) > $max_levels) {
            array_pop($date);
        }
        $res = '';
        if (isset($date[CYEAR]) and $date[CYEAR] !== 'any') {
            $year = $date[CYEAR];
            $year = is_int($year) || is_string($year) ? $year : '';
            $b = $year . '-';
            $e = $year . '-';
            if (isset($date[CMONTH]) and $date[CMONTH] !== 'any') {
                $month = $date[CMONTH];
                $month = is_numeric($month) ? (int) $month : 0;
                $b .= sprintf('%02d-', $month);
                $e .= sprintf('%02d-', $month);
                if (isset($date[CDAY]) and $date[CDAY] !== 'any') {
                    $day = $date[CDAY];
                    $day = is_numeric($day) ? (int) $day : 0;
                    $b .= sprintf('%02d', $day);
                    $e .= sprintf('%02d', $day);
                } else {
                    $b .= '01';
                    $e .= $this->get_all_days_in_month($year, $month);
                }
            } else {
                $b .= '01-01';
                $e .= '12-31';
                // No CMONTH re-check here: this is the else of the exact
                // same isset($date[CMONTH]) and $date[CMONTH] !== 'any'
                // condition above, so it can never be true.
                if (isset($date[CDAY]) and $date[CDAY] !== 'any') {
                    $day = $date[CDAY];
                    $day = is_int($day) || is_string($day) ? $day : '';
                    $res .= ' AND ' . $this->calendar_levels[CDAY]['sql'] . '=' . $day;
                }
            }
            $res = " AND {$this->date_field} BETWEEN '{$b}' AND '{$e} 23:59:59'" . $res;
        } else {
            $res = ' AND ' . $this->date_field . ' IS NOT NULL';
            if (isset($date[CMONTH]) and $date[CMONTH] !== 'any') {
                $month = $date[CMONTH];
                $month = is_int($month) || is_string($month) ? $month : '';
                $res .= ' AND ' . $this->calendar_levels[CMONTH]['sql'] . '=' . $month;
            }
            if (isset($date[CDAY]) and $date[CDAY] !== 'any') {
                $day = $date[CDAY];
                $day = is_int($day) || is_string($day) ? $day : '';
                $res .= ' AND ' . $this->calendar_levels[CDAY]['sql'] . '=' . $day;
            }
        }
        return $res;
    }

    /**
     * Returns an array with all the days in a given month.
     *
     * @param int|string $year both callers pass $page['chronology_date'][CYEAR],
     *   a numeric string parsed from the URL (or the literal 'any')
     * @param int|string $month same: $page['chronology_date'][CMONTH]
     */
    protected function get_all_days_in_month($year, $month): int
    {
        $md = [
            1 => 31,
            28,
            31,
            30,
            31,
            30,
            31,
            31,
            30,
            31,
            30,
            31,
        ];

        if (is_numeric($year) and $month == 2) {
            $nb_days = $md[2];
            if (($year % 4 == 0) and (($year % 100 != 0) or ($year % 400 != 0))) {
                $nb_days++;
            }
        } elseif (is_numeric($month)) {
            $nb_days = $md[$month];
        } else {
            $nb_days = 31;
        }
        return $nb_days;
    }

    /**
     * Build global calendar and assign the result in _$tpl_var_
     * @param array<string, mixed> $tpl_var
     */
    protected function build_global_calendar(array &$tpl_var): bool
    {
        /** @var array<string, mixed> $page */
        global $page;

        $page_chronology_date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];
        assert(count($page_chronology_date) == 0);
        $query = '
  SELECT ' . pwg_db_get_date_YYYYMM($this->date_field) . ' as period,
    COUNT(distinct id) as count';
        $query .= $this->inner_sql;
        $query .= $this->get_date_where();
        $query .= '
    GROUP BY period
    ORDER BY ' . pwg_db_get_year($this->date_field) . ' DESC, ' . pwg_db_get_month($this->date_field) . ' ASC';

        $result = pwg_query($query);
        $items = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $y = substr((string) $row['period'], 0, 4);
            $m = (int) substr((string) $row['period'], 4, 2);
            // count is a COUNT(...) aggregate, always a numeric string from
            // the driver (see pwg_db_fetch_assoc()'s own doc comment).
            $count = is_numeric($row['count']) ? (int) $row['count'] : 0;
            if (! isset($items[$y])) {
                $items[$y] = [
                    'nb_images' => 0,
                    'children' => [],
                ];
            }
            $items[$y]['children'][$m] = $count;
            $items[$y]['nb_images'] += $count;
        }
        // echo ('<pre>'. var_export($items, true) . '</pre>');
        if (count($items) == 1) {// only one year exists so bail out to year view
            [$y] = array_keys($items);
            assert(is_array($page['chronology_date']));
            $page['chronology_date'][CYEAR] = $y;
            return false;
        }

        /** @var array<string, mixed> $lang */
        global $lang;
        $month_labels = is_array($lang['month']) ? array_filter($lang['month'], is_string(...)) : null;
        $calendar_bars = [];
        foreach ($items as $year => $year_data) {
            $chronology_date = [$year];
            $url = duplicate_index_url([
                'chronology_date' => $chronology_date,
            ]);

            $nav_bar = $this->get_nav_bar_from_items(
                $chronology_date,
                $year_data['children'],
                false,
                false,
                $month_labels
            );

            $calendar_bars[] =
              [
                  'U_HEAD' => $url,
                  'NB_IMAGES' => $year_data['nb_images'],
                  'HEAD_LABEL' => $year,
                  'items' => $nav_bar,
              ];
        }
        $tpl_var['calendar_bars'] = $calendar_bars;

        return true;
    }

    /**
     * Build year calendar and assign the result in _$tpl_var_
     * @param array<string, mixed> $tpl_var
     */
    protected function build_year_calendar(array &$tpl_var): bool
    {
        /** @var array<string, mixed> $page */
        global $page;

        $page_chronology_date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];
        assert(count($page_chronology_date) == 1);
        $query = 'SELECT ' . pwg_db_get_date_MMDD($this->date_field) . ' as period,
              COUNT(DISTINCT id) as count';
        $query .= $this->inner_sql;
        $query .= $this->get_date_where();
        $query .= '
    GROUP BY period
    ORDER BY period ASC';

        $result = pwg_query($query);
        $items = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $m = (int) substr((string) $row['period'], 0, 2);
            $d = substr((string) $row['period'], 2, 2);
            // count is a COUNT(...) aggregate, always a numeric string from
            // the driver (see pwg_db_fetch_assoc()'s own doc comment).
            $count = is_numeric($row['count']) ? (int) $row['count'] : 0;
            if (! isset($items[$m])) {
                $items[$m] = [
                    'nb_images' => 0,
                    'children' => [],
                ];
            }
            $items[$m]['children'][$d] = $count;
            $items[$m]['nb_images'] += $count;
        }
        if (count($items) == 1) { // only one month exists so bail out to month view
            [$m] = array_keys($items);
            $page['chronology_date'][CMONTH] = $m;
            return false;
        }
        /** @var array<string, mixed> $lang */
        global $lang;
        $month_labels = is_array($lang['month']) ? array_filter($lang['month'], is_string(...)) : [];
        $calendar_bars = [];
        // $page['chronology_date'] is not mutated between the snapshot above
        // and here (the only mutation happens in the early-return branch
        // just above), so reusing the snapshot is equivalent to a fresh read.
        $year = $page_chronology_date[CYEAR] ?? null;
        $year = is_int($year) || is_string($year) ? $year : 0;
        foreach ($items as $month => $month_data) {
            $chronology_date = [$year, $month];
            $url = duplicate_index_url([
                'chronology_date' => $chronology_date,
            ]);

            $nav_bar = $this->get_nav_bar_from_items(
                $chronology_date,
                $month_data['children'],
                false
            );

            $calendar_bars[] =
              [
                  'U_HEAD' => $url,
                  'NB_IMAGES' => $month_data['nb_images'],
                  'HEAD_LABEL' => $month_labels[$month] ?? $month,
                  'items' => $nav_bar,
              ];
        }
        $tpl_var['calendar_bars'] = $calendar_bars;

        return true;
    }

    /**
     * Build month calendar and assign the result in _$tpl_var_
     * @param array<string, mixed> $tpl_var
     */
    protected function build_month_calendar(array &$tpl_var): bool
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $lang
         * @var array<string, mixed> $conf
         */
        global $page, $lang, $conf;

        // CYEAR/CMONTH are never touched below (only CDAY is toggled, per
        // day, inside the loop), so a single snapshot taken here stays valid
        // for the rest of the method.
        $page_chronology_date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];
        $year = $page_chronology_date[CYEAR] ?? null;
        $year = is_int($year) || is_string($year) ? $year : 0;
        $month = $page_chronology_date[CMONTH] ?? null;
        $month = is_int($month) || is_string($month) ? $month : 0;

        $query = 'SELECT ' . pwg_db_get_dayofmonth($this->date_field) . ' as period,
              COUNT(DISTINCT id) as count';
        $query .= $this->inner_sql;
        $query .= $this->get_date_where();
        $query .= '
    GROUP BY period
    ORDER BY period ASC';

        $items = [];
        $result = pwg_query($query);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $d = (int) $row['period'];
            $items[$d] = [
                'nb_images' => $row['count'],
            ];
        }

        foreach ($items as $day => $data) {
            // chronology_date is always an array here (see get_date_where()'s
            // doc comment for the invariant); narrow it right before writing
            // the per-day offset back to the real global.
            assert(is_array($page['chronology_date']));
            $page['chronology_date'][CDAY] = $day;
            $query = '
  SELECT id, file,representative_ext,path,width,height,rotation, ' . pwg_db_get_dayofweek($this->date_field) . '-1 as dow';
            $query .= $this->inner_sql;
            $query .= $this->get_date_where();
            $query .= '
    ORDER BY ' . DB_RANDOM_FUNCTION . '()
    LIMIT 1';
            unset($page['chronology_date'][CDAY]);

            $row = pwg_db_fetch_assoc(pwg_query($query));
            // $day came from the grouped count query above, which only
            // includes days with at least one image, so this LIMIT 1
            // query always finds a row
            assert(is_array($row));
            $derivative = new DerivativeImage(IMG_SQUARE, new SrcImage($row));
            $items[$day]['derivative'] = $derivative;
            $items[$day]['file'] = $row['file'];
            // dow is DAYOFWEEK(date_field)-1, a numeric SQL expression, so
            // it comes back as a numeric string (never null: the row is
            // guaranteed to exist and date_field is filtered NOT NULL above)
            $items[$day]['dow'] = is_numeric($row['dow']) ? (int) $row['dow'] : 0;
        }

        if (! empty($items)) {
            [$known_day] = array_keys($items);
            $known_dow = $items[$known_day]['dow'];
            $first_day_dow = ($known_dow - ($known_day - 1)) % 7;
            if ($first_day_dow < 0) {
                $first_day_dow += 7;
            }
            // first_day_dow = week day corresponding to the first day of this month
            $wday_labels = is_array($lang['day']) ? $lang['day'] : [];

            if ($conf['week_starts_on'] == 'monday') {
                if ($first_day_dow == 0) {
                    $first_day_dow = 6;
                } else {
                    --$first_day_dow;
                }

                $wday_labels[] = array_shift($wday_labels);
            }

            [$cell_width, $cell_height] = ImageStdParams::get_by_type(IMG_SQUARE)->sizing->ideal_size;

            $tpl_weeks = [];
            $tpl_crt_week = [];

            // fill the empty days in the week before first day of this month
            for ($i = 0; $i < $first_day_dow; $i++) {
                $tpl_crt_week[] = [];
            }

            // get_all_days_in_month() always returns >= 28, so this loop
            // always runs at least once and $dow is always assigned below;
            // the initializer only keeps analysis sound.
            $dow = 0;
            for ($day = 1;
                $day <= $this->get_all_days_in_month($year, $month);
                $day++) {
                $dow = ($first_day_dow + $day - 1) % 7;
                if ($dow == 0 and $day != 1) {
                    $tpl_weeks[] = $tpl_crt_week; // add finished week to week list
                    $tpl_crt_week = []; // start new week
                }

                if (! isset($items[$day])) {// empty day
                    $tpl_crt_week[] =
                      [
                          'DAY' => $day,
                      ];
                } else {
                    $url = duplicate_index_url(
                        [
                            'chronology_date' => [
                                $year,
                                $month,
                                $day,
                            ],
                        ]
                    );

                    $tpl_crt_week[] =
                      [
                          'DAY' => $day,
                          'DOW' => $dow,
                          'NB_ELEMENTS' => $items[$day]['nb_images'],
                          'IMAGE' => $items[$day]['derivative']->get_url(),
                          'U_IMG_LINK' => $url,
                          'IMAGE_ALT' => $items[$day]['file'],
                      ];
                }
            }
            // fill the empty days in the week after the last day of this month
            while ($dow < 6) {
                $tpl_crt_week[] = [];
                $dow++;
            }
            $tpl_weeks[] = $tpl_crt_week;

            $tpl_var['month_view'] =
                [
                    'CELL_WIDTH' => $cell_width,
                    'CELL_HEIGHT' => $cell_height,
                    'wday_labels' => $wday_labels,
                    'weeks' => $tpl_weeks,
                ];
        }

        return true;
    }
}
