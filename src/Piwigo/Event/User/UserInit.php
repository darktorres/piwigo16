<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `user_init` (notify).
 *
 * Dispatched from: src/Piwigo/Users/UserBootstrap.php
 */
final readonly class UserInit
{
    /**
     * @param array<mixed> $user
     */
    public function __construct(
        public array $user,
    ) {
    }
}
