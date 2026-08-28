<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarBase::$chronologyNavigationBars}'s own
 * row -- `$items` is set by `buildNavBar()`; `$previous`/`$next` are set
 * (or merged onto an existing `$items`-only row) by `buildNextPrev()`.
 * All 3 fields are independently optional -- a row can carry any subset.
 */
final readonly class ChronologyNavBarRow
{
    /**
     * @param ?list<CalendarNavBarEntry> $items
     */
    public function __construct(
        public ?array $items = null,
        public ?CalendarNavAdjacent $previous = null,
        public ?CalendarNavAdjacent $next = null,
    ) {}
}
