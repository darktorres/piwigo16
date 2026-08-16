<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

/**
 * Typed event for the legacy `delete_tags` notification. No handler is
 * registered for it anywhere today. Co-located here from `Piwigo\Event\Tag\DeleteTags` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class DeleteTags
{
    /**
     * @param list<int> $tagIds
     */
    public function __construct(
        public array $tagIds,
    ) {}
}
