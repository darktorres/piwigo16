<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `register_user_check` (dispatch).
 *
 * Dispatched from: src/Piwigo/Users/UserService.php
 */
final readonly class RegisterUserCheck
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $user
     */
    public function __construct(
        public array $errors,
        public array $user,
    ) {
    }

    /**
     * @param list<string> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($errors, $this->user);
    }
}
