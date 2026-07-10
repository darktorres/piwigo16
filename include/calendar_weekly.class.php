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
 * Weekly calendar style (composed of years/week in years and days in week)
 */
class CalendarWeekly extends CalendarBase
{
    /**
     * Initialize the calendar
     * @param string $inner_sql
     */
    #[\Override]
    public function initialize($inner_sql): void
    {
        parent::initialize($inner_sql);
        /**
         * @var array<string, mixed> $lang
         * @var array<string, mixed> $conf
         */
        global $lang, $conf;
        $week_no_labels = [];
        for ($i = 1; $i <= 53; $i++) {
            $week_no_labels[$i] = l10n('Week %d', $i);
            // $week_no_labels[$i] = $i;
        }

        // $lang['day'] is a labels array (numeric weekday => translated name)
        // defined by every language file as array<int, string>; filter to
        // string values to match CalendarBase::$calendar_levels' declared
        // element shape (array<int|string, string>|null) instead of a bare
        // mixed cast.
        $day_labels = is_array($lang['day']) ? array_filter($lang['day'], 'is_string') : null;

        $this->calendar_levels = [
            [
                'sql' => pwg_db_get_year($this->date_field),
                'labels' => null,
            ],
            [
                'sql' => pwg_db_get_week($this->date_field) . '+1',
                'labels' => $week_no_labels,
            ],
            [
                'sql' => pwg_db_get_dayofweek($this->date_field) . '-1',
                'labels' => $day_labels,
            ],
        ];
        // Comment next lines for week starting on Sunday or if MySQL version<4.0.17
        // WEEK(date,5) = "0-53 - Week 1=the first week with a Monday in this year"
        if ($conf['week_starts_on'] == 'monday') {
            $this->calendar_levels[CWEEK]['sql'] = pwg_db_get_week($this->date_field, 5) . '+1';
            $this->calendar_levels[CDAY]['sql'] = pwg_db_get_weekday($this->date_field);
            $cday_labels = $this->calendar_levels[CDAY]['labels'];
            if (is_array($cday_labels)) {
                $shifted = array_shift($cday_labels);
                if (is_string($shifted)) {
                    $cday_labels[] = $shifted;
                }
                $this->calendar_levels[CDAY]['labels'] = $cday_labels;
            }
        }
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    public function generate_category_content(): bool
    {
        /** @var array<string, mixed> $page */
        global $page;

        $nb_date_parts = is_array($page['chronology_date']) ? count($page['chronology_date']) : 0;
        if ($nb_date_parts == 0) {
            $this->build_nav_bar(CYEAR); // years
        }
        if ($nb_date_parts == 1) {
            $this->build_nav_bar(CWEEK, []); // week nav bar 1-53
        }
        if ($nb_date_parts == 2) {
            $this->build_nav_bar(CDAY); // days nav bar Mon-Sun
        }
        $this->build_next_prev();
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
        // or feed.php/picture.php (same invariant documented in
        // CalendarMonthly::get_date_where()).
        $date = is_array($page['chronology_date']) ? $page['chronology_date'] : [];
        while (count($date) > $max_levels) {
            array_pop($date);
        }
        $res = '';
        if (isset($date[CYEAR]) and $date[CYEAR] !== 'any') {
            $y = $date[CYEAR];
            $y = is_int($y) || is_string($y) ? $y : '';
            $res = " AND {$this->date_field} BETWEEN '{$y}-01-01' AND '{$y}-12-31 23:59:59'";
        }

        if (isset($date[CWEEK]) and $date[CWEEK] !== 'any') {
            $week = $date[CWEEK];
            $week = is_int($week) || is_string($week) ? $week : '';
            $res .= ' AND ' . $this->calendar_levels[CWEEK]['sql'] . '=' . $week;
        }
        if (isset($date[CDAY]) and $date[CDAY] !== 'any') {
            $day = $date[CDAY];
            $day = is_int($day) || is_string($day) ? $day : '';
            $res .= ' AND ' . $this->calendar_levels[CDAY]['sql'] . '=' . $day;
        }
        if (empty($res)) {
            $res = ' AND ' . $this->date_field . ' IS NOT NULL';
        }
        return $res;
    }
}
