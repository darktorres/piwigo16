<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

use Piwigo\Controller\Projection\PictureElement;

/**
 * Typed event for the legacy `get_element_metadata_available` filter.
 * No handler is registered for it anywhere today. Mutable on
 * `$available`; `$picture` stays context.
 */
final class GetElementMetadataAvailable
{
    public function __construct(
        public bool $available,
        public readonly PictureElement $picture,
    ) {}
}
