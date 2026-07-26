<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;

/**
 * Monthly calendar style (composed of years/months and days)
 */
final class CalendarMonthly extends CalendarBase
{
    /**
     * Initialize the calendar.
     * @param string $inner_sql
     */
    #[\Override]
    public function initialize($inner_sql): void
    {
        parent::initialize($inner_sql);
        $month_labels = \Piwigo\Core\Lang::months();
        $this->calendar_levels = [
            [
                'sql' => \Piwigo\Db\SqlDialect::getYear($this->date_field),
                'labels' => null,
            ],
            [
                'sql' => \Piwigo\Db\SqlDialect::getMonth($this->date_field),
                'labels' => $month_labels,
            ],
            [
                'sql' => \Piwigo\Db\SqlDialect::getDayOfMonth($this->date_field),
                'labels' => null,
            ],
        ];
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    #[\Override]
    public function generate_category_content(\Piwigo\Core\TemplateInterface $template): bool
    {
        $view_type = $this->chronology_view;
        if ($view_type === self::CAL_VIEW_CALENDAR) {
            $tpl_var = [];
            $nb_date_parts = count($this->chronology_date);
            if ($nb_date_parts === 0) {// case A: no year given - display all years+months
                if ($this->build_global_calendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                    return true;
                }
            }

            // build_global_calendar() may have just narrowed the current
            // selection down to a single year (see its own doc comment), so
            // chronology_date must be re-read, not cached above.
            $nb_date_parts = count($this->chronology_date);
            if ($nb_date_parts === 1) {// case B: year given - display all days in given year
                if ($this->build_year_calendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                    $this->build_nav_bar(self::CYEAR, null, $template); // years
                    return true;
                }
            }

            // same reasoning: build_year_calendar() may have narrowed down
            // to a single month.
            $nb_date_parts = count($this->chronology_date);
            if ($nb_date_parts === 2) {// case C: year+month given - display a nice month calendar
                if ($this->build_month_calendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                }
                $this->build_next_prev($template);
                return true;
            }
        }

        $nb_date_parts = count($this->chronology_date);
        if ($view_type === self::CAL_VIEW_LIST or $nb_date_parts === 3) {
            if ($nb_date_parts === 0) {
                $this->build_nav_bar(self::CYEAR, null, $template); // years
            }
            if ($nb_date_parts === 1) {
                $this->build_nav_bar(self::CMONTH, null, $template); // month
            }
            if ($nb_date_parts === 2) {
                $chronology_date = $this->chronology_date;
                $year = $chronology_date[self::CYEAR] ?? null;
                $year = is_int($year) || is_string($year) ? $year : 0;
                $month = $chronology_date[self::CMONTH] ?? null;
                $month = is_int($month) || is_string($month) ? $month : 0;
                $day_labels = range(1, $this->get_all_days_in_month($year, $month));
                array_unshift($day_labels, 0);
                unset($day_labels[0]);
                $this->build_nav_bar(self::CDAY, $day_labels, $template); // days
            }
            $this->build_next_prev($template);
        }
        return false;
    }

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     */
    #[\Override]
    public function get_date_where($max_levels = 3): string
    {
        $date = $this->chronology_date;
        while (count($date) > $max_levels) {
            array_pop($date);
        }
        $res = '';
        if (isset($date[self::CYEAR]) and $date[self::CYEAR] !== 'any') {
            $year = $date[self::CYEAR];
            $b = $year . '-';
            $e = $year . '-';
            if (isset($date[self::CMONTH]) and $date[self::CMONTH] !== 'any') {
                $month = $date[self::CMONTH];
                $month = is_numeric($month) ? (int) $month : 0;
                $b .= sprintf('%02d-', $month);
                $e .= sprintf('%02d-', $month);
                if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
                    $day = $date[self::CDAY];
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
                // No self::CMONTH re-check here: this is the else of the exact
                // same isset($date[self::CMONTH]) and $date[self::CMONTH] !== 'any'
                // condition above, so it can never be true.
                if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
                    $day = $date[self::CDAY];
                    $res .= ' AND ' . $this->calendar_levels[self::CDAY]['sql'] . '=' . $day;
                }
            }
            $res = " AND {$this->date_field} BETWEEN '{$b}' AND '{$e} 23:59:59'" . $res;
        } else {
            $res = ' AND ' . $this->date_field . ' IS NOT NULL';
            if (isset($date[self::CMONTH]) and $date[self::CMONTH] !== 'any') {
                $month = $date[self::CMONTH];
                $res .= ' AND ' . $this->calendar_levels[self::CMONTH]['sql'] . '=' . $month;
            }
            if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
                $day = $date[self::CDAY];
                $res .= ' AND ' . $this->calendar_levels[self::CDAY]['sql'] . '=' . $day;
            }
        }
        return $res;
    }

    /**
     * Returns an array with all the days in a given month.
     *
     * @param int|string $year both callers pass $page['chronology_date'][self::CYEAR],
     *   a numeric string parsed from the URL (or the literal 'any')
     * @param int|string $month same: $page['chronology_date'][self::CMONTH]
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

        if (is_numeric($year) and (string) $month === '2') {
            $nb_days = $md[2];
            if (((int) $year % 4 === 0) and (((int) $year % 100 !== 0) or ((int) $year % 400 !== 0))) {
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
     * $tpl_var is a growing Smarty template-variable bag shared across this
     * class's own build_*_calendar() methods, each assigning its own keys
     * -- matches Template::assign()'s own by-design arbitrary-value
     * contract, not a single reusable shape.
     *
     * @param array<string, mixed> $tpl_var
     */
    protected function build_global_calendar(array &$tpl_var): bool
    {
        $page_chronology_date = $this->chronology_date;
        assert(count($page_chronology_date) === 0);
        $query = '
  SELECT ' . \Piwigo\Db\SqlDialect::getDateYYYYMM($this->date_field) . ' as period,
    COUNT(distinct id) as count';
        $query .= $this->inner_sql;
        $query .= $this->get_date_where();
        $query .= '
    GROUP BY period
    ORDER BY ' . \Piwigo\Db\SqlDialect::getYear($this->date_field) . ' DESC, ' . \Piwigo\Db\SqlDialect::getMonth($this->date_field) . ' ASC';

        $rows = $this->calendarRepository->findRows($query);
        $items = [];
        foreach ($rows as $row) {
            // period is a DATE_FORMAT(...) expression (SqlDialect::
            // getDateYYYYMM()), always a scalar; DBAL row values are
            // typed mixed regardless, so narrow before casting.
            $periodRaw = $row['period'] ?? null;
            $period = is_scalar($periodRaw) ? (string) $periodRaw : '';
            $y = substr($period, 0, 4);
            $m = (int) substr($period, 4, 2);
            // count is a COUNT(...) aggregate; DBAL row values are mixed
            // (native int or numeric string depending on driver), guard
            // either way.
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
        if (count($items) === 1) {// only one year exists so bail out to year view
            [$y] = array_keys($items);
            $this->chronology_date[self::CYEAR] = $y;
            return false;
        }

        $month_labels = \Piwigo\Core\Lang::months();
        $calendar_bars = [];
        foreach ($items as $year => $year_data) {
            $chronology_date = [$year];
            $url = $this->urlService->duplicateIndexUrl([
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
     * $tpl_var is a growing Smarty template-variable bag shared across this
     * class's own build_*_calendar() methods, each assigning its own keys
     * -- matches Template::assign()'s own by-design arbitrary-value
     * contract, not a single reusable shape.
     *
     * @param array<string, mixed> $tpl_var
     */
    protected function build_year_calendar(array &$tpl_var): bool
    {
        $page_chronology_date = $this->chronology_date;
        assert(count($page_chronology_date) === 1);
        $query = 'SELECT ' . \Piwigo\Db\SqlDialect::getDateMMDD($this->date_field) . ' as period,
              COUNT(DISTINCT id) as count';
        $query .= $this->inner_sql;
        $query .= $this->get_date_where();
        $query .= '
    GROUP BY period
    ORDER BY period ASC';

        $rows = $this->calendarRepository->findRows($query);
        $items = [];
        foreach ($rows as $row) {
            // period is a DATE_FORMAT(...) expression (SqlDialect::
            // getDateMMDD()), always a scalar; DBAL row values are typed
            // mixed regardless, so narrow before casting.
            $periodRaw = $row['period'] ?? null;
            $period = is_scalar($periodRaw) ? (string) $periodRaw : '';
            $m = (int) substr($period, 0, 2);
            $d = substr($period, 2, 2);
            // count is a COUNT(...) aggregate; DBAL row values are mixed
            // (native int or numeric string depending on driver), guard
            // either way.
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
        if (count($items) === 1) { // only one month exists so bail out to month view
            [$m] = array_keys($items);
            $this->chronology_date[self::CMONTH] = $m;
            return false;
        }
        $month_labels = \Piwigo\Core\Lang::months();
        $calendar_bars = [];
        // $this->chronology_date is not mutated between the snapshot above
        // and here (the only mutation happens in the early-return branch
        // just above), so reusing the snapshot is equivalent to a fresh read.
        $year = $page_chronology_date[self::CYEAR] ?? null;
        $year = is_int($year) || is_string($year) ? $year : 0;
        foreach ($items as $month => $month_data) {
            $chronology_date = [$year, $month];
            $url = $this->urlService->duplicateIndexUrl([
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
     * $tpl_var is a growing Smarty template-variable bag shared across this
     * class's own build_*_calendar() methods, each assigning its own keys
     * -- matches Template::assign()'s own by-design arbitrary-value
     * contract, not a single reusable shape.
     *
     * @param array<string, mixed> $tpl_var
     */
    protected function build_month_calendar(array &$tpl_var): bool
    {
        // self::CYEAR/self::CMONTH are never touched below (only self::CDAY is toggled, per
        // day, inside the loop), so a single snapshot taken here stays valid
        // for the rest of the method.
        $page_chronology_date = $this->chronology_date;
        $year = $page_chronology_date[self::CYEAR] ?? null;
        $year = is_int($year) || is_string($year) ? $year : 0;
        $month = $page_chronology_date[self::CMONTH] ?? null;
        $month = is_int($month) || is_string($month) ? $month : 0;

        $query = 'SELECT ' . \Piwigo\Db\SqlDialect::getDayOfMonth($this->date_field) . ' as period,
              COUNT(DISTINCT id) as count';
        $query .= $this->inner_sql;
        $query .= $this->get_date_where();
        $query .= '
    GROUP BY period
    ORDER BY period ASC';

        $items = [];
        $rows = $this->calendarRepository->findRows($query);
        foreach ($rows as $row) {
            $periodRaw = $row['period'] ?? null;
            $d = is_numeric($periodRaw) ? (int) $periodRaw : 0;
            $items[$d] = [
                'nb_images' => $row['count'],
            ];
        }

        foreach ($items as $day => $data) {
            $this->chronology_date[self::CDAY] = $day;
            $query = '
  SELECT id, file,representative_ext,path,width,height,rotation, ' . \Piwigo\Db\SqlDialect::getDayOfWeek($this->date_field) . '-1 as dow';
            $query .= $this->inner_sql;
            $query .= $this->get_date_where();
            $query .= '
    ORDER BY ' . \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION . '()
    LIMIT 1';
            unset($this->chronology_date[self::CDAY]);

            $row = $this->calendarRepository->findRow($query);
            // $day came from the grouped count query above, which only
            // includes days with at least one image, so this LIMIT 1
            // query always finds a row
            assert(is_array($row));
            $derivative = new DerivativeImage(ImageStdParams::SQUARE, new SrcImage($row));
            $items[$day]['derivative'] = $derivative;
            $items[$day]['file'] = $row['file'];
            // dow is DAYOFWEEK(date_field)-1, a numeric SQL expression, so
            // it comes back as a numeric string (never null: the row is
            // guaranteed to exist and date_field is filtered NOT NULL above)
            $items[$day]['dow'] = is_numeric($row['dow']) ? (int) $row['dow'] : 0;
        }

        if ($items !== []) {
            [$known_day] = array_keys($items);
            $known_dow = $items[$known_day]['dow'] ?? 0;
            $first_day_dow = ($known_dow - ($known_day - 1)) % 7;
            if ($first_day_dow < 0) {
                $first_day_dow += 7;
            }
            // first_day_dow = week day corresponding to the first day of this month
            $wday_labels = \Piwigo\Core\Lang::days();

            if (\Piwigo\Config\CurrentConfig::weekStartsOn() === 'monday') {
                if ($first_day_dow === 0) {
                    $first_day_dow = 6;
                } else {
                    --$first_day_dow;
                }

                $wday_labels[] = array_shift($wday_labels);
            }

            [$cell_width, $cell_height] = ImageStdParams::get_by_type(ImageStdParams::SQUARE)->sizing->ideal_size;

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
                if ($dow === 0 and $day !== 1) {
                    $tpl_weeks[] = $tpl_crt_week; // add finished week to week list
                    $tpl_crt_week = []; // start new week
                }

                if (! isset($items[$day])) {// empty day
                    $tpl_crt_week[] =
                      [
                          'DAY' => $day,
                      ];
                } else {
                    $url = $this->urlService->duplicateIndexUrl(
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
                          'IMAGE_ALT' => $items[$day]['file'] ?? null,
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
