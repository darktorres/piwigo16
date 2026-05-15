<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `save_profile_from_post` (notify).
 *
 * Dispatched from: src/Piwigo/Users/ProfileService.php
 */
final readonly class SaveProfileFromPost
{
    public function __construct(
        public int $userId,
    ) {
    }
}
