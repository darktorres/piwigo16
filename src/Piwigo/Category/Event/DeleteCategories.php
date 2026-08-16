<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `delete_categories` notification. No
 * handler is registered for it anywhere today. Co-located here from `Piwigo\Event\Album\DeleteCategories` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class DeleteCategories
{
    /**
     * @param array<int, int> $ids
     */
    public function __construct(
        public array $ids,
    ) {}
}
