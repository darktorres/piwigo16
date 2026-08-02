<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `ws_users_getList` filter. No handler is
 * registered for it anywhere today.
 */
final readonly class WsUsersGetList
{
    /**
     * @param array<int, array<string, mixed>> $users
     */
    public function __construct(
        public array $users,
    ) {}
}
