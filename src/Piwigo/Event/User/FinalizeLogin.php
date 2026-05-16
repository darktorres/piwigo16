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
     * @param array<string, mixed> $userFound
     */
    public function __construct(
        public array $state,
        public array $userFound,
        public bool $rememberMe,
    ) {
    }
}
