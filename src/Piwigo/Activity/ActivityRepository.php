<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserActivityLogEntry;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;

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
     * Number of logged actions per user, excluding object='system'.
     *
     * Item 14 DQL audit: converted to DQL -- single table, aggregate DQL
     * can express. A prior audit pass kept this on plain DBAL, documenting
     * a phpstan-doctrine false positive that mis-inferred the nullable
     * `performed_by` (genuinely NULL here for a non-'system' row whose
     * acting user was later deleted, an ON-DELETE-SET-NULL FK case distinct
     * from the system-row NULL case {@see findSystemObjectLogWithUsernames()}
     * documents) as always non-null, which would have made the real
     * `is_numeric()` filter below misreport as dead code. Re-verified live
     * against the current toolchain: that false positive no longer
     * reproduces -- `performed_by` now correctly infers as `int|null`
     * through the DQL alias, so the `is_numeric()` guard type-checks as a
     * real (non-dead) narrowing and PHPStan is clean.
     *
     * @return array<int, int> performed_by => count
     */
    public function countByUser(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.performedBy AS performed_by', 'COUNT(a.activityId) AS counter')
            ->where("a.object != 'system'")
            ->groupBy('a.performedBy')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['performed_by'])) {
                continue;
            }

            $counts[$row['performed_by']] = $row['counter'];
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
     * Item 14 DQL audit: stays on DBAL -- joins `users`, which is never
     * entity-mapped anywhere in this migration (only `user_infos` is, via
     * UserInfoEntity), and $usernameColumn/$idColumn are runtime column
     * names DQL can't express as a property path.
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
     * Item 14 DQL audit: stays on DBAL -- same `users`-is-unmapped and
     * runtime-column-name blockers as {@see findUserObjectLogWithUsernames()}
     * above, plus a MySQL-specific `IF(...)` expression with no DQL
     * equivalent.
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
     * Paginated activity rows matching an already-built $condition --
     * Ws\PwgCore::getActivityList()'s own WS listing, one real caller.
     *
     * SQL-modernization audit: $whereClause used to be an already-built
     * raw SQL string (several of its own fragments -- uid/id/date range/
     * admin-id exclusion list -- spliced directly by the caller), and
     * $limit/$offset were spliced too; now a SqlCondition plus
     * setMaxResults()/setFirstResult().
     *
     * Item 14 DQL audit: stays on DBAL -- $condition carries a caller-built
     * raw SQL fragment (SqlCondition, applied via ->where($condition->sql)),
     * not a DQL property-path expression.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginated(SqlCondition $condition, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(
                'activity_id',
                'performed_by',
                'object',
                'object_id',
                'action',
                'session_idx',
                'ip_address',
                'occured_on',
                'details',
                'user_agent',
            )
            ->from(Tables::activity())
            ->orderBy('activity_id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (! $condition->isEmpty()) {
            $qb->where($condition->sql);
            foreach ($condition->parameters as $name => $value) {
                $qb->setParameter($name, $value, $condition->types[$name] ?? ParameterType::STRING);
            }
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Per (day, object, action) counts among rows on/after $sinceDate --
     * Controller\Admin\IntroSubController's own dashboard "recent
     * activity" chart data.
     *
     * Item 14 DQL audit: stays on DBAL -- `DATE_FORMAT()` is MySQL-specific
     * with no DQL equivalent.
     *
     * @return list<array{activity_day: string, object: string, action: string, counter: int}>
     */
    public function findDailyActionCountsSince(string $sinceDate): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select("DATE_FORMAT(occured_on, '%Y-%m-%d') AS activity_day", 'object', 'action', 'COUNT(*) AS activity_counter')
            ->from(Tables::activity())
            ->where('occured_on >= :sinceDate')
            ->groupBy('activity_day', 'object', 'action')
            ->setParameter('sinceDate', $sinceDate)
            ->executeQuery()
            ->fetchAllAssociative();

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
     * Item 14 DQL audit: converted to DQL -- single table, fixed WHERE
     * shape, no unmapped joins or MySQL-specific functions.
     *
     * @return list<array{action: string, occured_on: ?string, details: ?string}>
     */
    public function findCoreUpdateHistory(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.action', 'a.occuredOn AS occured_on', 'a.details')
            ->where("a.object = 'system'")
            ->andWhere('a.objectId = :activitySystemCore')
            ->andWhere("a.action IN ('update', 'autoupdate')")
            ->orderBy('a.activityId', 'ASC')
            ->setParameter('activitySystemCore', \Piwigo\Core\ActivitySystem::Core, ParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $history = [];
        foreach ($rows as $row) {
            $details = is_array($row) ? ($row['details'] ?? null) : null;
            // DQL array hydration decodes the `json`-typed `details` column
            // into a PHP array (unlike the raw-DBAL row this method used to
            // return, where it was the raw JSON text) -- re-encode
            // explicitly to keep this method's own `?string` contract;
            // PiwigoInfosSender's consumer only needs it to round-trip
            // through json_decode(), not byte-identically.
            $encodedDetails = is_array($details) ? json_encode($details) : false;

            $history[] = [
                'action' => is_array($row) && is_string($row['action']) ? $row['action'] : '',
                'occured_on' => is_array($row) && is_string($row['occured_on'] ?? null) ? $row['occured_on'] : null,
                'details' => $encodedDetails === false ? null : $encodedDetails,
            ];
        }

        return $history;
    }

    /**
     * Per (object_id, action) counts among object='system' rows --
     * Admin\PiwigoInfosSender's own telemetry "activities.system" bucket.
     * Unlike findSystemObjectLogWithUsernames() above, this doesn't join
     * `users` (only the counts matter here, not who performed each one).
     *
     * Item 14 DQL audit: converted to DQL -- single table, no unmapped
     * joins, `object`/`object_id`/`action` are all non-nullable columns so
     * no GROUP BY-on-nullable-column false positive applies here (unlike
     * {@see countByUser()}'s own documented one).
     *
     * @return list<array{object: string, object_id: int, action: string, counter: int}>
     */
    public function findSystemActionCountsByObjectId(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.object', 'a.objectId AS object_id', 'a.action', 'COUNT(a.activityId) AS counter')
            ->where("a.object = 'system'")
            ->groupBy('a.object', 'a.objectId', 'a.action')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'object' => $row['object'],
                'object_id' => $row['object_id'],
                'action' => $row['action'],
                'counter' => $row['counter'],
            ],
            $rows
        );
    }

    /**
     * Per non-browser `user_agent` counts and first/last-seen dates --
     * Admin\PiwigoInfosSender's own "which apps have been used" telemetry
     * breakdown. Excludes real browser traffic (Mozilla/5.x user agents).
     *
     * Item 14 DQL audit: converted to DQL -- single table, MIN()/MAX() are
     * standard DQL functions (unlike the MySQL-specific ones this pass
     * excludes). `user_agent` is nullable in the entity mapping, but the
     * `NOT LIKE` WHERE excludes NULL rows at the SQL level (NULL NOT LIKE
     * '...' is NULL, not TRUE), so the row-mapping below stays defensive
     * only for phpstan-doctrine's own static type, not a real runtime case.
     *
     * @return list<array{user_agent: ?string, counter: int, first_encounter: ?string, last_encounter: ?string}>
     */
    public function findUserAgentBreakdown(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.userAgent AS user_agent', 'COUNT(a.activityId) AS counter', 'MIN(a.occuredOn) AS first_encounter', 'MAX(a.occuredOn) AS last_encounter')
            ->where("a.userAgent NOT LIKE 'Mozilla/5%'")
            ->groupBy('a.userAgent')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'user_agent' => is_string($row['user_agent']) ? $row['user_agent'] : null,
                'counter' => $row['counter'],
                'first_encounter' => $row['first_encounter'],
                'last_encounter' => $row['last_encounter'],
            ],
            $rows
        );
    }
}
