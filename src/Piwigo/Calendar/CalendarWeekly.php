<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Calendar;

use Piwigo\Core\Lang;

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
        $week_no_labels = [];
        for ($i = 1; $i <= 53; $i++) {
            $week_no_labels[$i] = Lang::t('Week %d', $i);
            // $week_no_labels[$i] = $i;
        }

        $day_labels = \Piwigo\Core\Lang::days();

        $this->calendar_levels = [
            [
                'sql' => \Piwigo\Db\SqlDialect::getYear($this->date_field),
                'labels' => null,
            ],
            [
                'sql' => \Piwigo\Db\SqlDialect::getWeek($this->date_field) . '+1',
                'labels' => $week_no_labels,
            ],
            [
                'sql' => \Piwigo\Db\SqlDialect::getDayOfWeek($this->date_field) . '-1',
                'labels' => $day_labels,
            ],
        ];
        // Comment next lines for week starting on Sunday or if MySQL version<4.0.17
        // WEEK(date,5) = "0-53 - Week 1=the first week with a Monday in this year"
        if (\Piwigo\Config\Config::weekStartsOn() === 'monday') {
            $this->calendar_levels[self::CWEEK]['sql'] = \Piwigo\Db\SqlDialect::getWeek($this->date_field, 5) . '+1';
            $this->calendar_levels[self::CDAY]['sql'] = \Piwigo\Db\SqlDialect::getWeekday($this->date_field);
            // Always a real array here: $day_labels above comes from
            // Lang::days(), which never returns null.
            $cday_labels = $this->calendar_levels[self::CDAY]['labels'];
            $shifted = array_shift($cday_labels);
            if (is_string($shifted)) {
                $cday_labels[] = $shifted;
            }
            $this->calendar_levels[self::CDAY]['labels'] = $cday_labels;
        }
    }

    /**
     * Generate navigation bars for category page.
     *
     * @return bool false indicates that thumbnails where not included
     */
    #[\Override]
    public function generate_category_content(\Piwigo\Core\TemplateInterface $template): bool
    {
        $nb_date_parts = count($this->chronology_date);
        if ($nb_date_parts == 0) {
            $this->build_nav_bar(self::CYEAR, null, $template); // years
        }
        if ($nb_date_parts == 1) {
            $this->build_nav_bar(self::CWEEK, [], $template); // week nav bar 1-53
        }
        if ($nb_date_parts == 2) {
            $this->build_nav_bar(self::CDAY, null, $template); // days nav bar Mon-Sun
        }
        $this->build_next_prev($template);
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
            $y = $date[self::CYEAR];
            $res = " AND {$this->date_field} BETWEEN '{$y}-01-01' AND '{$y}-12-31 23:59:59'";
        }

        if (isset($date[self::CWEEK]) and $date[self::CWEEK] !== 'any') {
            $week = $date[self::CWEEK];
            $res .= ' AND ' . $this->calendar_levels[self::CWEEK]['sql'] . '=' . $week;
        }
        if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
            $day = $date[self::CDAY];
            $res .= ' AND ' . $this->calendar_levels[self::CDAY]['sql'] . '=' . $day;
        }
        if (empty($res)) {
            $res = ' AND ' . $this->date_field . ' IS NOT NULL';
        }
        return $res;
    }
}
