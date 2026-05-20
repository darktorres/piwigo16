<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * One (object, action, counter) bucket from the activity-table
 * GROUP BY queries that feed `TelemetryPayload::$activities`.
 *
 * Replaces the loose `array<string, mixed>` rows returned by
 * {@see \Piwigo\Activity\ActivityRepository::findUserActivityGroupCounts()}
 * and {@see \Piwigo\Activity\ActivityRepository::findSystemActivityGroupCounts()}.
 *
 * `$objectId` is set only for the system-scoped variant
 * (`object = 'system'`); the user-activity grouping leaves it null.
 */
final readonly class TelemetryActivityGroup
{
    public function __construct(
        public string $object,
        public ?int   $objectId,
        public string $action,
        public int    $counter,
    ) {
    }

    /**
     * Build from an associative row coming back from `fetchAllAssociative`.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $objectIdRaw = $row['object_id'] ?? null;
        return new self(
            object:   is_string($row['object'] ?? null) ? $row['object'] : '',
            objectId: is_numeric($objectIdRaw) ? (int) $objectIdRaw : null,
            action:   is_string($row['action'] ?? null) ? $row['action'] : '',
            counter:  is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
        );
    }
}
