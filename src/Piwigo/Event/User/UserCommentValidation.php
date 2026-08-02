<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `user_comment_validation` notification. No
 * handler is registered for it anywhere today. `$commentId` matches
 * `CommentService::validateComment()`'s own real unwrapped shape (a
 * single id, or a list of them) -- diverges from the reference's bare
 * `mixed`.
 */
final readonly class UserCommentValidation
{
    /**
     * @param int|list<int> $commentId
     */
    public function __construct(
        public int|array $commentId,
    ) {}
}
