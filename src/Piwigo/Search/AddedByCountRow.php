<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** (counter, added_by_id) row from SearchRepository::findAddedByForFilter(). */
final readonly class AddedByCountRow
{
    public function __construct(
        public int $counter,
        public int $addedById,
    ) {
    }
}
