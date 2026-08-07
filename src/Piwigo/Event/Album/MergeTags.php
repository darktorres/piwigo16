<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

use Piwigo\Common\ValueObject\TagId;

/**
 * Typed event for the legacy `merge_tags` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class MergeTags
{
    /**
     * @param list<TagId> $mergeTagIds
     */
    public function __construct(
        public TagId $destinationTagId,
        public array $mergeTagIds,
    ) {}
}
