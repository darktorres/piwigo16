<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'chronology_calendar' template variable assigned by
 * {@see \Piwigo\Calendar\CalendarMonthly::generateCategoryContent()}
 * -- the same single key, filled by whichever of
 * buildGlobalCalendar()/buildYearCalendar()/buildMonthCalendar()
 * actually ran (mutually exclusive: at most one of a non-empty
 * $calendarBars and a non-null $monthView; neither is also real --
 * buildMonthCalendar() returns null for a month with no images at all).
 *
 * `$calendarBars` is a plain list rather than `?list`, because both of
 * its producers can legitimately hand back an empty one --
 * buildGlobalCalendar() falls through to `[]` for a scope with no dated
 * photos -- and month_calendar.latte only asks whether there is a bar to
 * draw. Keeping the nullable meant neither `!== null` nor `!== []` was a
 * correct guard on its own (P58-B2).
 */
final readonly class CalendarMonthlyCalendarPageContext implements TemplatePageContext
{
    /**
     * @param list<CalendarBarEntry> $calendarBars
     */
    public function __construct(
        public array $calendarBars,
        public ?CalendarMonthView $monthView,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'chronology_calendar' => new CalendarChronologyCalendar(
                calendarBars: $this->calendarBars,
                monthView: $this->monthView,
            ),
        ];
    }
}
