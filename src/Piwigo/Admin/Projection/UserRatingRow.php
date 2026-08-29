<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One user's row in the rating-per-user table, frozen by
 * {@see \Piwigo\Admin\UserRatingAccumulator::freeze()} and rendered by
 * `rating_user.latte`.
 *
 * The user's own name is not a field: it is the key this row is stored
 * under, which is what `rating_user.latte` renders in the first
 * column.
 *
 * `$cdTop` is null when none of this user's rated elements is in the
 * top-rated set, i.e. when there was nothing to average. It used to be
 * `''` for that case, which the template tests with `!empty()` -- null
 * reads the same way there, and so does a real 0.0.
 */
final readonly class UserRatingRow
{
    /**
     * @param array<int, list<int>> $rates element ids keyed by the score
     *   they were given, one bucket per configured rate item (an
     *   unused score keeps its empty bucket, which is what makes the
     *   table's columns line up with the header)
     */
    public function __construct(
        public int $uid,
        public string $aid,
        public string $firstDate,
        public string $lastDate,
        public int $count,
        public float $avg,
        public float $cv,
        public float $cd,
        public ?float $cdTop,
        public array $rates,
    ) {}
}
