<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_index_thumbnails_selection` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryDefaultRenderer.php
 *
 * Not present in tools/triggers_list.php — caught during B5 multi-line
 * dispatch audit.
 */
final readonly class LocIndexThumbnailsSelection
{
    /**
     * @param array<mixed> $selection
     */
    public function __construct(
        public array $selection,
    ) {
    }

    /**
     * @param array<mixed> $selection
     */
    public function withSelection(array $selection): self
    {
        return new self($selection);
    }
}
