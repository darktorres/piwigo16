<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

/**
 * Typed event for the legacy `render_tag_url` filter. No context -- every
 * real call site passes only the tag name.
 */
final class RenderTagUrl
{
    public function __construct(
        public string $tagName,
    ) {}
}
