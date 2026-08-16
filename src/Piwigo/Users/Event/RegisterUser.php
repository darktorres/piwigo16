<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

/**
 * Typed event for the legacy `register_user` notification. No handler is
 * registered for it anywhere today. Co-located here from `Piwigo\Event\User\RegisterUser` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class RegisterUser
{
    /**
     * @param array{id: int, username: string, email: ?string} $user
     */
    public function __construct(
        public array $user,
    ) {}
}
