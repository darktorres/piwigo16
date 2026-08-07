<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

use Piwigo\Common\ValueObject\UserId;

/**
 * Typed event for the legacy `user_login` filter (notify). No handler is
 * registered for it anywhere today.
 */
final readonly class UserLogin
{
    public function __construct(
        public UserId $userId,
    ) {}
}
