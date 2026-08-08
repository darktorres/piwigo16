<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findDailyActionCountsSince()}'s
 * own row shape -- {@see \Piwigo\Controller\Admin\IntroSubController}'s own
 * "recent activity" dashboard chart data, its real (and only) consumer.
 */
final readonly class DailyActionCount
{
    public function __construct(
        public string $activityDay,
        public string $object,
        public string $action,
        public int $counter,
    ) {}
}
