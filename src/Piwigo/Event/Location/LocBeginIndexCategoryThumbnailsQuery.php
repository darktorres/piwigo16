<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_begin_index_category_thumbnails_query` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php
 */
final readonly class LocBeginIndexCategoryThumbnailsQuery
{
    public function __construct(
        public string $query,
    ) {
    }
}
