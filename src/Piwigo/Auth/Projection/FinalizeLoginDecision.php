<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * The post-credential-check login decision `AuthService::pwgLogin()` acts
 * on: whether to allow the login (`canLogin`), what to log on denial
 * (`reason`), and whether the session was already established by
 * something other than `pwgLogin()`'s own `logUser()` call
 * (`authenticated`). Overridable via `AuthService::__construct()`'s own
 * `$finalizeLoginOverride` param, always `null` in production
 * (`pwgLogin()` falls back to `canLogin: true, reason: null,
 * authenticated: false`); tests inject a real decision directly instead
 * of registering a plugin handler.
 */
final readonly class FinalizeLoginDecision
{
    public function __construct(
        public bool $canLogin,
        public ?string $reason,
        public bool $authenticated,
    ) {}
}
