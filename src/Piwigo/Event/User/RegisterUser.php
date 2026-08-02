<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `register_user` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class RegisterUser
{
    /**
     * @param array{id: int, username: string, email: ?string} $user
     */
    public function __construct(
        public array $user,
    ) {}
}
