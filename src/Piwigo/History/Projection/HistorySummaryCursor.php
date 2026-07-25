<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * Typed row shape for
 * {@see \Piwigo\History\HistoryRepository::findLastSummaryWithHistoryIdTo()}
 * (P17-23 Stage 1b, History domain) -- the most recently summarized
 * `history_summary` row that has a `history_id_to` cursor, used by
 * {@see \Piwigo\History\HistoryService::summarize()} to resume from where
 * the last summarization run left off.
 */
final readonly class HistorySummaryCursor
{
    public function __construct(
        public int $year,
        public ?int $month,
        public ?int $day,
        public ?int $hour,
        public int $historyIdTo,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\History\HistoryRepository::findLastSummaryWithHistoryIdTo()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            year: is_numeric($row['year'] ?? null) ? (int) $row['year'] : 0,
            month: is_numeric($row['month'] ?? null) ? (int) $row['month'] : null,
            day: is_numeric($row['day'] ?? null) ? (int) $row['day'] : null,
            hour: is_numeric($row['hour'] ?? null) ? (int) $row['hour'] : null,
            historyIdTo: is_numeric($row['history_id_to'] ?? null) ? (int) $row['history_id_to'] : 0,
        );
    }

    /**
     * @return array{year: int, month: ?int, day: ?int, hour: ?int, history_id_to: int}
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'hour' => $this->hour,
            'history_id_to' => $this->historyIdTo,
        ];
    }
}
