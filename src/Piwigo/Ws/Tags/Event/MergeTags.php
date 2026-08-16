<?php

declare(strict_types=1);

namespace Piwigo\Ws\Tags\Event;

use Piwigo\Common\ValueObject\TagId;

/**
 * Typed event for the legacy `merge_tags` notification. No handler is
 * registered for it anywhere today. Co-located here from `Piwigo\Event\Album\MergeTags` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
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
