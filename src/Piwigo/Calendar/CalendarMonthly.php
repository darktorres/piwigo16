<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\Dml;
use Piwigo\Db\SqlExpr;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;

/**
 * @package functions\calendar
 */

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
    public function initialize(mixed $inner_sql): void
    {
        parent::initialize($inner_sql);
        $lang = &$GLOBALS['lang'];
        $langArr = is_array($lang) ? $lang : [];
        $monthLabels = is_array($langArr['month'] ?? null) ? $langArr['month'] : null;
        $dayLabels = is_array($langArr['day'] ?? null) ? $langArr['day'] : null;
        $this->calendar_levels = [
          [
              'sql' => SqlExpr::year($this->date_field),
              'labels' => null,
            ],
          [
          'sql' => SqlExpr::month($this->date_field),
          'labels' => $monthLabels,
            ],
          [
              'sql' => SqlExpr::dayOfMonth($this->date_field),
              'labels' => null,
            ],
         ];
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return boolean false indicates that thumbnails where not included
     */
    public function generateCategoryContent(): bool
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $chronologyDate = is_array($pageArr['chronology_view'] ?? null) ? [] :
            (is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : []);
        // Re-read chronology_view separately
        $view_type = $pageArr['chronology_view'] ?? '';
        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        if ($view_type == CAL_VIEW_CALENDAR) {
            $template = TemplateRegistry::current();
            $tpl_var = [];
            if (count($chronologyDate) == 0) {//case A: no year given - display all years+months
                if ($this->buildGlobalCalendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                    return true;
                }
                // Re-read after build_global_calendar may have modified it
                $pageArr = is_array($page) ? $page : [];
                $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];
            }

            if (count($chronologyDate) == 1) {//case B: year given - display all days in given year
                if ($this->buildYearCalendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                    $this->buildNavBar(CYEAR); // years
                    return true;
                }
                // Re-read after build_year_calendar may have modified it
                $pageArr = is_array($page) ? $page : [];
                $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];
            }

            if (count($chronologyDate) == 2) {//case C: year+month given - display a nice month calendar
                if ($this->buildMonthCalendar($tpl_var)) {
                    $template->assign('chronology_calendar', $tpl_var);
                }
                $this->buildNextPrev();
                return true;
            }
        }

        // Re-read chronology_date in case it changed
        $pageArr = is_array($page) ? $page : [];
        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        if ($view_type == CAL_VIEW_LIST or count($chronologyDate) == 3) {
            if (count($chronologyDate) == 0) {
                $this->buildNavBar(CYEAR); // years
            }
            if (count($chronologyDate) == 1) {
                $this->buildNavBar(CMONTH); // month
            }
            if (count($chronologyDate) == 2) {
                $cyear = $chronologyDate[CYEAR];
                $cmonth = $chronologyDate[CMONTH];
                $yearInt = is_int($cyear) ? $cyear : (is_numeric($cyear) ? (int) $cyear : 0);
                $monthInt = is_int($cmonth) ? $cmonth : (is_numeric($cmonth) ? (int) $cmonth : 1);
                $day_labels = range(1, $this->getAllDaysInMonth($yearInt, $monthInt));
                array_unshift($day_labels, 0);
                unset($day_labels[0]);
                $this->buildNavBar(CDAY, $day_labels); // days
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
    public function getDateWhere($max_levels = 3): string
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $date = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        while (count($date) > $max_levels) {
            array_pop($date);
        }
        $res = '';

        $cyear = $date[CYEAR] ?? null;
        $cmonth = $date[CMONTH] ?? null;
        $cday = $date[CDAY] ?? null;

        if (isset($cyear) && $cyear !== 'any') {
            $yearStr = is_int($cyear) ? (string) $cyear : (is_string($cyear) ? $cyear : '');
            $b = $yearStr . '-';
            $e = $yearStr . '-';
            if (isset($cmonth) && $cmonth !== 'any') {
                $monthVal = is_int($cmonth) ? $cmonth : (is_numeric($cmonth) ? (int) $cmonth : 0);
                $b .= sprintf('%02d-', $monthVal);
                $e .= sprintf('%02d-', $monthVal);
                if (isset($cday) && $cday !== 'any') {
                    $dayVal = is_int($cday) ? $cday : (is_numeric($cday) ? (int) $cday : 0);
                    $b .= sprintf('%02d', $dayVal);
                    $e .= sprintf('%02d', $dayVal);
                } else {
                    $yearInt = is_int($cyear) ? $cyear : (is_numeric($cyear) ? (int) $cyear : 0);
                    $b .= '01';
                    $e .= $this->getAllDaysInMonth($yearInt, $monthVal);
                }
            } else {
                $b .= '01-01';
                $e .= '12-31';
                // Note: $cmonth is either null or 'any' here (the opposite branch already handled non-any)
                if (isset($cday) && $cday !== 'any') {
                    $dayVal = is_int($cday) ? $cday : (is_numeric($cday) ? (int) $cday : 0);
                    $level2 = $this->calendar_levels[CDAY] ?? [];
                    $sql2 = is_array($level2) ? (is_string($level2['sql'] ?? null) ? $level2['sql'] : '') : '';
                    $res .= ' AND '.$sql2.'='.$dayVal;
                }
            }
            $res = " AND $this->date_field BETWEEN '$b' AND '$e 23:59:59'" . $res;
        } else {
            $res = ' AND '.$this->date_field.' IS NOT NULL';
            if (isset($cmonth) && $cmonth !== 'any') {
                $monthVal = is_int($cmonth) ? $cmonth : (is_numeric($cmonth) ? (int) $cmonth : 0);
                $level1 = $this->calendar_levels[CMONTH] ?? [];
                $sql1 = is_array($level1) ? (is_string($level1['sql'] ?? null) ? $level1['sql'] : '') : '';
                $res .= ' AND '.$sql1.'='.$monthVal;
            }
            if (isset($cday) && $cday !== 'any') {
                $dayVal = is_int($cday) ? $cday : (is_numeric($cday) ? (int) $cday : 0);
                $level2 = $this->calendar_levels[CDAY] ?? [];
                $sql2 = is_array($level2) ? (is_string($level2['sql'] ?? null) ? $level2['sql'] : '') : '';
                $res .= ' AND '.$sql2.'='.$dayVal;
            }
        }
        return $res;
    }

    /**
     * Returns an array with all the days in a given month.
     */
    protected function getAllDaysInMonth(int $year, int $month): int
    {
        $md = [1 => 31,28,31,30,31,30,31,31,30,31,30,31];

        if ($month == 2) {
            $nb_days = $md[2];
            if (($year % 4 == 0)  and (($year % 100 != 0) or ($year % 400 != 0))) {
                $nb_days++;
            }
        } elseif ($month >= 1 && $month <= 12) {
            $nb_days = $md[$month];
        } else {
            $nb_days = 31;
        }
        return $nb_days;
    }

    /**
     * Build global calendar and assign the result in _$tpl_var_
     */
    /** @param array<mixed> $tpl_var */
    protected function buildGlobalCalendar(array &$tpl_var): bool
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        assert(count($chronologyDate) == 0);
        $query = '
  SELECT '.SqlExpr::dateYYYYMM($this->date_field).' as period,
    COUNT(distinct id) as count';
        $query .= $this->inner_sql;
        $query .= $this->getDateWhere();
        $query .= '
    GROUP BY period
    ORDER BY '.SqlExpr::year($this->date_field).' DESC, '.SqlExpr::month($this->date_field).' ASC';

        $items = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative() as $row) {
            $periodRaw = $row['period'] ?? '';
            $periodStr = is_scalar($periodRaw) ? (string) $periodRaw : '';
            $y = substr($periodStr, 0, 4);
            $m = (int) substr($periodStr, 4, 2);
            if (! isset($items[$y])) {
                $items[$y] = ['nb_images' => 0, 'children' => [] ];
            }
            $countRaw = $row['count'] ?? 0;
            $items[$y]['children'][$m] = $countRaw;
            $items[$y]['nb_images'] += is_int($countRaw) ? $countRaw : (is_numeric($countRaw) ? (int) $countRaw : 0);
        }
        if (count($items) == 1) {// only one year exists so bail out to year view
            $first_year = array_key_first($items);
            $y = (string) $first_year;
            if (is_array($page)) {
                if (!is_array($page['chronology_date'] ?? null)) {
                    $page['chronology_date'] = [];
                }
                $page['chronology_date'][CYEAR] = $y;
            }
            return false;
        }

        $lang = &$GLOBALS['lang'];
        $langArr = is_array($lang) ? $lang : [];
        $monthLabels = is_array($langArr['month'] ?? null) ? $langArr['month'] : null;

        foreach ($items as $year => $year_data) {
            $chronology_date = [ $year ];
            $url = UrlService::get()->duplicateIndexUrl(['chronology_date' => $chronology_date]);

            $nav_bar = $this->getNavBarFromItems(
                $chronology_date,
                $year_data['children'],
                false,
                false,
                $monthLabels
            );

            $nbImages = $year_data['nb_images'];
            if (!isset($tpl_var['calendar_bars']) || !is_array($tpl_var['calendar_bars'])) {
                $tpl_var['calendar_bars'] = [];
            }
            $tpl_var['calendar_bars'][] =
              [
                'U_HEAD'  => $url,
                'NB_IMAGES' => $nbImages,
                'HEAD_LABEL' => $year,
                'items' => $nav_bar,
              ];
        }

        return true;
    }

    /**
     * Build year calendar and assign the result in _$tpl_var_
     */
    /** @param array<mixed> $tpl_var */
    protected function buildYearCalendar(array &$tpl_var): bool
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        assert(count($chronologyDate) == 1);
        $query = 'SELECT '.SqlExpr::dateMMDD($this->date_field).' as period,
              COUNT(DISTINCT id) as count';
        $query .= $this->inner_sql;
        $query .= $this->getDateWhere();
        $query .= '
    GROUP BY period
    ORDER BY period ASC';

        $items = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative() as $row) {
            $periodRaw = $row['period'] ?? '';
            $periodStr = is_scalar($periodRaw) ? (string) $periodRaw : '';
            $m = (int) substr($periodStr, 0, 2);
            $d = substr($periodStr, 2, 2);
            if (! isset($items[$m])) {
                $items[$m] = ['nb_images' => 0, 'children' => [] ];
            }
            $countRaw = $row['count'] ?? 0;
            $items[$m]['children'][$d] = $countRaw;
            $items[$m]['nb_images'] += is_int($countRaw) ? $countRaw : (is_numeric($countRaw) ? (int) $countRaw : 0);
        }
        if (count($items) == 1) { // only one month exists so bail out to month view
            [$m] = array_keys($items);
            if (is_array($page)) {
                $cd = is_array($page['chronology_date'] ?? null) ? $page['chronology_date'] : [];
                $cd[CMONTH] = $m;
                $page['chronology_date'] = $cd;
            }
            return false;
        }
        $lang = &$GLOBALS['lang'];
        $langArr = is_array($lang) ? $lang : [];
        $monthLabels = is_array($langArr['month'] ?? null) ? $langArr['month'] : [];

        foreach ($items as $month => $month_data) {
            $cyearRaw = $chronologyDate[CYEAR] ?? '';
            $chronology_date = [ $cyearRaw, $month ];
            $url = UrlService::get()->duplicateIndexUrl(['chronology_date' => $chronology_date]);

            $nav_bar = $this->getNavBarFromItems(
                $chronology_date,
                $month_data['children'],
                false
            );

            $monthLabelRaw = $monthLabels[$month] ?? null;
            $monthLabel = is_string($monthLabelRaw) ? $monthLabelRaw : (string) $month;

            if (!isset($tpl_var['calendar_bars']) || !is_array($tpl_var['calendar_bars'])) {
                $tpl_var['calendar_bars'] = [];
            }
            $tpl_var['calendar_bars'][] =
              [
                'U_HEAD'  => $url,
                'NB_IMAGES' => $month_data['nb_images'],
                'HEAD_LABEL' => $monthLabel,
                'items' => $nav_bar,
              ];
        }

        return true;
    }

    /**
     * Build month calendar and assign the result in _$tpl_var_
     */
    /** @param array<mixed> $tpl_var */
    protected function buildMonthCalendar(array &$tpl_var): bool
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $lang = &$GLOBALS['lang'];
        $langArr = is_array($lang) ? $lang : [];

        $query = 'SELECT '.SqlExpr::dayOfMonth($this->date_field).' as period,
              COUNT(DISTINCT id) as count';
        $query .= $this->inner_sql;
        $query .= $this->getDateWhere();
        $query .= '
    GROUP BY period
    ORDER BY period ASC';

        $day_counts = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative() as $row) {
            $periodRaw = $row['period'] ?? 0;
            $d = is_int($periodRaw) ? $periodRaw : (is_numeric($periodRaw) ? (int) $periodRaw : 0);
            $day_counts[$d] = $row['count'];
        }

        $items = [];
        foreach ($day_counts as $day => $nb_images) {
            if (is_array($page)) {
                $cdTmp = is_array($page['chronology_date'] ?? null) ? $page['chronology_date'] : [];
                $cdTmp[CDAY] = $day;
                $page['chronology_date'] = $cdTmp;
            }
            $query = '
  SELECT id, file,representative_ext,path,width,height,rotation, '.SqlExpr::dayOfWeek($this->date_field).'-1 as dow';
            $query .= $this->inner_sql;
            $query .= $this->getDateWhere();
            $query .= '
    ORDER BY '.Dml::RANDOM_FUNCTION.'()
    LIMIT 1';
            if (is_array($page)) {
                $cdTmp2 = is_array($page['chronology_date'] ?? null) ? $page['chronology_date'] : [];
                unset($cdTmp2[CDAY]);
                $page['chronology_date'] = $cdTmp2;
            }

            $row = ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAssociative() ?: null;
            if ($row === null) {
                continue;
            }
            $derivative = new DerivativeImage(DerivativeSize::Square->value, new SrcImage($row));
            $dowRaw = $row['dow'] ?? 0;
            $items[$day] = [
                'nb_images' => $nb_images,
                'derivative' => $derivative,
                'file' => $row['file'],
                'dow' => $dowRaw,
            ];
        }

        if (!empty($items)) {
            [$known_day] = array_keys($items);
            $known_dow_raw = $items[$known_day]['dow'];
            $known_dow = is_int($known_dow_raw) ? $known_dow_raw : (is_numeric($known_dow_raw) ? (int) $known_dow_raw : 0);
            $first_day_dow = ($known_dow - ($known_day - 1)) % 7;
            if ($first_day_dow < 0) {
                $first_day_dow += 7;
            }
            //first_day_dow = week day corresponding to the first day of this month
            $dayLabels = is_array($langArr['day'] ?? null) ? $langArr['day'] : [];
            $wday_labels = $dayLabels;

            if ('monday' == Config::weekStartsOn()) {
                if ($first_day_dow == 0) {
                    $first_day_dow = 6;
                } else {
                    $first_day_dow -= 1;
                }

                array_push($wday_labels, array_shift($wday_labels) ?? '');
            }

            [$cell_width, $cell_height] = ImageStdParams::getByType(DerivativeSize::Square->value)->sizing->ideal_size;

            $tpl_weeks    = [];
            $tpl_crt_week = [];
            $dow = 0;

            //fill the empty days in the week before first day of this month
            for ($i = 0; $i < $first_day_dow; $i++) {
                $tpl_crt_week[] = [];
            }

            // Re-read chronology_date for month calendar loop
            $pageArr2 = is_array($page) ? $page : [];
            $chronologyDate = is_array($pageArr2['chronology_date'] ?? null) ? $pageArr2['chronology_date'] : [];
            $cyearRaw = $chronologyDate[CYEAR] ?? 0;
            $cmonthRaw = $chronologyDate[CMONTH] ?? 1;
            $cyearInt = is_int($cyearRaw) ? $cyearRaw : (is_numeric($cyearRaw) ? (int) $cyearRaw : 0);
            $cmonthInt = is_int($cmonthRaw) ? $cmonthRaw : (is_numeric($cmonthRaw) ? (int) $cmonthRaw : 1);

            for ($day = 1;
                $day <= $this->getAllDaysInMonth($cyearInt, $cmonthInt);
                $day++) {
                $dow = ($first_day_dow + $day - 1) % 7;
                if ($dow == 0 and $day != 1) {
                    $tpl_weeks[]    = $tpl_crt_week; // add finished week to week list
                    $tpl_crt_week   = []; // start new week
                }

                if (!isset($items[$day])) {// empty day
                    $tpl_crt_week[]   =
                      [
                          'DAY' => $day,
                        ];
                } else {
                    $url = UrlService::get()->duplicateIndexUrl(
                        [
                          'chronology_date' =>
                            [
                              $cyearRaw,
                              $cmonthRaw,
                              $day,
                            ],
                        ]
                    );

                    $tpl_crt_week[]   =
                      [
                          'DAY'         => $day,
                          'DOW'         => $dow,
                          'NB_ELEMENTS' => $items[$day]['nb_images'],
                          'IMAGE'       => $items[$day]['derivative']->getUrl(),
                          'U_IMG_LINK'  => $url,
                          'IMAGE_ALT'   => $items[$day]['file'],
                        ];
                }
            }
            //fill the empty days in the week after the last day of this month
            while ($dow < 6) {
                $tpl_crt_week[] = [];
                $dow++;
            }
            $tpl_weeks[]    = $tpl_crt_week;

            $tpl_var['month_view'] =
                [
                   'CELL_WIDTH'   => $cell_width,
                   'CELL_HEIGHT' => $cell_height,
                   'wday_labels' => $wday_labels,
                   'weeks' => $tpl_weeks,
                  ];
        }

        return true;
    }
}
