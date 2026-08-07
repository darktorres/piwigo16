<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

use Piwigo\Common\ValueObject\Username;

/**
 * Typed event for the legacy `login_success` filter (notify). No handler
 * is registered for it anywhere today. `$username` is nullable -- built
 * via `Username::tryFrom()` at every dispatch site rather than trusted
 * as already-valid, same defensive stance as `Users\User::$username`.
 */
final readonly class LoginSuccess
{
    public function __construct(
        public ?Username $username,
    ) {}
}
