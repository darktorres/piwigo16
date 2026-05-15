<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_begin_index_category_thumbnails` (notify).
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php
 */
final readonly class LocBeginIndexCategoryThumbnails
{
    /**
     * @param array<mixed> $categories
     */
    public function __construct(
        public array $categories,
    ) {
    }
}
