<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `load_profile_in_template` notification.
 * No handler is registered for it anywhere today. Co-located here from `Piwigo\Event\User\LoadProfileInTemplate` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class LoadProfileInTemplate
{
    /**
     * @param array<string, mixed> $userdata
     */
    public function __construct(
        public array $userdata,
    ) {}
}
