<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One month in the search sidebar's date-filter tree, keyed by its own
 * `Y-m` under {@see DateFilterYear::$months} (P58-A).
 */
final readonly class DateFilterMonth
{
    /**
     * @param array<array-key, DateFilterDay> $days keyed by `Y-m-d`
     */
    public function __construct(
        public string $label,
        public int $count,
        public array $days,
    ) {}
}
