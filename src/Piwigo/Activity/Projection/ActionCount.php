<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * {@see \Piwigo\Activity\ActivityRepository::findActionCounts()}'s own row
 * shape -- number of logged actions per (object, action) pair, consumed by
 * `Admin\UserActivityPageRenderer`'s own activity-log filter list and
 * `Admin\PiwigoInfosSender`'s telemetry payload.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * `UserActivityPageRenderer` builds a Smarty-bound array from it with an
 * extra derived `value` key this DTO doesn't carry, so that caller
 * `toArray()`s each row before splicing it in.
 */
final readonly class ActionCount
{
    public function __construct(
        public string $object,
        public string $action,
        public int $counter,
    ) {}

    /**
     * @return array{object: string, action: string, counter: int}
     */
    public function toArray(): array
    {
        return [
            'object' => $this->object,
            'action' => $this->action,
            'counter' => $this->counter,
        ];
    }
}
