<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `invalidate_user_cache` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Users/UserAdminService.php
 */
final readonly class InvalidateUserCache
{
    public function __construct(
        public bool $full,
    ) {
    }
}
