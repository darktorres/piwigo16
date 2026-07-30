<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\ORM\EntityRepository;
use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserActivityLogEntry;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the activity domain: `activity` (an append-only
 * audit-ish log of admin/user actions app-wide, not to be confused with
 * the greenfield P18 AuditService/SEC-57, which is a separate, dedicated
 * tamper-evident log). Also serves admin/user_activity.php's own
 * dashboard read queries.
 *
 * @extends EntityRepository<ActivityEntity>
 */
final class ActivityRepository extends EntityRepository
{
    /**
     * @param list<array{
     *   object: string,
     *   objectId: int|string,
     *   action: string,
     *   performedBy: ?int,
     *   sessionIdx: string,
     *   ipAddress: ?IpAddress,
     *   occuredOn: string,
     *   details: array<string, mixed>,
     *   userAgent: ?string,
     * }> $rows
     */
    public function insertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $em = $this->getEntityManager();
        foreach ($rows as $row) {
            $em->persist(new ActivityEntity(
                object: $row['object'],
                objectId: is_numeric($row['objectId']) ? (int) $row['objectId'] : 0,
                action: $row['action'],
                performedBy: $row['performedBy'],
                sessionIdx: $row['sessionIdx'],
                ipAddress: $row['ipAddress'],
                occuredOn: $row['occuredOn'],
                details: $row['details'],
                userAgent: $row['userAgent'],
            ));
        }

