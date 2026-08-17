<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

use Piwigo\Common\ValueObject\UserId;

/**
 * Typed event for the legacy `delete_user` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class DeleteUser
{
    public function __construct(
        public UserId $userId,
    ) {}
}
