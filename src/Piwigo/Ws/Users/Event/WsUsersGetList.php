<?php

declare(strict_types=1);

namespace Piwigo\Ws\Users\Event;

/**
 * Typed event for the legacy `ws_users_getList` filter. No handler is
 * registered for it anywhere today. No context -- every real call site
 * passes only the users list. Co-located here from `Piwigo\Event\User\WsUsersGetList` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class WsUsersGetList
{
    /**
     * @param array<int, array<string, mixed>> $users
     */
    public function __construct(
        public array $users,
    ) {}
}
