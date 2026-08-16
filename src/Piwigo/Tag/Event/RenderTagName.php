<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

/**
 * Typed event for the legacy `render_tag_name` filter. No handler is
 * registered for it anywhere today -- a pure information carrier.
 * Mutable on `$tagName`; `$tag` stays context. Co-located here from `Piwigo\Event\Tag\RenderTagName` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderTagName
{
    /**
     * @param array<mixed> $tag
     */
    public function __construct(
        public string $tagName,
        public readonly array $tag,
    ) {}
}
