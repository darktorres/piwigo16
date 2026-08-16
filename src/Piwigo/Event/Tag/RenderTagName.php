<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for the legacy `render_tag_name` filter. No handler is
 * registered for it anywhere today -- a pure information carrier.
 * Mutable on `$tagName`; `$tag` stays context.
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
