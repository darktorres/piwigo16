<?php

declare(strict_types=1);

namespace Piwigo\Picture\Event;

/**
 * Typed event for the legacy `render_comment_author` filter. No context --
 * every real call site passes only the author value. Co-located here from `Piwigo\Event\Template\RenderCommentAuthor` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderCommentAuthor
{
    public function __construct(
        public string $commentAuthor,
    ) {}
}
