<?php

declare(strict_types=1);

namespace Piwigo\Html\Event;

/**
 * Typed event for the legacy `render_comment_content` filter. No context --
 * every real call site passes only the content value.
 */
final class RenderCommentContent
{
    public function __construct(
        public string $commentContent,
    ) {}
}
