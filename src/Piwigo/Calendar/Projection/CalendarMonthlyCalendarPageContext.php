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
 * actually ran (mutually exclusive: at most one of $calendarBars/
 * $monthView is non-null; both null is also real -- buildMonthCalendar()
 * returns null for a month with no images at all).
 */
final readonly class CalendarMonthlyCalendarPageContext implements TemplatePageContext
{
    /**
     * @param ?list<CalendarBarEntry> $calendarBars
     */
    public function __construct(
        public ?array $calendarBars,
        public ?CalendarMonthView $monthView,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        if ($this->calendarBars !== null) {
            return [
                'chronology_calendar' => [
                    'calendar_bars' => array_map(static fn (CalendarBarEntry $entry): array => $entry->toArray(), $this->calendarBars),
                ],
            ];
        }

        if ($this->monthView !== null) {
            return [
                'chronology_calendar' => [
                    'month_view' => $this->monthView->toArray(),
                ],
            ];
        }

        return [
            'chronology_calendar' => [],
        ];
    }
}
