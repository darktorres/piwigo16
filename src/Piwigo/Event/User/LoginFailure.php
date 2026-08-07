<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

use Piwigo\Common\ValueObject\Username;

/**
 * Typed event for the legacy `login_failure` filter (notify). No handler
 * is registered for it anywhere today. `$username` is nullable -- this
 * fires on a failed login attempt, so the raw input may not even satisfy
 * `Username`'s own validation (empty, too long, control characters);
 * `Username::tryFrom()` at the dispatch site degrades to null instead of
 * throwing on such input.
 */
final readonly class LoginFailure
{
    public function __construct(
        public ?Username $username,
    ) {}
}
