<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `ws_users_getList` (dispatch).
 *
 * New in 2.6.2.
 *
 * Dispatched from: src/Piwigo/Ws/Method/UsersEndpoints.php
 */
final readonly class WsUsersGetList
{
    /**
     * @param array<mixed> $users
     */
    public function __construct(
        public array $users,
    ) {
    }

    /**
     * @param array<mixed> $users
     */
    public function withUsers(array $users): self
    {
        return new self($users);
    }
}
