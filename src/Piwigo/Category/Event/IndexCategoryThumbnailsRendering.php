<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `loc_begin_index_category_thumbnails`
 * notification. No handler is registered for it anywhere today.
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
