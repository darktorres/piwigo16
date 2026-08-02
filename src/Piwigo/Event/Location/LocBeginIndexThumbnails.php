<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for the legacy `loc_begin_index_thumbnails` notification.
 * No handler is registered for it anywhere today.
 */
final readonly class LocBeginIndexThumbnails
{
    /**
     * @param array<mixed> $pictures
     */
    public function __construct(
        public array $pictures,
    ) {}
}
