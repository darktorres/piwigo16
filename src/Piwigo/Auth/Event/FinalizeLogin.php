<?php

declare(strict_types=1);

namespace Piwigo\Auth\Event;

use Piwigo\Auth\Projection\AuthUser;

/**
 * Typed event for the legacy `finalize_login` filter. No handler is
 * registered for it anywhere today. Lives under `Piwigo\Auth\Event\`, not
 * `Piwigo\Event\User\`, since it carries a real `Piwigo\Auth\Projection\
 * AuthUser` instance -- deptrac's L0Data layer (`Piwigo\Event\*`) may
 * depend on nothing. `$state` keeps the legacy plugin-facing array shape
 * (`can_login`/`reason`/`authenticated`) real third-party handlers
 * already expect, precisely typed rather than the reference's loose
 * `array`. Mutable on `$state`; `$userFound`/`$rememberMe` stay context.
 */
final class FinalizeLogin
{
    /**
     * @param array{can_login: bool, reason: ?string, authenticated: bool} $state
     */
    public function __construct(
        public array $state,
        public readonly ?AuthUser $userFound,
        public readonly bool $rememberMe,
    ) {}
}
