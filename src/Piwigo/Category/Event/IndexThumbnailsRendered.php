<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

use Piwigo\Category\Projection\ImageThumbnail;

/**
 * Typed event for the legacy `loc_end_index_thumbnails` filter. No
 * handler is registered for it anywhere today. Mutable on
 * `$tplThumbnailsVar`; `$pictures` stays context.
 */
final class IndexThumbnailsRendered
{
    /**
     * @param list<ImageThumbnail> $tplThumbnailsVar
     * @param array<mixed> $pictures
     */
    public function __construct(
        public array $tplThumbnailsVar,
        public readonly array $pictures,
    ) {}
}
