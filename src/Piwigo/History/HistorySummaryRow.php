<?php

declare(strict_types=1);

namespace Piwigo\History;

/**
 * One row from history_summary selecting (year, month, day, hour, nb_pages).
 * Returned by findSummaryByType(), findMonthlyRollups(), findDailyStatsForMonths().
 * All granularity fields are nullable — NULL means the row covers the broader period.
 */
final readonly class HistorySummaryRow
{
    public function __construct(
        public ?int $year,
        public ?int $month,
        public ?int $day,
        public ?int $hour,
        public int  $nbPages,
    ) {
    }
}
