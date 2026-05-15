<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `finalize_login` (dispatch).
 *
 * Dispatched from: src/Piwigo/Users/AuthService.php
 */
final readonly class FinalizeLogin
{
    /**
     * @param array<mixed> $state
     */
    public function __construct(
        public array $state,
        public bool $userFound,
        public bool $rememberMe,
    ) {
    }

    /**
     * @param array<mixed> $state
     */
    public function withState(array $state): self
    {
        return new self($state, $this->userFound, $this->rememberMe);
    }
}
