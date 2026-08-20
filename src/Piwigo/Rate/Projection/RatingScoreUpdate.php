<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateRepository::updateRatingScores()}'s own fixed
 * `{id, ratingScore}` row shape -- one per image whose bayesian
 * `images.rating_score` is being recomputed.
 */
final readonly class RatingScoreUpdate
{
    public function __construct(
        public int $id,
        public float $ratingScore,
    ) {}
}
