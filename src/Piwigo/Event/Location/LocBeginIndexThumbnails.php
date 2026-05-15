<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_begin_index_thumbnails` (notify).
 *
 * Dispatched from: src/Piwigo/Category/CategoryDefaultRenderer.php
 */
final readonly class LocBeginIndexThumbnails
{
    /**
     * @param array<mixed> $pictures
     */
    public function __construct(
        public array $pictures,
    ) {
    }
}
