<?php

declare(strict_types=1);

namespace Piwigo\Picture\Event;

/**
 * Typed event for the legacy `user_comment_insertion` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class UserCommentInsertion
{
    /**
     * @param array<mixed> $comm
     */
    public function __construct(
        public array $comm,
    ) {}
}
