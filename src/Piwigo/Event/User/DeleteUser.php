<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `delete_user` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Users/UserAdminService.php
 */
final readonly class DeleteUser
{
    public function __construct(
        public int $userId,
    ) {
    }
}
