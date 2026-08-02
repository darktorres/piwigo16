<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for the legacy `loc_begin_index_category_thumbnails`
 * notification. No handler is registered for it anywhere today.
 */
final readonly class LocBeginIndexCategoryThumbnails
{
    /**
     * @param array<mixed> $categories
     */
    public function __construct(
        public array $categories,
    ) {}
}
