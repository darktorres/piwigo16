<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `create_virtual_category` notification. No
 * handler is registered for it anywhere today. Co-located here from `Piwigo\Event\Album\CreateVirtualCategory` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class CreateVirtualCategory
{
    /**
     * @param array<mixed> $category
     */
    public function __construct(
        public array $category,
    ) {}
}
