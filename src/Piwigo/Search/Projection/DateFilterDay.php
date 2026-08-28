<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One day in the search sidebar's date-filter tree, keyed by its own
 * `Y-m-d` date under {@see DateFilterMonth::$days} (P58-A).
 */
final readonly class DateFilterDay
{
    public function __construct(
        public string $label,
        public int $count,
    ) {}
}
