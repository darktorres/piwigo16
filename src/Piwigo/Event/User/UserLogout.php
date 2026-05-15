<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `user_logout` (notify).
 *
 * New in 2.5
 *
 * Dispatched from: src/Piwigo/Users/AuthService.php
 */
final readonly class UserLogout
{
    public function __construct(
        public int $userId,
    ) {
    }
}
