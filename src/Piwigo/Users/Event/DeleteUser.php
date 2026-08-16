<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

use Piwigo\Common\ValueObject\UserId;

/**
 * Typed event for the legacy `delete_user` notification. No handler is
 * registered for it anywhere today. Co-located here from `Piwigo\Event\User\DeleteUser` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class DeleteUser
{
    public function __construct(
        public UserId $userId,
    ) {}
}
