<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

/**
 * Typed event for the legacy `render_tag_url` filter. No context -- every
 * real call site passes only the tag name. Co-located here from `Piwigo\Event\Tag\RenderTagUrl` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderTagUrl
{
    public function __construct(
        public string $tagName,
    ) {}
}
