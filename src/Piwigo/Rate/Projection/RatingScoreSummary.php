<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateService::updateRatingScore()}'s own fixed
 * `{score, average, count}` result shape -- `score`/`average` are null when
 * the target element has no rates of its own (or none was targeted).
 */
final readonly class RatingScoreSummary
{
    public function __construct(
        public ?float $score,
        public ?float $average,
        public int $count,
    ) {}
}
