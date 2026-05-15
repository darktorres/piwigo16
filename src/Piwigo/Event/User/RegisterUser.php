<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `register_user` (notify).
 *
 * Dispatched from: src/Piwigo/Users/UserService.php
 */
final readonly class RegisterUser
{
    /**
     * @param array<mixed> $user
     */
    public function __construct(
        public array $user,
    ) {
    }
}
