<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Event;

/**
 * Typed event for the legacy `user_init` notification. No handler is
 * registered for it anywhere today. `$user` matches
 * `UserService::buildUser()`'s own `array<string, mixed>` return shape --
 * its one real dispatch site (`UserBootstrap::init()`) fires right after
 * reassigning `$user` to that method's result. Co-located here from `Piwigo\Event\User\UserInit` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class UserInit
{
    /**
     * @param array<string, mixed> $user
     */
    public function __construct(
        public array $user,
    ) {}
}
