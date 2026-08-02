<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for the legacy `loc_end_index_category_thumbnails`
 * filter. No handler is registered for it anywhere today.
 */
final readonly class LocEndIndexCategoryThumbnails
{
    /**
     * @param array<mixed> $tplThumbnailsVar
     */
    public function __construct(
        public array $tplThumbnailsVar,
    ) {}
}
