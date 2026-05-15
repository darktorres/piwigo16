<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `login_failure_before_log_user` (notify).
 *
 * Dispatched from: src/Piwigo/Users/AuthService.php
 */
final readonly class LoginFailureBeforeLogUser
{
    public function __construct(
        public string $username,
    ) {
    }
}
