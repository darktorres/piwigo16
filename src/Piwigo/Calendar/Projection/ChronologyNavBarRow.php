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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->items !== null) {
            $result['items'] = array_map(static fn (CalendarNavBarEntry $entry): array => $entry->toArray(), $this->items);
        }

        if ($this->previous !== null) {
            $result['previous'] = $this->previous->toArray();
        }

        if ($this->next !== null) {
            $result['next'] = $this->next->toArray();
        }

        return $result;
    }
}
