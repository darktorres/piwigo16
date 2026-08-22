<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

use Piwigo\Users\User;

/**
 * Typed event for the legacy `load_profile_in_template` notification.
 * No handler is registered for it anywhere today.
 */
final readonly class LoadProfileInTemplate
{
    public function __construct(
        public User $user,
    ) {}
}
