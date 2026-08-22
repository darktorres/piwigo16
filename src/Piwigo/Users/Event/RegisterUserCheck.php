<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

use Piwigo\Users\Projection\RegistrationCandidate;

/**
 * Typed event for the legacy `register_user_check` filter. No handler is
 * registered for it anywhere today. `$errors` stays loosely `array<mixed>`
 * (not the real `list<string>` shape `UserService::registerUser()` itself
 * builds): the one real consumer already defensively filters each
 * element via `is_string()`, and a precise element type would make
 * PHPStan treat that filter as dead code (same reasoning as
 * GetAdminPluginMenuLinks/GetBatchManagerPrefilters from the
 * Admin/Integrity/Upload batch). Mutable on `$errors`; `$user` stays
 * context.
 */
final class RegisterUserCheck
{
    /**
     * @param array<mixed> $errors
     */
    public function __construct(
        public array $errors,
        public readonly RegistrationCandidate $user,
    ) {}
}
