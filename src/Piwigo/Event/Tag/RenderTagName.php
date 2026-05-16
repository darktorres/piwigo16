<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for legacy `render_tag_name` (dispatch).
 *
 * Dispatched from: src/Piwigo/Admin/Tag/TagAdminService.php, src/Piwigo/Tag/TagService.php, src/Piwigo/Controller/Admin/MiscController.php, src/Piwigo/Search/SearchService.php
 */
final readonly class RenderTagName
{
    /**
     * @param array<mixed> $tag
     */
    public function __construct(
        public string $tagName,
        public array $tag,
    ) {
    }
}
