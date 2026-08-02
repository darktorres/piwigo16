<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `user_comment_check` filter. Registered
 * (`CommentService::checkForSpam()`, wired from
 * `RequestBootstrap.php`) -- mutable on `$commentAction`. The reference
 * types `$commentAction` as a `CommentModerationAction` enum, but that
 * type doesn't exist on this branch yet -- this branch's own real
 * handler (`checkForSpam()`) works on a plain string, so that's what
 * this carries instead. `$comm` stays loosely `array<string, mixed>`,
 * matching both real dispatch sites
 * (`CommentService::insertComment()`/`updateComment()`) -- the latter's
 * own docblock already documents this as deliberately generic since its
 * own defensive is_scalar()/is_string() narrowing treats every field as
 * untrusted regardless.
 */
final class UserCommentCheck
{
    /**
     * @param array<string, mixed> $comm
     */
    public function __construct(
        public string $commentAction,
        public readonly array $comm,
    ) {}
}
