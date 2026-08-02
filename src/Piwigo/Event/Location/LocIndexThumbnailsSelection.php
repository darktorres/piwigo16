<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for the legacy `loc_index_thumbnails_selection` filter.
 * No handler is registered for it anywhere today. `$selection` stays
 * loosely `array<mixed>` -- the one real consumer already defensively
 * filters each element (is_int()/is_string()), and a precise element
 * type would make PHPStan treat that filter as dead code.
 */
final readonly class LocIndexThumbnailsSelection
{
    /**
     * @param array<mixed> $selection
     */
    public function __construct(
        public array $selection,
    ) {}
}
