<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/** (activity_day, object, action, activity_counter) row from ActivityRepository::findDailyActionCountsSince(). */
final readonly class DailyActionCountRow
{
    public function __construct(
        public string $activityDay,
        public string $object,
        public string $action,
        public int $activityCounter,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            activityDay:     is_string($row['activity_day'] ?? null) ? $row['activity_day'] : '',
            object:          is_string($row['object'] ?? null) ? $row['object'] : '',
            action:          is_string($row['action'] ?? null) ? $row['action'] : '',
            activityCounter: is_numeric($row['activity_counter'] ?? null) ? (int) $row['activity_counter'] : 0,
        );
    }
}
