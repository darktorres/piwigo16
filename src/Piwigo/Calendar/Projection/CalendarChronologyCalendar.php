<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * `month_calendar.latte`'s own `chronology_calendar` variable, as
 * {@see CalendarMonthlyCalendarPageContext} assigns it.
 *
 * Exactly one of the two is set, and which one decides what the template
 * draws: `$calendarBars` is the all-years or whole-year accordion,
 * `$monthView` the day grid for a single month. Both are null on the third
 * path, where the calendar has nothing to show -- the context used to emit
 * an empty array there, which the template's `!empty()`/`isset()` guards
 * read identically to a null property (P58-A).
 */
final readonly class CalendarChronologyCalendar
{
    /**
     * @param list<CalendarBarEntry> $calendarBars
     */
    public function __construct(
        public array $calendarBars = [],
        public ?CalendarMonthView $monthView = null,
    ) {}
}
