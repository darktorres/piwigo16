<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `load_profile_in_template` (notify).
 *
 * Dispatched from: src/Piwigo/Users/ProfileService.php
 */
final readonly class LoadProfileInTemplate
{
    /**
     * @param array<mixed> $userdata
     */
    public function __construct(
        public array $userdata,
    ) {
    }
}
