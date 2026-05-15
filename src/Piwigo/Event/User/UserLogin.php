<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `user_login` (notify).
 *
 * New in 2.5
 *
 * Dispatched from: src/Piwigo/Users/AuthService.php
 */
final readonly class UserLogin
{
    public function __construct(
        public int $userId,
    ) {
    }
}
