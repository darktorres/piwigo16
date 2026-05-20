<?php

declare(strict_types=1);

namespace Piwigo\History;

/** One row from findHourlyGroupingAfterId() — a date/hour bucket with min/max history ids and a page count. */
final readonly class HourlyGroupingRow
{
    public function __construct(
        public string $date,
        public int    $hour,
        public int    $minId,
        public int    $maxId,
        public int    $nbPages,
    ) {
    }
}
