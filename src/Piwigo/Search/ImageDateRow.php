<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** (id, date) row shared by findImageDatePostedRows() and findImageDateCreatedRows(). */
final readonly class ImageDateRow
{
    public function __construct(
        public int    $id,
        public string $date,
    ) {
    }
}
