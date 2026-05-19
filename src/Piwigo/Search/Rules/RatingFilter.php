<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `ratings` saved-search filter — list of integer rating buckets
 * (0..5) the image's `rating_score` must fall into. Bucket 0 = "not
 * yet rated" (rating_score IS NULL); 1..5 = exclusive upper-bound
 * range against rating_score.
 */
final readonly class RatingFilter
{
    /** @param list<int> $buckets */
    public function __construct(public array $buckets)
    {
    }

    /** @param array<int|string, mixed> $raw  flat list of rating bucket codes */
    public static function fromArray(array $raw): ?self
    {
        $buckets = [];
        foreach ($raw as $bucket) {
            if (is_numeric($bucket)) {
                $buckets[] = (int) $bucket;
            }
        }
        return $buckets === [] ? null : new self($buckets);
    }
}
