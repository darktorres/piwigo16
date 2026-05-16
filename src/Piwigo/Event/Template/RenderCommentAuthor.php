<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_comment_author` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/CommentsController.php, src/Piwigo/Picture/PictureCommentRenderer.php
 */
final readonly class RenderCommentAuthor
{
    public function __construct(
        public string $commentAuthor,
    ) {
    }
}
