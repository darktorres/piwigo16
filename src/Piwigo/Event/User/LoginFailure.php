<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `login_failure` filter (notify). No handler
 * is registered for it anywhere today.
 */
final readonly class LoginFailure
{
    public function __construct(
        public string $username,
    ) {}
}
