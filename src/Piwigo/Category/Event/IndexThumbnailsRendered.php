<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `loc_end_index_thumbnails` filter. No
 * handler is registered for it anywhere today. Mutable on
 * `$tplThumbnailsVar`; `$pictures` stays context. Renamed and co-located here from `Piwigo\Event\Location\LocEndIndexThumbnails` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class IndexThumbnailsRendered
{
    /**
     * @param array<mixed> $tplThumbnailsVar
     * @param array<mixed> $pictures
     */
    public function __construct(
        public array $tplThumbnailsVar,
        public readonly array $pictures,
    ) {}
}
