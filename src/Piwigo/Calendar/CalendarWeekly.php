<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Db\SqlExpr;

/**
 * @package functions\calendar
 */
/**
 * Weekly calendar style (composed of years/week in years and days in week)
 */
final class CalendarWeekly extends CalendarBase
{
    /**
     * Initialize the calendar
     * @param string $inner_sql
     */
    #[\Override]
    public function initialize(mixed $inner_sql): void
    {
        parent::initialize($inner_sql);
        $lang = &$GLOBALS['lang'];
        $week_no_labels = [];
        for ($i = 1; $i <= 53; $i++) {
            $week_no_labels[$i] = Lang::t('Week %d', $i);
            //$week_no_labels[$i] = $i;
        }

        $langArr = is_array($lang) ? $lang : [];
        $dayLabels = is_array($langArr['day'] ?? null) ? $langArr['day'] : [];
        $this->calendar_levels = [
          [
              'sql' => SqlExpr::year($this->date_field),
              'labels' => null,
            ],
          [
              'sql' => SqlExpr::week($this->date_field).'+1',
              'labels' => $week_no_labels,
            ],
          [
              'sql' => SqlExpr::dayOfWeek($this->date_field).'-1',
              'labels' => $dayLabels,
            ],
         ];
        //Comment next lines for week starting on Sunday or if MySQL version<4.0.17
        //WEEK(date,5) = "0-53 - Week 1=the first week with a Monday in this year"
        if ('monday' == Config::weekStartsOn()) {
            $this->calendar_levels[CWEEK]['sql'] = SqlExpr::week($this->date_field, 5).'+1';
            $this->calendar_levels[CDAY]['sql'] = SqlExpr::weekday($this->date_field);
            $dayLabelsArr = (array) $this->calendar_levels[CDAY]['labels'];
            $shifted = array_shift($dayLabelsArr);
            if ($shifted !== null) {
                $dayLabelsArr[] = $shifted;
            }
            $this->calendar_levels[CDAY]['labels'] = $dayLabelsArr;
        }
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return boolean false indicates that thumbnails where not included
     */
    #[\Override]
    public function generateCategoryContent(): bool
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];

        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];
        if (count($chronologyDate) == 0) {
            $this->buildNavBar(CYEAR); // years
        }
        if (count($chronologyDate) == 1) {
            $this->buildNavBar(CWEEK, []); // week nav bar 1-53
        }
        if (count($chronologyDate) == 2) {
            $this->buildNavBar(CDAY); // days nav bar Mon-Sun
        }
        $this->buildNextPrev();
        return false;
    }

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     */
    #[\Override]
    public function getDateWhere(int $max_levels = 3): string
    {
        $page = &$GLOBALS['page'];
        $pageArr2 = is_array($page) ? $page : [];
        $date = is_array($pageArr2['chronology_date'] ?? null) ? $pageArr2['chronology_date'] : [];
        while (count($date) > $max_levels) {
            array_pop($date);
        }
        $res = '';
        if (isset($date[CYEAR]) and $date[CYEAR] !== 'any') {
            $y = is_scalar($date[CYEAR]) ? (string)$date[CYEAR] : '';
            $res = " AND $this->date_field BETWEEN '$y-01-01' AND '$y-12-31 23:59:59'";
        }

        if (isset($date[CWEEK]) and $date[CWEEK] !== 'any') {
            $cweekLevel = is_array($this->calendar_levels[CWEEK] ?? null) ? $this->calendar_levels[CWEEK] : [];
            $cweekSql = is_string($cweekLevel['sql'] ?? null) ? $cweekLevel['sql'] : '';
            $res .= ' AND '.$cweekSql.'='.(is_scalar($date[CWEEK]) ? (string)$date[CWEEK] : '');
        }
        if (isset($date[CDAY]) and $date[CDAY] !== 'any') {
            $cdayLevel = is_array($this->calendar_levels[CDAY] ?? null) ? $this->calendar_levels[CDAY] : [];
            $cdaySql = is_string($cdayLevel['sql'] ?? null) ? $cdayLevel['sql'] : '';
            $res .= ' AND '.$cdaySql.'='.(is_scalar($date[CDAY]) ? (string)$date[CDAY] : '');
        }
        if (empty($res)) {
            $res = ' AND '.$this->date_field.' IS NOT NULL';
        }
        return $res;
    }
}
