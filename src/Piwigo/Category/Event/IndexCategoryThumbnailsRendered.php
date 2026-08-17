<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `loc_end_index_category_thumbnails`
 * filter. No handler is registered for it anywhere today. No context --
 * every real call site passes only the thumbnails list.
 */
final class IndexCategoryThumbnailsRendered
{
    /**
     * @param array<mixed> $tplThumbnailsVar
     */
    public function __construct(
        public array $tplThumbnailsVar,
    ) {}
}