        $em->flush();
    }

    /**
     * Number of logged actions per user, excluding object='system'. Stays
     * plain DBAL rather than DQL -- phpstan-doctrine mis-infers a nullable
     * scalar-selected column used in GROUP BY as always non-null (verified
     * live: `performed_by` genuinely comes back NULL here for a
     * non-'system' row whose acting user was later deleted, an
     * ON-DELETE-SET-NULL FK case distinct from the system-row NULL case
     * {@see findSystemObjectLogWithUsernames()} documents), which would
     * make the real `is_numeric()` filter below misreported as dead code.
     *
     * @return array<int, int> performed_by => count
     */
    public function countByUser(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
        return $this->findOneBy([], [
            'activityId' => 'ASC',
        ])?->occuredOn;
    }

    public function findMaxOccuredOn(): ?string
    {
        return $this->findOneBy([], [
            'activityId' => 'DESC',
        ])?->occuredOn;
    }

    /**
     * When $objectId (of type $object) was logged performing $action --
     * Admin\CatModifyPageRenderer's own "album created since" display.
     */
    public function findOccuredOnForObject(int $objectId, string $object, string $action): ?string
    {
        return $this->findOneBy([
            'objectId' => $objectId,
            'object' => $object,
            'action' => $action,
        ])?->occuredOn;
    }

    /**
     * Number of logged actions per (object, action) pair, excluding
     * object='system' and optionally restricted to a single object type.
     *
     * @return list<array{object: string, action: string, counter: int}>
     */
    public function findActionCounts(?string $objectFilter): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.object', 'a.action', 'COUNT(a.activityId) AS counter')
            ->where("a.object != 'system'")
            ->groupBy('a.action', 'a.object')
            ->orderBy('a.object', 'ASC');

        if ($objectFilter !== null) {
            $qb->andWhere('a.object = :objectFilter')
                ->setParameter('objectFilter', $objectFilter);
        }

        $rows = $qb->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'object' => $row['object'],
                'action' => $row['action'],
                'counter' => $row['counter'],
            ],
            $rows
        );
    }

    /**
     * Every activity-log line for object='user', joined with the acting
     * user's own username -- used only by the CSV export
     * (admin/user_activity.php's `type=download_logs`). $usernameColumn/
     * $idColumn are the configurable DB column names (see
     * \Piwigo\Config\CurrentConfig::userFields()), not user-controlled --
     * `users` is never ORM-mapped, so this join stays plain DBAL.
     *
     * `details` stays the raw JSON text here, not decoded to `?array` --
     * the CSV export writes it out as one opaque column value, unlike
     * {@see findSystemObjectLogWithUsernames()}'s own consumer, which does
     * structured `$details['key']` access and needs the real array.
     *
     * @return list<UserActivityLogEntry>
     */
    public function findUserObjectLogWithUsernames(string $usernameColumn, string $idColumn): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('activity_id', 'performed_by', 'object', 'object_id', 'action', 'ip_address', 'occured_on', 'details', 'u.' . $usernameColumn . ' AS username')
            ->from(Tables::activity(), 'a')
            ->innerJoin('a', Tables::users(), 'u', 'a.performed_by = u.' . $idColumn)
            ->where("a.object = 'user'")
            ->orderBy('a.activity_id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(UserActivityLogEntry::fromRow(...), $rows);
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
     * @return list<SystemActivityLogEntry>
     */
    public function findSystemObjectLogWithUsernames(string $usernameColumn, string $idColumn): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('activity_id', 'performed_by', 'object_id', 'action', 'occured_on', 'details', "IF(performed_by = 0 OR performed_by IS NULL, 'System', u." . $usernameColumn . ') AS username')
            ->from(Tables::activity(), 'a')
            ->leftJoin('a', Tables::users(), 'u', 'a.performed_by = u.' . $idColumn)
            ->where("a.object = 'system'")
            ->orderBy('a.activity_id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(SystemActivityLogEntry::fromRow(...), $rows);
    }

    /**
     * Paginated activity rows matching an already-built $whereClause --
     * Ws\PwgCore::getActivityList()'s own WS listing, one real caller.
     * $whereClause is an already-built, trusted SQL WHERE fragment
     * (starting with `WHERE`), same "caller composes trusted fragments"
     * contract used throughout this codebase.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginated(string $whereClause, int $limit, int $offset): array
    {
        $activityTable = Tables::activity();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    activity_id,
                    performed_by,
                    object,
                    object_id,
                    action,
                    session_idx,
                    ip_address,
                    occured_on,
                    details,
                    user_agent
                FROM {$activityTable}
                {$whereClause}
                ORDER BY activity_id DESC
                LIMIT {$limit} OFFSET {$offset}
                SQL);
    }

    /**
     * Per (day, object, action) counts among rows on/after $sinceDate --
     * Controller\Admin\IntroSubController's own dashboard "recent
     * activity" chart data.
     *
     * @return list<array{activity_day: string, object: string, action: string, counter: int}>
     */
    public function findDailyActionCountsSince(string $sinceDate): array
    {
        $activityTable = Tables::activity();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    DATE_FORMAT(occured_on, '%Y-%m-%d') AS activity_day,
                    object,
                    action,
                    COUNT(*) AS activity_counter
                FROM {$activityTable}
                WHERE occured_on >= :since_date
                GROUP BY activity_day, object, action
                SQL
                , [
                                'since_date' => $sinceDate,
                            ]);

        return array_map(
            static fn (array $row): array => [
                'activity_day' => is_string($row['activity_day']) ? $row['activity_day'] : '',
                'object' => is_string($row['object']) ? $row['object'] : '',
                'action' => is_string($row['action']) ? $row['action'] : '',
                'counter' => is_numeric($row['activity_counter']) ? (int) $row['activity_counter'] : 0,
            ],
            $rows
        );
    }

    /**
     * Every action/occured_on/details row for core (Piwigo\Core\
     * ActivitySystem::Core) update/autoupdate system-activity entries,
     * oldest first -- Admin\PiwigoInfosSender's own "version upgrade
     * history" telemetry field.
     *
     * @return list<array{action: string, occured_on: ?string, details: ?string}>
     */
    public function findCoreUpdateHistory(): array
    {
        $activityTable = Tables::activity();
        $activitySystemCore = \Piwigo\Core\ActivitySystem::Core;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    action,
                    occured_on,
                    details
                FROM {$activityTable}
                WHERE object = 'system'
                    AND object_id = {$activitySystemCore}
                    AND action IN ('update', 'autoupdate')
                ORDER BY activity_id ASC
                SQL);

        return array_map(
            static fn (array $row): array => [
                'action' => is_string($row['action']) ? $row['action'] : '',
                'occured_on' => is_string($row['occured_on'] ?? null) ? $row['occured_on'] : null,
                'details' => is_string($row['details'] ?? null) ? $row['details'] : null,
            ],
            $rows
        );
    }

    /**
     * Per (object_id, action) counts among object='system' rows --
     * Admin\PiwigoInfosSender's own telemetry "activities.system" bucket.
     * Unlike findSystemObjectLogWithUsernames() above, this doesn't join
     * `users` (only the counts matter here, not who performed each one).
     *
     * @return list<array{object: string, object_id: int, action: string, counter: int}>
     */
    public function findSystemActionCountsByObjectId(): array
    {
        $activityTable = Tables::activity();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    object,
                    object_id,
                    action,
                    COUNT(*) AS counter
                FROM {$activityTable}
                WHERE object = 'system'
                GROUP BY object, object_id, action
                SQL);

        return array_map(
            static fn (array $row): array => [
                'object' => is_string($row['object']) ? $row['object'] : '',
                'object_id' => is_numeric($row['object_id']) ? (int) $row['object_id'] : 0,
                'action' => is_string($row['action']) ? $row['action'] : '',
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
            ],
            $rows
        );
    }

    /**
     * Per non-browser `user_agent` counts and first/last-seen dates --
     * Admin\PiwigoInfosSender's own "which apps have been used" telemetry
     * breakdown. Excludes real browser traffic (Mozilla/5.x user agents).
     *
     * @return list<array{user_agent: ?string, counter: int, first_encounter: ?string, last_encounter: ?string}>
     */
    public function findUserAgentBreakdown(): array
    {
        $activityTable = Tables::activity();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    user_agent,
                    COUNT(*) AS counter,
                    MIN(occured_on) AS first_encounter,
                    MAX(occured_on) AS last_encounter
                FROM {$activityTable}
                WHERE user_agent NOT LIKE 'Mozilla/5%'
                GROUP BY user_agent
                SQL);

        return array_map(
            static fn (array $row): array => [
                'user_agent' => is_string($row['user_agent'] ?? null) ? $row['user_agent'] : null,
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
                'first_encounter' => is_string($row['first_encounter'] ?? null) ? $row['first_encounter'] : null,
                'last_encounter' => is_string($row['last_encounter'] ?? null) ? $row['last_encounter'] : null,
            ],
            $rows
        );
    }
}
