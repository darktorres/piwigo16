<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

use Piwigo\Common\ValueObject\TagId;

/**
 * Typed event for the legacy `merge_tags` notification. No handler is
 * registered for it anywhere today. Its real dispatcher is
 * `Controller\Api\Tags\TagMergeController`; co-located under
 * `Piwigo\Tag\Event` (the domain it's about) rather than the calling
 * controller's own namespace -- see `docs/events-legacy-map.md`.
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
