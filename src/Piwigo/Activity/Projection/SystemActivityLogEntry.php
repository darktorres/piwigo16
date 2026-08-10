<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;

/**
 * Typed row shape for
 * {@see \Piwigo\Activity\ActivityRepository::findSystemObjectLogWithUsernames()}.
 * Not a plain `activity` row
 * -- a `LEFT JOIN` onto `users` (`username`, nullable: NULL
 * `performed_by` renders as "System" instead of a real username) plus a
 * narrower column list than the table has (no `object`/`session_idx`/
 * `ip_address`/`user_agent`) -- matches this repository method's own real
 * query shape, its one real consumer
 * ({@see \Piwigo\Admin\Maintenance\ActivityLogEntryFormatter}) needs exactly
 * this and nothing more. `details` is decoded to `?array` here (unlike
 * {@see UserActivityLogEntry}'s own raw string) -- that consumer does
 * structured `$details['key']` access.
 *
 * ActivityEntity::$performedBy is UserId-typed -- `performedBy` here
 * stays plain `?int` (Projection convention), `fromRow()` narrows via
 * `instanceof UserId`, not `is_numeric()`.
 */
final readonly class SystemActivityLogEntry
{
    public function __construct(
        public int $activityId,
        public ?int $performedBy,
        public int $objectId,
        public string $action,
        public string $occuredOn,
        /**
         * @var array<string, mixed>|null
         */
        public ?array $details,
        public ?string $username,
    ) {}

    /**
     * @param array<array-key, mixed> $row a {@see \Piwigo\Activity\ActivityRepository::findSystemObjectLogWithUsernames()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            activityId: is_numeric($row['activity_id'] ?? null) ? (int) $row['activity_id'] : 0,
            performedBy: ($row['performed_by'] ?? null) instanceof UserId ? $row['performed_by']->value : null,
            objectId: is_numeric($row['object_id'] ?? null) ? (int) $row['object_id'] : 0,
            action: is_string($row['action'] ?? null) ? $row['action'] : '',
            occuredOn: ($row['occured_on'] ?? null) instanceof SqlDateTime
                ? $row['occured_on']->value
                : (is_string($row['occured_on'] ?? null) ? $row['occured_on'] : ''),
            details: is_array($row['details'] ?? null)
                ? array_filter($row['details'], is_string(...), ARRAY_FILTER_USE_KEY)
                : null,
            // `username` is a CASE WHEN ... THEN 'System' ELSE u.username
            // END expression -- unlike a bare u.username reference, it's
            // uncertain whether Doctrine's array hydration attributes
            // u.username's custom Type to a computed/conditional SELECT
            // expression the same way, so this handles both a raw string
            // (the 'System' literal branch, or an un-Typed hydration) and
            // a real Username instance (the u.username branch, Typed).
            username: match (true) {
                ($row['username'] ?? null) instanceof Username => $row['username']->value,
                is_string($row['username'] ?? null) => $row['username'],
                default => null,
            },
        );
    }

    /**
     * @return array{activity_id: int, performed_by: ?int, object_id: int,
     *   action: string, occured_on: string, details: array<string, mixed>|null,
     *   username: ?string}
     */
    public function toArray(): array
    {
        return [
            'activity_id' => $this->activityId,
            'performed_by' => $this->performedBy,
            'object_id' => $this->objectId,
            'action' => $this->action,
            'occured_on' => $this->occuredOn,
            'details' => $this->details,
            'username' => $this->username,
        ];
    }
}
