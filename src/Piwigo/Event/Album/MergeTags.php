<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `merge_tags` (notify).
 *
 * Dispatched from: src/Piwigo/Ws/Method/TagsEndpoints.php
 */
final readonly class MergeTags
{
    /**
     * @param array<mixed> $mergeTag
     */
    public function __construct(
        public int $destinationTagId,
        public array $mergeTag,
    ) {
    }
}
