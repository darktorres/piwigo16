<?php

declare(strict_types=1);

namespace Piwigo\Rate;

/** Count and average rating for a single image element. */
final readonly class RateStats
{
    public function __construct(
        public int $count,
        public float|null $average,
    ) {
    }
}
