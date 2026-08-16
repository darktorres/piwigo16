<?php

declare(strict_types=1);

namespace Piwigo\Html\Event;

/**
 * Typed event for the legacy `render_comment_content` filter. No context --
 * every real call site passes only the content value. Co-located here from `Piwigo\Event\Template\RenderCommentContent` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderCommentContent
{
    public function __construct(
        public string $commentContent,
    ) {}
}
