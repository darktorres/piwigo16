<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

use Piwigo\Comment\CommentModerationAction;

/**
 * Typed event for legacy `user_comment_check` (dispatch).
 *
 * use this trigger to add conditions on comment validation
 *
 * Dispatched from: src/Piwigo/Comment/CommentService.php
 */
final readonly class UserCommentCheck
{
    /**
     * @param array<mixed> $comm
     */
    public function __construct(
        public CommentModerationAction $commentAction,
        public array $comm,
    ) {
    }
}
