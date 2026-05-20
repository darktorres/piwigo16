<?php

declare(strict_types=1);

namespace Piwigo\History;

/**
 * Full history_summary row (SELECT *) returned by findSummariesAtTime().
 * Also used as the update payload in updateSummaryBatch() — nb_pages and
 * historyIdTo are recomputed before the batch write.
 */
final readonly class HistorySummaryDetail
{
    public function __construct(
        public ?int $year,
        public ?int $month,
        public ?int $day,
        public ?int $hour,
        public int  $nbPages,
        public ?int $historyIdTo,
    ) {
    }
}
