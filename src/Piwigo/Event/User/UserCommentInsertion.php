<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `user_comment_insertion` (notify).
 *
 * Dispatched from: src/Piwigo/Picture/PictureCommentRenderer.php
 */
final readonly class UserCommentInsertion
{
    /**
     * @param array<mixed> $comm
     */
    public function __construct(
        public array $comm,
    ) {
    }
}
