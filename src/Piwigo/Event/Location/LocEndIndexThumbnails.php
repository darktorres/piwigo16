<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for the legacy `loc_end_index_thumbnails` filter. No
 * handler is registered for it anywhere today. Mutable on
 * `$tplThumbnailsVar`; `$pictures` stays context.
 */
final class LocEndIndexThumbnails
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
