<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * The post-credential-check login decision `AuthService::pwgLogin()` acts
 * on: whether to allow the login (`canLogin`), what to log on denial
 * (`reason`), and whether the session was already established by
 * something other than `pwgLogin()`'s own `logUser()` call
 * (`authenticated`). Overridable via `AuthService::__construct()`'s own
 * `$finalizeLoginOverride` param -- replaces the old `FinalizeLogin`
 * plugin event (P32 Stage A5 -- zero production listeners, and every real
 * auth-extension plugin in the surveyed 433-plugin corpus (two 2FA
 * plugins, one LDAP plugin) hooks the earlier, already-alive `TryLogUser`
 * event instead, none of them `finalize_login`). Always `null` in
 * production (`pwgLogin()` falls back to `canLogin: true, reason: null,
 * authenticated: false`, the same default the deleted event used to
 * dispatch with); tests inject a real decision directly instead of
 * registering a plugin handler.
 */
final readonly class FinalizeLoginDecision
{
    public function __construct(
        public bool $canLogin,
        public ?string $reason,
        public bool $authenticated,
    ) {}
}
