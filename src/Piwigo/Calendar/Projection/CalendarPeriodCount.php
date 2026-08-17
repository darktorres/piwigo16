<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * Shared row shape for {@see \Piwigo\Calendar\CalendarRepository}'s 4
 * period-count queries (countGroupedByLevel()/countByYearMonth()/
 * countByMonthDay()/countByDayOfMonth()) -- one real consumer per
 * method, {@see \Piwigo\Calendar\CalendarBase::buildNavBar()} and
 * {@see \Piwigo\Calendar\CalendarMonthly}'s own build*Calendar() methods.
 *
 * $period's real representation varies by query (YEAR()/WEEK()/
 * WEEKDAY()/DAYOFMONTH() are numeric, DATE_FORMAT_YEAR_MONTH()/
 * DATE_FORMAT_MONTH_DAY() are formatted digit strings); $count is a
 * COUNT(DISTINCT ...) aggregate, a native int on some drivers and a
 * numeric string on others (DBAL's own cross-driver variance, not a
 * data problem). Both stay `int|string`, never forced to a single type
 * here: every real consumer already does its own is_numeric()/is_scalar()
 * narrowing downstream, and buildMonthCalendar()'s own day-count loop
 * deliberately never casts $count to int, unlike its
 * buildGlobalCalendar()/buildYearCalendar() siblings -- a real, existing
 * behavioral difference a forced-int field here would silently erase.
 */
final readonly class CalendarPeriodCount
{
    public function __construct(
        public int|string $period,
        public int|string $count,
    ) {}
}
