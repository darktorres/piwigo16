<?php

declare(strict_types=1);

namespace Piwigo\Auth\Event;

use Piwigo\Common\ValueObject\UserId;

/**
 * Typed event for the legacy `user_logout` filter (notify). No handler is
 * registered for it anywhere today. `$userId` is nullable -- diverges
 * from the reference's non-nullable `int` -- since its one real dispatch
 * site (`AuthService::logoutUser()`) reads `$_SESSION['pwg_uid'] ?? null`,
 * genuinely null for a session that requests logout without ever having
 * logged in.
 */
final readonly class UserLogout
{
    public function __construct(
        public ?UserId $userId,
    ) {}
}
