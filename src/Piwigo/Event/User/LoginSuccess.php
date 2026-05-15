<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `login_success` (notify).
 *
 * Dispatched from: src/Piwigo/Users/AuthService.php
 */
final readonly class LoginSuccess
{
    public function __construct(
        public string $username,
    ) {
    }
}
