<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `render_comment_content` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/CommentsController.php, src/Piwigo/Picture/PictureCommentRenderer.php
 */
final readonly class RenderCommentContent
{
    public function __construct(
        public string $commentContent,
    ) {
    }
}
