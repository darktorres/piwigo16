<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for the legacy `delete_tags` notification. No handler is
 * registered for it anywhere today.
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
