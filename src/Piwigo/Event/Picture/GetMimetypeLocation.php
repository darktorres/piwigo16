<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `get_mimetype_location` filter. No handler
 * is registered for it anywhere today.
 */
final readonly class GetMimetypeLocation
{
    public function __construct(
        public string $location,
        public string $ext,
    ) {}
}
