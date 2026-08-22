<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

use Piwigo\Users\Projection\NewUserSummary;

/**
 * Typed event for the legacy `register_user` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class RegisterUser
{
    public function __construct(
        public NewUserSummary $user,
    ) {}
}
