<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarMonthly::buildGlobalCalendar()}/
 * {@see \Piwigo\Calendar\CalendarMonthly::buildYearCalendar()}'s own
 * shared `calendar_bars` row shape.
 */
final readonly class CalendarBarEntry
{
    /**
     * @param list<CalendarNavBarEntry> $items
     */
    public function __construct(
        public string $uHead,
        public int $nbImages,
        public int|string $headLabel,
        public array $items,
    ) {}
}
