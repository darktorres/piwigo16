<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

use Piwigo\Common\ValueObject\ActivityId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed row shape for
 * {@see \Piwigo\Activity\ActivityRepository::findUserObjectLogWithUsernames()}.
 * Not a plain `activity` row --
 * an `INNER JOIN` onto `users` (`username`, always present: the join
 * itself guarantees a matching user) restricted to `object = 'user'` rows,
 * feeding only {@see \Piwigo\Admin\UserActivityPageRenderer}'s CSV export.
 *
 * `details` stays the raw JSON text here, not decoded to `?array` -- the CSV
 * export writes it out as one opaque column value, unlike
 * {@see SystemActivityLogEntry}'s own consumer, which does structured
 * `$details['key']` access and needs the real array.
 *
 * ActivityEntity::$performedBy/$activityId are UserId-/ActivityId-typed --
 * `performedBy`/`activityId` here stay plain `?int`/`int` (Projection
 * convention), `fromRow()` narrows both via `instanceof`, not `is_numeric()`.
 */
final readonly class UserActivityLogEntry
{
    public function __construct(
        public int $activityId,
        public ?int $performedBy,
        public string $object,
        public int $objectId,
        public string $action,
        public ?IpAddress $ipAddress,
        public string $occuredOn,
        public ?string $details,
        public string $username,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Activity\ActivityRepository::findUserObjectLogWithUsernames()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            activityId: ($row['activity_id'] ?? null) instanceof ActivityId ? $row['activity_id']->value : 0,
            performedBy: ($row['performed_by'] ?? null) instanceof UserId ? $row['performed_by']->value : null,
            object: is_string($row['object'] ?? null) ? $row['object'] : '',
            objectId: is_numeric($row['object_id'] ?? null) ? (int) $row['object_id'] : 0,
            action: is_string($row['action'] ?? null) ? $row['action'] : '',
            ipAddress: is_string($row['ip_address'] ?? null) ? IpAddress::tryFrom($row['ip_address']) : null,
            occuredOn: ($row['occured_on'] ?? null) instanceof SqlDateTime
                ? $row['occured_on']->value
                : (is_string($row['occured_on'] ?? null) ? $row['occured_on'] : ''),
            details: is_string($row['details'] ?? null) ? $row['details'] : null,
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
        );
    }

    /**
     * @return array{activity_id: int, performed_by: ?int, object: string,
     *   object_id: int, action: string, ip_address: ?string, occured_on: string,
     *   details: ?string, username: string}
     */
    public function toArray(): array
    {
        return [
            'activity_id' => $this->activityId,
            'performed_by' => $this->performedBy,
            'object' => $this->object,
            'object_id' => $this->objectId,
            'action' => $this->action,
            'ip_address' => $this->ipAddress?->value,
            'occured_on' => $this->occuredOn,
            'details' => $this->details,
            'username' => $this->username,
        ];
    }
}
