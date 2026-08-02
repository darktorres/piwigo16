<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for the legacy `render_tag_name` filter. No handler is
 * registered for it anywhere today -- a pure information carrier.
 */
final readonly class RenderTagName
{
    /**
     * @param array<mixed> $tag
     */
    public function __construct(
        public string $tagName,
        public array $tag,
    ) {}
}
