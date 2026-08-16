<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `loc_begin_index_category_thumbnails`
 * notification. No handler is registered for it anywhere today. Renamed and co-located here from `Piwigo\Event\Location\LocBeginIndexCategoryThumbnails` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class IndexCategoryThumbnailsRendering
{
    /**
     * @param array<mixed> $categories
     */
    public function __construct(
        public array $categories,
    ) {}
}
