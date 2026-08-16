<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `get_element_metadata_available` filter.
 * No handler is registered for it anywhere today. Mutable on
 * `$available`; `$picture` stays context. Co-located here from `Piwigo\Event\Picture\GetElementMetadataAvailable` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetElementMetadataAvailable
{
    /**
     * @param array<mixed> $picture
     */
    public function __construct(
        public bool $available,
        public readonly array $picture,
    ) {}
}
