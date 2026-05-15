<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_end_index_category_thumbnails` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryCatsRenderer.php
 */
final readonly class LocEndIndexCategoryThumbnails
{
    /**
     * @param array<mixed> $tplThumbnailsVar
     */
    public function __construct(
        public array $tplThumbnailsVar,
    ) {
    }

    /**
     * @param array<mixed> $tplThumbnailsVar
     */
    public function withTplThumbnailsVar(array $tplThumbnailsVar): self
    {
        return new self($tplThumbnailsVar);
    }
}
