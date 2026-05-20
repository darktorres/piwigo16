<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** (MAX(id)+1, COUNT(*)) pair returned by ImageRepository::findMaxIdAndCount(). */
final readonly class MaxIdAndCount
{
    public function __construct(
        public int $nextId,
        public int $total,
    ) {
    }
}
