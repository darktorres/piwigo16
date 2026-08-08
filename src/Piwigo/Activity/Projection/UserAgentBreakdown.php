<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findUserAgentBreakdown()}'s
 * own row shape -- {@see \Piwigo\Admin\PiwigoInfosSender}'s own "which apps
 * have been used" telemetry breakdown, its real (and only) consumer.
 *
 * `firstEncounter`/`lastEncounter` stay `?string` (not `SqlDateTime`) --
 * that repository method's own docblock documents why: `MIN()`/`MAX()`
 * around a custom-Typed column don't get the column's Type applied during
 * hydration, so these come back as plain driver strings.
 */
final readonly class UserAgentBreakdown
{
    public function __construct(
        public ?string $userAgent,
        public int $counter,
        public ?string $firstEncounter,
        public ?string $lastEncounter,
    ) {}
}
