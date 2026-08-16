<?php

declare(strict_types=1);

namespace Piwigo\Comment\Event;

/**
 * Typed event for the legacy `user_comment_validation` notification. No
 * handler is registered for it anywhere today. `$commentId` matches
 * `CommentService::validateComment()`'s own real unwrapped shape (a
 * single id, or a list of them) -- diverges from the reference's bare
 * `mixed`. Co-located here from `Piwigo\Event\User\UserCommentValidation` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
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
