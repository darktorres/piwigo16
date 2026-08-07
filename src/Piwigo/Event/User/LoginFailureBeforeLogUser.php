<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

use Piwigo\Common\ValueObject\Username;

/**
 * Typed event for the legacy `login_failure_before_log_user` filter
 * (notify). No handler is registered for it anywhere today. `$username`
 * is nullable -- same reasoning as `LoginFailure::$username`, a failed
 * login attempt's raw input may not satisfy `Username`'s own validation.
 */
final readonly class LoginFailureBeforeLogUser
{
    public function __construct(
        public ?Username $username,
    ) {}
}
