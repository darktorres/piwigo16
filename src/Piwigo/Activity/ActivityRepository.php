<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Piwigo\Core\ArrayHelper;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the activity domain: `activity` (an append-only
 * audit-ish log of admin/user actions app-wide, not to be confused with
 * the greenfield P18 AuditService/SEC-57, which is a separate, dedicated
 * tamper-evident log). Also serves admin/user_activity.php's own
 * dashboard read queries.
 */
final class ActivityRepository extends AbstractRepository
{
    /**
     * @param list<array{
     *   object: string,
     *   objectId: int|string,
     *   action: string,
     *   performedBy: ?int,
     *   sessionIdx: string,
     *   ipAddress: ?string,
     *   occuredOn: string,
     *   details: array<string, mixed>,
     *   userAgent: ?string,
     * }> $rows
     */
    public function insertMany(array $rows): void
    {
        foreach ($rows as $row) {
            $this->conn->createQueryBuilder()
                ->insert(Tables::activity())
                ->values([
                    'object' => ':object',
                    'object_id' => ':objectId',
                    'action' => ':action',
                    'performed_by' => ':performedBy',
                    'session_idx' => ':sessionIdx',
                    'ip_address' => ':ipAddress',
                    'occured_on' => ':occuredOn',
                    'details' => ':details',
                    'user_agent' => ':userAgent',
                ])
                ->setParameter('object', $row['object'])
                ->setParameter('objectId', $row['objectId'])
                ->setParameter('action', $row['action'])
                ->setParameter('performedBy', $row['performedBy'])
                ->setParameter('sessionIdx', $row['sessionIdx'])
                ->setParameter('ipAddress', $row['ipAddress'])
                ->setParameter('occuredOn', $row['occuredOn'])
                ->setParameter('details', json_encode($row['details']))
                ->setParameter('userAgent', $row['userAgent'])
                ->executeStatement();
        }
    }

