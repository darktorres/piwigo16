<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

use Piwigo\Common\ValueObject\TagId;

/**
 * Typed event for the legacy `merge_tags` notification. No handler is
 * registered for it anywhere today. Originally co-located from
 * `Piwigo\Event\Album\MergeTags` into `Piwigo\Ws\Tags\Event` (P32 Stage
 * A5 -- see `docs/events-legacy-map.md`); moved again here when the WS
 * layer itself was deleted (P27) -- its real dispatcher is now
 * `Controller\Api\Tags\TagMergeController`, so `Piwigo\Tag\Event`
 * (the domain it's about) is the correct co-location target per that
 * same P32 principle, not the calling controller's own namespace.
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
