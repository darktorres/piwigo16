<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findNextIdAndCount()}'s own row
 * shape -- `Ws\PwgCore::getMissingDerivatives()`'s own pagination-cursor
 * bootstrap.
 */
final readonly class NextIdCount
{
    public function __construct(
        public int $nextId,
        public int $count,
    ) {}
}
