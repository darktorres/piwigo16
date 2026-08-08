<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateRepository::findRateSummaries()}'s own per-element
 * row shape -- {@see \Piwigo\Rate\RateService::updateRatingScore()}'s real
 * (and only) consumer, keyed by element id in the repository's own return
 * map (`array<int, RateSummary>`), so this DTO itself carries no id.
 */
final readonly class RateSummary
{
    public function __construct(
        public int $rcount,
        public float $rsum,
    ) {}
}
