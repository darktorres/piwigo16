<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for legacy `render_tag_url` (dispatch).
 *
 * Dispatched from: src/Piwigo/Admin/Tag/TagAdminService.php, src/Piwigo/Ws/Method/TagsEndpoints.php
 */
final readonly class RenderTagUrl
{
    public function __construct(
        public string $tagName,
    ) {
    }

    public function withTagName(string $tagName): self
    {
        return new self($tagName);
    }
}
