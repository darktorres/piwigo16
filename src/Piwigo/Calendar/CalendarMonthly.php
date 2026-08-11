<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Override;
use Piwigo\Calendar\Projection\CalendarMonthlyCalendarPageContext;
use Piwigo\Calendar\Projection\RandomImageForDay;
use Piwigo\Core\TemplateInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\SqlCondition;

/**
 * Monthly calendar style (composed of years/months and days)
 */
final class CalendarMonthly extends CalendarBase
{
    /**
     * Initialize the calendar.
     */
    #[Override]
    public function initialize(CalendarQueryScope $scope): void
    {
        parent::initialize($scope);
        $month_labels = $this->lang->months();
        $this->calendar_levels = [
            [
                'sql' => 'YEAR(' . $this->date_field . ')',
                'dql' => 'YEAR(' . $this->date_field_dql . ')',
                'labels' => null,
            ],
            [
                'sql' => 'MONTH(' . $this->date_field . ')',
                'dql' => 'MONTH(' . $this->date_field_dql . ')',
                'labels' => $month_labels,
            ],
            [
                'sql' => 'DAYOFMONTH(' . $this->date_field . ')',
                'dql' => 'DAYOFMONTH(' . $this->date_field_dql . ')',
                'labels' => null,
            ],
        ];
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    #[Override]
    public function generateCategoryContent(TemplateInterface $template): bool
    {
        $view_type = $this->chronology_view;
        if ($view_type === self::CAL_VIEW_CALENDAR) {
            $tpl_var = [];
            $nb_date_parts = count($this->chronology_date);
            if ($nb_date_parts === 0) {// case A: no year given - display all years+months
                if ($this->buildGlobalCalendar($tpl_var)) {
                    $template->assignContext(new CalendarMonthlyCalendarPageContext($tpl_var));
                    return true;
                }
            }

            // buildGlobalCalendar() may have just narrowed the current
            // selection down to a single year (see its own doc comment), so
            // chronology_date must be re-read, not cached above.
            $nb_date_parts = count($this->chronology_date);
            if ($nb_date_parts === 1) {// case B: year given - display all days in given year
                if ($this->buildYearCalendar($tpl_var)) {
                    $template->assignContext(new CalendarMonthlyCalendarPageContext($tpl_var));
                    $this->buildNavBar(self::CYEAR, null); // years
                    return true;
                }
            }

            // same reasoning: buildYearCalendar() may have narrowed down
            // to a single month.
            $nb_date_parts = count($this->chronology_date);
            if ($nb_date_parts === 2) {// case C: year+month given - display a nice month calendar
                if ($this->buildMonthCalendar($tpl_var)) {
                    $template->assignContext(new CalendarMonthlyCalendarPageContext($tpl_var));
                }
                $this->buildNextPrev();
                return true;
            }
        }

        $nb_date_parts = count($this->chronology_date);
        if ($view_type === self::CAL_VIEW_LIST or $nb_date_parts === 3) {
            if ($nb_date_parts === 0) {
                $this->buildNavBar(self::CYEAR, null); // years
            }
            if ($nb_date_parts === 1) {
                $this->buildNavBar(self::CMONTH, null); // month
            }
            if ($nb_date_parts === 2) {
                $chronology_date = $this->chronology_date;
                $year = $chronology_date[self::CYEAR] ?? null;
                $year = is_int($year) || is_string($year) ? $year : 0;
                $month = $chronology_date[self::CMONTH] ?? null;
                $month = is_int($month) || is_string($month) ? $month : 0;
                $day_labels = range(1, $this->getAllDaysInMonth($year, $month));
                array_unshift($day_labels, 0);
                unset($day_labels[0]);
                $this->buildNavBar(self::CDAY, $day_labels); // days
            }
            $this->buildNextPrev();
        }
        return false;
    }

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     */
    #[Override]
    public function getDateWhere($max_levels = 3, bool $forDql = false): SqlCondition
    {
        $dateField = $forDql ? $this->date_field_dql : $this->date_field;
        $levelKey = $forDql ? 'dql' : 'sql';

        $date = $this->chronology_date;
        while (count($date) > $max_levels) {
            array_pop($date);
        }
        $res = '';
        $params = [];
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
                    $e .= $this->getAllDaysInMonth($year, $month);
                }
            } else {
                $b .= '01-01';
                $e .= '12-31';
                // No self::CMONTH re-check here: this is the else of the exact
                // same isset($date[self::CMONTH]) and $date[self::CMONTH] !== 'any'
                // condition above, so it can never be true.
                if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
                    $day = $date[self::CDAY];
                    $res .= ' AND ' . $this->calendar_levels[self::CDAY][$levelKey] . '= :dateWhereDay';
                    $params['dateWhereDay'] = $day;
                }
            }
            $res = " AND {$dateField} BETWEEN :dateWhereStart AND :dateWhereEnd" . $res;
            $params['dateWhereStart'] = $b;
            $params['dateWhereEnd'] = $e . ' 23:59:59';
        } else {
            $res = ' AND ' . $dateField . ' IS NOT NULL';
            if (isset($date[self::CMONTH]) and $date[self::CMONTH] !== 'any') {
                $month = $date[self::CMONTH];
                $res .= ' AND ' . $this->calendar_levels[self::CMONTH][$levelKey] . '= :dateWhereMonth';
                $params['dateWhereMonth'] = $month;
            }
            if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
                $day = $date[self::CDAY];
                $res .= ' AND ' . $this->calendar_levels[self::CDAY][$levelKey] . '= :dateWhereDay';
                $params['dateWhereDay'] = $day;
            }
        }

        // $res always starts with ' AND ' by construction above -- meant
        // to be concatenated directly after existing raw SQL WHERE text
        // (findImageIds()'s own raw-DBAL path). The DQL side applies this
        // condition via applyCondition()'s own andWhere() call, which
        // already supplies that connector -- a leading 'AND ' there would
        // double up into invalid DQL ("... AND AND ...").
        if ($forDql) {
            $res = substr($res, strlen(' AND '));
        }

        return new SqlCondition($res, $params);
    }

    /**
     * Returns the number of days (28-31) in a given month, accounting for
     * leap years.
     *
     * @param int|string $year each caller passes $page['chronology_date'][self::CYEAR],
     *   a numeric string parsed from the URL (or the literal 'any')
     * @param int|string $month same: $page['chronology_date'][self::CMONTH]
     */
    protected function getAllDaysInMonth($year, $month): int
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
    protected function buildGlobalCalendar(array &$tpl_var): bool
    {
        $page_chronology_date = $this->chronology_date;
        assert(count($page_chronology_date) === 0);
        $scope = $this->scope;
        $dateWhere = $this->getDateWhere(forDql: true);

        $rows = $this->calendarRepository->countByYearMonth($this->date_field_dql, $scope, $dateWhere);
        $items = [];
        foreach ($rows as $row) {
            // period is a DATE_FORMAT_YEAR_MONTH(...) expression, always a
            // scalar; DQL array-hydrated row values are typed mixed
            // regardless, so narrow before casting.
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

        $month_labels = $this->lang->months();
        $calendar_bars = [];
        foreach ($items as $year => $year_data) {
            $chronology_date = [$year];
            $url = $this->urlService->duplicateIndexUrl([
                'chronology_date' => $chronology_date,
            ]);

            $nav_bar = $this->getNavBarFromItems(
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
    protected function buildYearCalendar(array &$tpl_var): bool
    {
        $page_chronology_date = $this->chronology_date;
        assert(count($page_chronology_date) === 1);
        $scope = $this->scope;
        $dateWhere = $this->getDateWhere(forDql: true);

        $rows = $this->calendarRepository->countByMonthDay($this->date_field_dql, $scope, $dateWhere);
        $items = [];
        foreach ($rows as $row) {
            // period is a DATE_FORMAT_MONTH_DAY(...) expression, always a
            // scalar; DQL array-hydrated row values are typed mixed
            // regardless, so narrow before casting.
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
        $month_labels = $this->lang->months();
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

            $nav_bar = $this->getNavBarFromItems(
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
    protected function buildMonthCalendar(array &$tpl_var): bool
    {
        // self::CYEAR/self::CMONTH are never touched below (only self::CDAY is toggled, per
        // day, inside the loop), so a single snapshot taken here stays valid
        // for the rest of the method.
        $page_chronology_date = $this->chronology_date;
        $year = $page_chronology_date[self::CYEAR] ?? null;
        $year = is_int($year) || is_string($year) ? $year : 0;
        $month = $page_chronology_date[self::CMONTH] ?? null;
        $month = is_int($month) || is_string($month) ? $month : 0;

        $scope = $this->scope;
        $dateWhere = $this->getDateWhere(forDql: true);

        $items = [];
        $rows = $this->calendarRepository->countByDayOfMonth($this->date_field_dql, $scope, $dateWhere);
        foreach ($rows as $row) {
            $periodRaw = $row['period'] ?? null;
            $d = is_numeric($periodRaw) ? (int) $periodRaw : 0;
            $items[$d] = [
                'nb_images' => $row['count'],
            ];
        }

        foreach ($items as $day => $data) {
            $this->chronology_date[self::CDAY] = $day;
            $scopePerDay = $this->scope;
            $dateWherePerDay = $this->getDateWhere(forDql: true);
            unset($this->chronology_date[self::CDAY]);

            $row = $this->calendarRepository->findRandomImageForDay($this->date_field_dql, $scopePerDay, $dateWherePerDay);
            // $day came from the grouped count query above, which only
            // includes days with at least one image, so this LIMIT 1
            // query always finds a row
            assert($row instanceof RandomImageForDay);
            $derivative = new DerivativeImage(ImageStdParams::SQUARE, new SrcImage($row->toArray()), $this->currentConfig);
            $items[$day]['derivative'] = $derivative;
            $items[$day]['file'] = $row->file;
            $items[$day]['dow'] = $row->dow;
        }

        if ($items !== []) {
            [$known_day] = array_keys($items);
            $known_dow = $items[$known_day]['dow'] ?? 0;
            $first_day_dow = ($known_dow - ($known_day - 1)) % 7;
            if ($first_day_dow < 0) {
                $first_day_dow += 7;
            }
            // first_day_dow = week day corresponding to the first day of this month
            $wday_labels = $this->lang->days();

            if ($this->currentConfig->weekStartsOn === 'monday') {
                if ($first_day_dow === 0) {
                    $first_day_dow = 6;
                } else {
                    --$first_day_dow;
                }

                $wday_labels[] = array_shift($wday_labels);
            }

            [$cell_width, $cell_height] = $this->imageStdParams->getByType(ImageStdParams::SQUARE)->sizing->ideal_size;

            $tpl_weeks = [];
            $tpl_crt_week = [];

            // fill the empty days in the week before first day of this month
            for ($i = 0; $i < $first_day_dow; $i++) {
                $tpl_crt_week[] = [];
            }

            // getAllDaysInMonth() always returns >= 28, so this loop
            // always runs at least once and $dow is always assigned below;
            // the initializer only keeps analysis sound.
            $dow = 0;
            for ($day = 1;
                $day <= $this->getAllDaysInMonth($year, $month);
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
                          'IMAGE' => $items[$day]['derivative']->getUrl(),
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
