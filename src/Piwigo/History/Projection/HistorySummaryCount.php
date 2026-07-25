<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * Typed row shape for
 * {@see \Piwigo\History\HistoryRepository::findSummaryRowsForHierarchy()}
 * (P17-23 Stage 1b, History domain) -- an existing `history_summary` row
 * anywhere in a year[/month[/day[/hour]]] hierarchy, used by
 * {@see \Piwigo\History\HistoryService::summarize()} to decide whether a
 * bucket needs an UPDATE (this row exists) or an INSERT (it doesn't).
 */
final readonly class HistorySummaryCount
{
    public function __construct(
        public int $year,
        public ?int $month,
        public ?int $day,
        public ?int $hour,
        public int $nbPages,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\History\HistoryRepository::findSummaryRowsForHierarchy()} row
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
