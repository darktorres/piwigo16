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
use Piwigo\Core\TemplateInterface;
use Piwigo\Permission\SqlCondition;

/**
 * Weekly calendar style (composed of years/week in years and days in week)
 */
final class CalendarWeekly extends CalendarBase
{
    /**
     * Initialize the calendar
     */
    #[Override]
    public function initialize(CalendarQueryScope $scope): void
    {
        parent::initialize($scope);
        $week_no_labels = [];
        for ($i = 1; $i <= 53; $i++) {
            $week_no_labels[$i] = $this->lang->t('Week %d', $i);
            // $week_no_labels[$i] = $i;
        }

        $day_labels = $this->lang->days();

        $this->calendar_levels = [
            [
                'sql' => 'YEAR(' . $this->date_field . ')',
                'dql' => 'YEAR(' . $this->date_field_dql . ')',
                'labels' => null,
            ],
            [
                'sql' => 'WEEK(' . $this->date_field . ')+1',
                'dql' => 'WEEK(' . $this->date_field_dql . ')+1',
                'labels' => $week_no_labels,
            ],
            [
                'sql' => 'DAYOFWEEK(' . $this->date_field . ')-1',
                'dql' => 'DAYOFWEEK(' . $this->date_field_dql . ')-1',
                'labels' => $day_labels,
            ],
        ];
        // Comment next lines for week starting on Sunday or if MySQL version<4.0.17
        // WEEK(date,5) = "0-53 - Week 1=the first week with a Monday in this year"
        if ($this->currentConfig->weekStartsOn === 'monday') {
            $this->calendar_levels[self::CWEEK]['sql'] = 'WEEK(' . $this->date_field . ', 5)+1';
            $this->calendar_levels[self::CWEEK]['dql'] = 'WEEK(' . $this->date_field_dql . ', 5)+1';
            $this->calendar_levels[self::CDAY]['sql'] = 'WEEKDAY(' . $this->date_field . ')';
            $this->calendar_levels[self::CDAY]['dql'] = 'WEEKDAY(' . $this->date_field_dql . ')';
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
    #[Override]
    public function generateCategoryContent(TemplateInterface $template): bool
    {
        $nb_date_parts = count($this->chronology_date);
        if ($nb_date_parts === 0) {
            $this->buildNavBar(self::CYEAR, null); // years
        }
        if ($nb_date_parts === 1) {
            $this->buildNavBar(self::CWEEK, []); // week nav bar 1-53
        }
        if ($nb_date_parts === 2) {
            $this->buildNavBar(self::CDAY, null); // days nav bar Mon-Sun
        }
        $this->buildNextPrev();
        return false;
    }

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     */
    #[Override]
    public function getDateWhere(int $max_levels = 3, bool $forDql = false): SqlCondition
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
            $y = $date[self::CYEAR];
            $res = " AND {$dateField} BETWEEN :dateWhereYearStart AND :dateWhereYearEnd";
            $params['dateWhereYearStart'] = $y . '-01-01';
            $params['dateWhereYearEnd'] = $y . '-12-31 23:59:59';
        }

        if (isset($date[self::CWEEK]) and $date[self::CWEEK] !== 'any') {
            $week = $date[self::CWEEK];
            $res .= ' AND ' . $this->calendar_levels[self::CWEEK][$levelKey] . '= :dateWhereWeek';
            $params['dateWhereWeek'] = $week;
        }
        if (isset($date[self::CDAY]) and $date[self::CDAY] !== 'any') {
            $day = $date[self::CDAY];
            $res .= ' AND ' . $this->calendar_levels[self::CDAY][$levelKey] . '= :dateWhereDay';
            $params['dateWhereDay'] = $day;
        }
        if ($res === '') {
            $res = ' AND ' . $dateField . ' IS NOT NULL';
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
}
