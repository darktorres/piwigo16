<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `delete_user` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class DeleteUser
{
    public function __construct(
        public int $userId,
    ) {}
}
