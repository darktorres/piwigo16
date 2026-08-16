<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

/**
 * Typed event for the legacy `get_mimetype_location` filter. No handler
 * is registered for it anywhere today. Mutable on `$location`; `$ext`
 * stays context. Co-located here from `Piwigo\Event\Picture\GetMimetypeLocation` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetMimetypeLocation
{
    public function __construct(
        public string $location,
        public readonly string $ext,
    ) {}
}
