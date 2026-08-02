<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `login_success` filter (notify). No handler
 * is registered for it anywhere today.
 */
final readonly class LoginSuccess
{
    public function __construct(
        public string $username,
    ) {}
}
