<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `try_log_user` (dispatch).
 *
 * New in 2.5. Used by identification form to check user credentials. If success is true, another login method already succeeded. Return true if your method succeeds.
 *
 * Dispatched from: src/Piwigo/Users/AuthService.php
 */
final readonly class TryLogUser
{
    public function __construct(
        public bool $success,
        public string $username,
        public string $password,
        public bool $rememberMe,
    ) {
    }

    public function withSuccess(bool $success): self
    {
        return new self($success, $this->username, $this->password, $this->rememberMe);
    }
}
