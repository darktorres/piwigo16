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

    /**
     * @return array{U_HEAD: string, NB_IMAGES: int, HEAD_LABEL: int|string, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'U_HEAD' => $this->uHead,
            'NB_IMAGES' => $this->nbImages,
            'HEAD_LABEL' => $this->headLabel,
            'items' => array_map(static fn (CalendarNavBarEntry $entry): array => $entry->toArray(), $this->items),
        ];
    }
}
