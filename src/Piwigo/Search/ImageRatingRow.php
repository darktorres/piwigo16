<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** (id, rating_score) row from SearchRepository::findRatingsForFilter(). */
final readonly class ImageRatingRow
{
    public function __construct(
        public int        $id,
        public float|null $ratingScore,
    ) {
    }
}
