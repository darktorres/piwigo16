<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * Shared `history_summary` row shape for
 * {@see \Piwigo\History\HistoryRepository::findLastByType()},
 * {@see \Piwigo\History\HistoryRepository::findMonthlyRows()}, and
 * {@see \Piwigo\History\HistoryRepository::findDailyRowsForMonths()} --
 * all 3 select the identical `year`/`month`/`day`/`hour`/`nb_pages`
 * columns, filtered/ordered differently. All 3 feed
 * `Admin\StatsPageRenderer`'s own chart-data queries, which build/merge
 * synthetic filler rows of this same shape internally -- {@see
 * \Piwigo\History\HistoryService}'s own pass-through methods hand this
 * object straight through, and `StatsPageRenderer` reads it by property.
 */
final readonly class HistorySummaryRow
{
    public function __construct(
        public int $year,
        public ?int $month,
        public ?int $day,
        public ?int $hour,
        public int $nbPages,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\History\HistoryRepository::findLastByType()}-shaped row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            year: is_numeric($row['year'] ?? null) ? (int) $row['year'] : 0,
            month: is_numeric($row['month'] ?? null) ? (int) $row['month'] : null,
            day: is_numeric($row['day'] ?? null) ? (int) $row['day'] : null,
            hour: is_numeric($row['hour'] ?? null) ? (int) $row['hour'] : null,
            nbPages: is_numeric($row['nb_pages'] ?? null) ? (int) $row['nb_pages'] : 0,
        );
    }

    /**
     * @return array{year: int, month: ?int, day: ?int, hour: ?int, nb_pages: int}
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'hour' => $this->hour,
            'nb_pages' => $this->nbPages,
        ];
    }
}
