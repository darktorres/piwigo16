<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findCoreUpdateHistory()}'s own
 * row shape -- {@see \Piwigo\Admin\PiwigoInfosSender}'s own "version
 * upgrade history" telemetry field, its real (and only) consumer.
 *
 * `$details` stays a JSON-re-encoded `?string`, not a decoded array -- see
 * that repository method's own docblock for why (the one real consumer
 * only needs it to round-trip through `json_decode()`, not stay a typed
 * value here).
 */
final readonly class CoreUpdateHistoryRow
{
    public function __construct(
        public string $action,
        public ?string $occuredOn,
        public ?string $details,
    ) {}
}
