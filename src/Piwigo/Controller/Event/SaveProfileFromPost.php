<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

use Piwigo\Common\ValueObject\UserId;

/**
 * Typed event for the legacy `save_profile_from_post` notification. No
 * handler is registered for it anywhere today. Co-located here from `Piwigo\Event\User\SaveProfileFromPost` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class SaveProfileFromPost
{
    public function __construct(
        public UserId $userId,
    ) {}
}
