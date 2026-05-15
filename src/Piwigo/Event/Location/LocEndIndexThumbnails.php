<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_end_index_thumbnails` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryDefaultRenderer.php
 */
final readonly class LocEndIndexThumbnails
{
    /**
     * @param array<mixed> $tplThumbnailsVar
     * @param array<mixed> $pictures
     */
    public function __construct(
        public array $tplThumbnailsVar,
        public array $pictures,
    ) {
    }

    /**
     * @param array<mixed> $tplThumbnailsVar
     */
    public function withTplThumbnailsVar(array $tplThumbnailsVar): self
    {
        return new self($tplThumbnailsVar, $this->pictures);
    }
}
