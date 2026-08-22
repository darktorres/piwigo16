<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarMonthly::buildMonthCalendar()}'s own
 * `month_view` shape -- a grid of weeks, each a list of
 * {@see CalendarDayCell} rows. `$cellWidth`/`$cellHeight` come from
 * {@see \Piwigo\Image\SizingParams::$ideal_size}, still a plain `int[]`
 * two-element array on this branch (not yet converted to
 * {@see \Piwigo\Image\Dimensions}), so both are genuinely `int`.
 */
final readonly class CalendarMonthView
{
    /**
     * @param list<string> $wdayLabels
     * @param list<list<CalendarDayCell>> $weeks
     */
    public function __construct(
        public int $cellWidth,
        public int $cellHeight,
        public array $wdayLabels,
        public array $weeks,
    ) {}

    /**
     * @return array{CELL_WIDTH: int, CELL_HEIGHT: int, wday_labels: list<string>, weeks: list<list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        return [
            'CELL_WIDTH' => $this->cellWidth,
            'CELL_HEIGHT' => $this->cellHeight,
            'wday_labels' => $this->wdayLabels,
            'weeks' => array_map(
                static fn (array $week): array => array_map(static fn (CalendarDayCell $cell): array => $cell->toArray(), $week),
                $this->weeks
            ),
        ];
    }
}
