<?php

declare(strict_types=1);

namespace Piwigo\Auth\Event;

use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Typed event for the legacy `try_log_user` filter. Registered
 * (`AuthService::pwgLogin()`, wired from `RequestBootstrap.php`) -- the
 * only change-shape event under `Piwigo\Event\User\`, mutable on `$success`. `$password`
 * is nullable -- diverges from the reference's non-nullable `string` --
 * since both real callers (identification.php's raw POST body,
 * {@see \Piwigo\Controller\Api\SessionLoginController}'s optional request
 * field) can genuinely omit it.
 *
 * The password is stored wrapped in the engine's own `SensitiveParameterValue`
 * (not just a private property) -- confirmed live that `#[SensitiveParameter]`
 * only redacts the *parameter* at a function boundary, not an object property
 * built from it, and that `print_r()`/raw property enumeration (unlike
 * `var_dump()`) never call `__debugInfo()` at all: a `TryLogUser` instance
 * flowing through the event bus and into a captured stack trace's `args`
 * exposed the plaintext in the clear via either path, `private` or not,
 * until this wrapping (`SensitiveParameterValue` has no enumerable
 * properties of its own, so neither path finds anything to print). See
 * SensitiveParameterTest.php for the live proof.
 */
final class TryLogUser
{
    private readonly SensitiveParameterValue $passwordValue;

    public function __construct(
        public bool $success,
        public readonly string $username,
        #[SensitiveParameter]
        ?string $password,
        public readonly bool $rememberMe,
    ) {
        $this->passwordValue = new SensitiveParameterValue($password);
    }

    public function password(): ?string
    {
        $value = $this->passwordValue->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'success' => $this->success,
            'username' => $this->username,
            'password' => $this->password() === null ? null : str_repeat('*', 8),
            'rememberMe' => $this->rememberMe,
        ];
    }
}
