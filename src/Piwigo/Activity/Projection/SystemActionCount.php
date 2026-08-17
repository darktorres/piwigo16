<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findSystemActionCountsByObjectId()}'s
 * own row shape -- {@see \Piwigo\Admin\PiwigoInfosSender}'s own
 * "activities.system" telemetry bucket, its real (and only) consumer.
 *
 * $systemScope is an {@see \Piwigo\Core\ActivitySystem} constant
 * (`Core`/`Plugin`/`Theme`), not a row id -- see Version20260815230000
 * for the `object_id`/`systemScope` split. Nullable because the column
 * is: a row predating that split, or written by something that bypassed
 * ActivityService, has none.
 */
final readonly class SystemActionCount
{
    public function __construct(
        public string $object,
        public ?int $systemScope,
        public string $action,
        public int $counter,
    ) {}
}
