<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

/**
 * Typed event for the legacy `register_user_check` filter. No handler is
 * registered for it anywhere today. `$errors` stays loosely `array<mixed>`
 * (not the real `list<string>` shape `UserService::registerUser()` itself
 * builds): the one real consumer already defensively filters each
 * element via `is_string()`, and a precise element type would make
 * PHPStan treat that filter as dead code (same reasoning as
 * GetAdminPluginMenuLinks/GetBatchManagerPrefilters from the
 * Admin/Integrity/Upload batch). Mutable on `$errors`; `$user` stays
 * context. Co-located here from `Piwigo\Users\Event\RegisterUserCheck` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RegisterUserCheck
{
    /**
     * @param array<mixed> $errors
     * @param array{username: string, password: string, email: ?string} $user
     */
    public function __construct(
        public array $errors,
        public readonly array $user,
    ) {}
}
