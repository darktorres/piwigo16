<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for the legacy `merge_tags` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class MergeTags
{
    /**
     * @param array<int, int> $mergeTagIds
     */
    public function __construct(
        public int $destinationTagId,
        public array $mergeTagIds,
    ) {}
}
