<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findSystemActionCountsByObjectId()}'s
 * own row shape -- {@see \Piwigo\Admin\PiwigoInfosSender}'s own
 * "activities.system" telemetry bucket, its real (and only) consumer.
 */
final readonly class SystemActionCount
{
    public function __construct(
        public string $object,
        public int $objectId,
        public string $action,
        public int $counter,
    ) {}
}
