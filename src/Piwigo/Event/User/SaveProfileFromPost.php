<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `save_profile_from_post` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class SaveProfileFromPost
{
    public function __construct(
        public int $userId,
    ) {}
}
