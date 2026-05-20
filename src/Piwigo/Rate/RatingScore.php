<?php

declare(strict_types=1);

namespace Piwigo\Rate;

/** Rating summary for a single image — score, vote count, and running average. */
final readonly class RatingScore
{
    public function __construct(
        public float $score,
        public int $count,
        public float|null $average,
    ) {
    }
}