    /**
     * Number of logged actions per user, excluding object='system'.
     *
     * @return array<int, int> performed_by => count
     */
    public function countByUser(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('performed_by', 'COUNT(*) AS counter')
            ->from(Tables::activity())
            ->where("object != 'system'")
            ->groupBy('performed_by')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['performed_by'])) {
                continue;
            }

            $counts[(int) $row['performed_by']] = is_numeric($row['counter']) ? (int) $row['counter'] : 0;
        }

        return $counts;
    }

    public function findMinOccuredOn(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('occured_on')
            ->from(Tables::activity())
            ->orderBy('activity_id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    public function findMaxOccuredOn(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('occured_on')
            ->from(Tables::activity())
            ->orderBy('activity_id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * Number of logged actions per (object, action) pair, excluding
     * object='system' and optionally restricted to a single object type.
     *
     * @return list<array{object: string, action: string, counter: int}>
     */
    public function findActionCounts(?string $objectFilter): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('object', 'action', 'COUNT(*) AS counter')
            ->from(Tables::activity())
            ->where("object != 'system'")
            ->groupBy('action', 'object')
            ->orderBy('object', 'ASC');

        if ($objectFilter !== null) {
            $qb->andWhere('object = :objectFilter')
                ->setParameter('objectFilter', $objectFilter);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'object' => is_string($row['object']) ? $row['object'] : '',
                'action' => is_string($row['action']) ? $row['action'] : '',
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
            ],
            $rows
        );
    }

    /**
     * Every activity-log line for object='user', joined with the acting
     * user's own username -- used only by the CSV export
     * (admin/user_activity.php's `type=download_logs`). $usernameColumn/
     * $idColumn are the configurable DB column names (see
     * \Piwigo\Config\CurrentConfig::userFields()), not user-controlled.
     *
     * `details` stays the raw JSON text here, not decoded to `?array` --
     * the CSV export writes it out as one opaque column value, unlike
     * {@see findSystemObjectLogWithUsernames()}'s own consumer, which does
     * structured `$details['key']` access and needs the real array.
     *
     * @return list<array{
     *   activity_id: int,
     *   performed_by: ?int,
     *   object: string,
     *   object_id: int,
     *   action: string,
     *   ip_address: ?string,
     *   occured_on: string,
     *   details: ?string,
     *   username: string,
     * }>
     */
    public function findUserObjectLogWithUsernames(string $usernameColumn, string $idColumn): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('activity_id', 'performed_by', 'object', 'object_id', 'action', 'ip_address', 'occured_on', 'details', 'u.' . $usernameColumn . ' AS username')
            ->from(Tables::activity(), 'a')
            ->innerJoin('a', Tables::users(), 'u', 'a.performed_by = u.' . $idColumn)
            ->where("a.object = 'user'")
            ->orderBy('a.activity_id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'activity_id' => is_numeric($row['activity_id']) ? (int) $row['activity_id'] : 0,
                'performed_by' => is_numeric($row['performed_by']) ? (int) $row['performed_by'] : null,
                'object' => is_string($row['object']) ? $row['object'] : '',
                'object_id' => is_numeric($row['object_id']) ? (int) $row['object_id'] : 0,
                'action' => is_string($row['action']) ? $row['action'] : '',
                'ip_address' => is_string($row['ip_address']) ? $row['ip_address'] : null,
                'occured_on' => is_string($row['occured_on']) ? $row['occured_on'] : '',
                'details' => is_string($row['details']) ? $row['details'] : null,
                'username' => is_string($row['username']) ? $row['username'] : '',
            ],
            $rows
        );
    }

    /**
     * admin/maintenance_sys.php's own log ('object' = 'system', core/
     * plugin/theme install/update/activate/etc.) -- a LEFT JOIN (not the
     * INNER JOIN findUserObjectLogWithUsernames() uses), since a
     * system-triggered row's performed_by is NULL (no known actor, e.g. a
     * plugin autoupdate that ran before $user was loaded -- see
     * ActivityService::record()'s own fix note) and has no matching user
     * row. 'username' renders as "System" when performed_by IS NULL (the
     * real, reachable "unknown actor" state under the column's ON DELETE
     * SET NULL foreign key -- 0 was the legacy sentinel before that FK
     * existed and is no longer a value this column can ever hold, confirmed
     * via a real ForeignKeyConstraintViolationException) -- 0 is kept in
     * the condition too, defensively, in case any pre-FK historical row
     * still holds it. A row whose performed_by references a since-deleted
     * user id specifically is impossible under this FK (deletion always
     * nulls it), so that's not a distinct case to preserve.
     *
     * `details` is decoded to `?array` here (unlike
     * {@see findUserObjectLogWithUsernames()}'s own raw string) -- its one
     * real consumer, {@see \Piwigo\Admin\Maintenance\ActivityLogEntryFormatter},
     * does structured `$details['key']` access and used to `unserialize()`
     * it itself.
     *
     * @return list<array{
     *   activity_id: int,
     *   performed_by: ?int,
     *   object_id: int,
     *   action: string,
     *   occured_on: string,
     *   details: array<string, mixed>|null,
     *   username: ?string,
     * }>
     */
    public function findSystemObjectLogWithUsernames(string $usernameColumn, string $idColumn): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('activity_id', 'performed_by', 'object_id', 'action', 'occured_on', 'details', "IF(performed_by = 0 OR performed_by IS NULL, 'System', u." . $usernameColumn . ') AS username')
            ->from(Tables::activity(), 'a')
            ->leftJoin('a', Tables::users(), 'u', 'a.performed_by = u.' . $idColumn)
            ->where("a.object = 'system'")
            ->orderBy('a.activity_id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'activity_id' => is_numeric($row['activity_id']) ? (int) $row['activity_id'] : 0,
                'performed_by' => is_numeric($row['performed_by']) ? (int) $row['performed_by'] : null,
                'object_id' => is_numeric($row['object_id']) ? (int) $row['object_id'] : 0,
                'action' => is_string($row['action']) ? $row['action'] : '',
                'occured_on' => is_string($row['occured_on']) ? $row['occured_on'] : '',
                'details' => is_string($row['details'])
                    ? array_filter(ArrayHelper::safeJsonDecode($row['details']), is_string(...), ARRAY_FILTER_USE_KEY)
                    : null,
                'username' => is_string($row['username']) ? $row['username'] : null,
            ],
            $rows
        );
    }
}
