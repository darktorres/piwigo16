<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `load_profile_in_template` notification.
 * No handler is registered for it anywhere today.
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
