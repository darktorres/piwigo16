<?php

declare(strict_types=1);

namespace Piwigo\Auth\Event;

/**
 * Typed event for the legacy `try_log_user` filter. Registered
 * (`AuthService::pwgLogin()`, wired from `RequestBootstrap.php`) -- the
 * only change-shape event under `Piwigo\Event\User\`, mutable on `$success`. `$password`
 * is nullable -- diverges from the reference's non-nullable `string` --
 * since both real callers (identification.php's raw POST body,
 * `pwg.session.login`'s optional WS param) can genuinely omit it. Co-located here from `Piwigo\Event\User\TryLogUser` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class TryLogUser
{
    public function __construct(
        public bool $success,
        public readonly string $username,
        public readonly ?string $password,
        public readonly bool $rememberMe,
    ) {}

    /**
     * `#[\SensitiveParameter]` only redacts scalar/array function
     * parameters, not object properties, so a `TryLogUser` instance
     * flowing through the event bus and into a stack trace's captured
     * arguments still exposes `$password` unless this hooks the object's
     * own debug-output path -- var_dump()-family serialization only,
     * same redaction convention as Config\CurrentConfig::all().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'success' => $this->success,
            'username' => $this->username,
            'password' => $this->password === null ? null : str_repeat('*', 8),
            'rememberMe' => $this->rememberMe,
        ];
    }
}
