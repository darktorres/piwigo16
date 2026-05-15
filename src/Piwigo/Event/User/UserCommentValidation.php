<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `user_comment_validation` (notify).
 *
 * $comment_id is an int or an array of int
 *
 * Dispatched from: src/Piwigo/Comment/CommentService.php
 */
final readonly class UserCommentValidation
{
    public function __construct(
        public mixed $commentId,
    ) {
    }
}
