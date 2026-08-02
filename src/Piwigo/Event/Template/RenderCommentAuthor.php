<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for the legacy `render_comment_author` filter. No context --
 * every real call site passes only the author value.
 */
final class RenderCommentAuthor
{
    public function __construct(
        public string $commentAuthor,
    ) {}
}
