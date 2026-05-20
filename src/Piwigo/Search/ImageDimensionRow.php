<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** (id, width, height) row from SearchRepository::findRatiosForFilter(). */
final readonly class ImageDimensionRow
{
    public function __construct(
        public int $id,
        public int $width,
        public int $height,
    ) {
    }
}
