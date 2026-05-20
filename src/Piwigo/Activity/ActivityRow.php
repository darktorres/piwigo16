<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * Typed row from the `activity` table. Replaces the loose
 * `array<string,mixed>` rows that `ActivityRepository::findActivityPage()`
 * and `findSystemActivityRows()` previously returned and that the
 * `pwg.activity.getList` handler + admin maintenance controller
 * destructured with per-field `is_string()`/`is_scalar()` checks.
 *
 * `details` is the raw JSON column value; consumers `json_decode` it.
 * `username` is set only for joined queries (`findSystemActivityRows`);
 * the page query leaves it null.
 *
 * `object` carries the string slot (ActivityObject enum value);
 * narrowing it to the enum here would break legacy ENUM-extension rows
 * that pre-date the closed case set.
 */
final readonly class ActivityRow
{
    public function __construct(
        public int     $activityId,
        public ?int    $performedBy,
        public string  $object,
        public ?string $objectId,
        public string  $action,
        public ?string $sessionIdx,
        public ?string $ipAddress,
        public string  $occuredOn,
        public ?string $details,
        public ?string $userAgent,
        public ?string $username = null,
    ) {
    }

    /**
     * Build from an associative row coming back from
     * `fetchAllAssociative()`. Per-field narrowing happens here so
     * call sites can read typed properties directly.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $performedByRaw = $row['performed_by'] ?? null;
        $objectIdRaw    = $row['object_id']    ?? null;

        return new self(
            activityId:  is_numeric($row['activity_id'] ?? null) ? (int) $row['activity_id'] : 0,
            performedBy: is_numeric($performedByRaw) ? (int) $performedByRaw : null,
            object:      is_string($row['object'] ?? null) ? $row['object'] : '',
            objectId:    is_scalar($objectIdRaw) ? (string) $objectIdRaw : null,
            action:      is_string($row['action'] ?? null) ? $row['action'] : '',
            sessionIdx:  is_string($row['session_idx'] ?? null) ? $row['session_idx'] : null,
            ipAddress:   is_string($row['ip_address'] ?? null) ? $row['ip_address'] : null,
            occuredOn:   is_string($row['occured_on'] ?? null) ? $row['occured_on'] : '',
            details:     is_string($row['details'] ?? null) ? $row['details'] : null,
            userAgent:   is_string($row['user_agent'] ?? null) ? $row['user_agent'] : null,
            username:    is_string($row['username'] ?? null) ? $row['username'] : null,
        );
    }
}
