<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarBase::$chronologyNavigationBars}'s own
 * row -- `$items` is set by `buildNavBar()`; `$previous`/`$next` are set
 * (or merged onto an existing `$items`-only row) by `buildNextPrev()`.
 * All 3 fields are independently optional -- a row can carry any subset.
 *
 * `$items` is a plain list, not `?list`: `month_calendar.latte` asks only
 * whether there is a nav item to draw (and falls back to a `&nbsp;` cell
 * if not), so a row built by `buildNextPrev()` alone (no items) and a
 * `buildNavBar()` row whose item set came out empty were one answer in two
 * spellings. The nullable bought only an `empty()` that had to cover both,
 * on the exact branch whose padding cells this campaign has already
 * mis-flipped once (P58-B2).
 */
final readonly class ChronologyNavBarRow
{
    /**
     * @param list<CalendarNavBarEntry> $items
     */
    public function __construct(
        public array $items = [],
        public ?CalendarNavAdjacent $previous = null,
        public ?CalendarNavAdjacent $next = null,
    ) {}
}
