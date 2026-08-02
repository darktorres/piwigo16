<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `try_log_user` filter. Registered
 * (`AuthService::pwgLogin()`, wired from `RequestBootstrap.php`) -- the
 * only change-shape event in this batch, mutable on `$success`. `$password`
 * is nullable -- diverges from the reference's non-nullable `string` --
 * since both real callers (identification.php's raw POST body,
 * `pwg.session.login`'s optional WS param) can genuinely omit it.
 */
final class TryLogUser
{
    public function __construct(
        public bool $success,
        public readonly string $username,
        public readonly ?string $password,
        public readonly bool $rememberMe,
    ) {}
}
