<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserActivityLogEntry;
use Piwigo\Auth\LoginActivityLookupInterface;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Users\UserEntity;

/**
 * Persistence layer for the activity domain: `activity` (an append-only
 * audit-ish log of admin/user actions app-wide, not to be confused with
 * the greenfield P18 AuditService/SEC-57, which is a separate, dedicated
 * tamper-evident log). Also serves admin/user_activity.php's own
 * dashboard read queries.
 *
 * @extends EntityRepository<ActivityEntity>
 */
final class ActivityRepository extends EntityRepository implements LoginActivityLookupInterface
{
    /**
     * Item 16F: real DQL replacement for the raw DBAL read
     * {@see \Piwigo\Auth\AuthRepository::countLoginActivity()} used to do
     * directly -- `Auth` (`L2aCoreDomain`) can't depend on `Activity`
     * (`L2bExtendedDomain`), so `AuthRepository` now constructor-injects
     * {@see \Piwigo\Auth\LoginActivityLookupInterface} instead, wired to
     * this class at the composition root.
     */
    #[\Override]
    public function countLoginActivity(int $userId): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(a.activityId)')
            ->from(ActivityEntity::class, 'a')
            ->where("a.action = 'login'")
            ->andWhere('a.performedBy = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

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
     * (admin/user_activity.php's `type=download_logs`).
     *
     * Item 16G: converted to real DQL. `details` is re-encoded via
     * `json_encode()` after DQL array hydration -- `ActivityEntity::
     * $details`'s custom `json` Doctrine Type always decodes it to a real
     * array on the way in, unlike the raw DBAL text this used to pass
     * straight through. Verified the CSV export
     * ({@see \Piwigo\Admin\UserActivityPageRenderer}) doesn't depend on
     * exact original byte-for-byte JSON formatting -- it writes the
     * string out as one opaque CSV cell, and its own defensive
     * `str_replace(['`groups`', '`rank`'], ...)` cleanup (a legacy
     * backtick-escaping artifact never found in any real fixture/DB row,
     * confirmed via a direct query) becomes a harmless no-op against a
     * freshly re-encoded, always-valid JSON string.
     *
     * @return list<UserActivityLogEntry>
     */
    public function findUserObjectLogWithUsernames(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select(
                'a.activityId AS activity_id',
                'a.performedBy AS performed_by',
                'a.object AS object',
                'a.objectId AS object_id',
                'a.action AS action',
                'a.ipAddress AS ip_address',
                'a.occuredOn AS occured_on',
                'a.details AS details',
                'u.username AS username',
            )
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'u.id = a.performedBy')
            ->where("a.object = 'user'")
            ->orderBy('a.activityId', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ipAddress = $row['ip_address'] ?? null;
            $details = $row['details'] ?? null;
            $encodedDetails = is_array($details) ? json_encode($details) : null;

            $result[] = UserActivityLogEntry::fromRow([
                'activity_id' => $row['activity_id'] ?? null,
                'performed_by' => $row['performed_by'] ?? null,
                'object' => $row['object'] ?? null,
                'object_id' => $row['object_id'] ?? null,
                'action' => $row['action'] ?? null,
                'ip_address' => $ipAddress instanceof IpAddress ? $ipAddress->value : null,
                'occured_on' => $row['occured_on'] ?? null,
                'details' => $encodedDetails !== false ? $encodedDetails : null,
                'username' => $row['username'] ?? null,
            ]);
        }

        return $result;
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
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real DQL
     * -- `users` is now mapped ({@see \Piwigo\Users\UserEntity}), so the
     * `$usernameColumn`/`$idColumn` multi-auth indirection is gone (always
     * `u.username`/`u.id`), and the MySQL `IF(...)` expression translates
     * directly to DQL's own `CASE WHEN ... END`. Unlike
     * {@see findUserObjectLogWithUsernames()}, `details`'s own consumer
     * here wants the decoded array, which DQL array hydration's `json`
     * Type conversion gives for free.
     *
     * @return list<SystemActivityLogEntry>
     */
    public function findSystemObjectLogWithUsernames(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select(
                'a.activityId AS activity_id',
                'a.performedBy AS performed_by',
                'a.objectId AS object_id',
                'a.action AS action',
                'a.occuredOn AS occured_on',
                'a.details AS details',
                "CASE WHEN a.performedBy = 0 OR a.performedBy IS NULL THEN 'System' ELSE u.username END AS username"
            )
            ->leftJoin(UserEntity::class, 'u', Join::WITH, 'u.id = a.performedBy')
            ->where("a.object = 'system'")
            ->orderBy('a.activityId', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = SystemActivityLogEntry::fromRow($row);
            }
        }

        return $result;
    }

    /**
     * Paginated activity rows matching an already-built $condition --
     * Ws\PwgCore::getActivityList()'s own WS listing, one real caller.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3: converted to real
     * DQL -- the caller-built raw `SqlCondition` fragment (itself already
     * a `SqlCondition::combine('AND', ...)` of a small, finite set of
     * optional pieces, all built from the caller's own already-validated
     * `$param`) is replaced by {@see ActivityListCriteria}, an immutable
     * object the caller builds once from the same `$param` values, now
     * translated into bound `andWhere()` calls here instead of a raw SQL
     * string the caller composed itself (same shape Item 13's own
     * Criteria classes established, e.g. {@see
     * \Piwigo\Comment\CommentApiCriteria}). Returned rows carry whatever
     * type each `ActivityEntity` column really is under DQL array
     * hydration -- `ip_address` an `IpAddress` VO (not a raw string),
     * `details` an already-decoded `array` (Doctrine's own `json` Type
     * conversion, not a raw JSON string) -- the caller was updated to
     * match instead of re-flattening these back to strings.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaginated(ActivityListCriteria $criteria, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.performedBy AS performed_by',
                'a.object AS object',
                'a.objectId AS object_id',
                'a.action AS action',
                'a.sessionIdx AS session_idx',
                'a.ipAddress AS ip_address',
                'a.occuredOn AS occured_on',
                'a.details AS details',
                'a.userAgent AS user_agent',
            )
            ->where("a.object != 'system'")
            ->orderBy('a.activityId', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($criteria->performedBy !== null) {
            $qb->andWhere('a.performedBy = :performedBy')
                ->setParameter('performedBy', $criteria->performedBy);
        }

        if ($criteria->action !== null) {
            $qb->andWhere('a.action = :action')
                ->setParameter('action', $criteria->action);
        }

        if ($criteria->object !== null) {
            $qb->andWhere('a.object = :object')
                ->setParameter('object', $criteria->object);
        }

        if ($criteria->minDate !== null) {
            $qb->andWhere('a.occuredOn >= :minDate')
                ->setParameter('minDate', $criteria->minDate);
        }

        if ($criteria->maxDate !== null) {
            $qb->andWhere('a.occuredOn <= :maxDate')
                ->setParameter('maxDate', $criteria->maxDate);
        }

        if ($criteria->objectId !== null) {
            $qb->andWhere('a.objectId = :objectId')
                ->setParameter('objectId', $criteria->objectId);
        }

        if ($criteria->connectionsMode === 'none') {
            $qb->andWhere("a.action NOT IN ('login', 'logout')");
        } elseif ($criteria->connectionsMode === 'admins_only') {
            $qb->andWhere("NOT (a.action IN ('login', 'logout') AND a.objectId NOT IN (:adminIds))")
                ->setParameter('adminIds', $criteria->adminIds, ArrayParameterType::INTEGER);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'performed_by' => $row['performed_by'] ?? null,
                'object' => $row['object'] ?? null,
                'object_id' => $row['object_id'] ?? null,
                'action' => $row['action'] ?? null,
                'session_idx' => $row['session_idx'] ?? null,
                'ip_address' => $row['ip_address'] ?? null,
                'occured_on' => $row['occured_on'] ?? null,
                'details' => $row['details'] ?? null,
                'user_agent' => $row['user_agent'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Per (day, object, action) counts among rows on/after $sinceDate --
     * Controller\Admin\IntroSubController's own dashboard "recent
     * activity" chart data.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- MySQL's `DATE_FORMAT(occured_on, '%Y-%m-%d')` has no
     * portable DQL equivalent, and it was also part of the `GROUP BY` key
     * (DQL can't group by a SELECT alias). Fetches `occuredOn`/`object`/
     * `action` per row instead and groups in PHP -- this admin dashboard
     * chart is already bounded to a small multi-week window by its own
     * caller ({@see \Piwigo\Controller\Admin\IntroSubController}) and
     * session-cached for 5 minutes, so scanning every matching row is an
     * acceptable trade. `occuredOn` is a `Y-m-d H:i:s` string (`ActivityEntity`'s
     * own `length: 19`), so `substr($occuredOn, 0, 10)` reproduces
     * `DATE_FORMAT(..., '%Y-%m-%d')`'s output exactly.
     *
     * @return list<array{activity_day: string, object: string, action: string, counter: int}>
     */
    public function findDailyActionCountsSince(string $sinceDate): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.occuredOn AS occured_on', 'a.object AS object', 'a.action AS action')
            ->where('a.occuredOn >= :sinceDate')
            ->setParameter('sinceDate', $sinceDate)
            ->getQuery()
            ->getArrayResult();

        $byKey = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $occuredOn = is_string($row['occured_on'] ?? null) ? $row['occured_on'] : '';
            $object = is_string($row['object'] ?? null) ? $row['object'] : '';
            $action = is_string($row['action'] ?? null) ? $row['action'] : '';
            $activityDay = substr($occuredOn, 0, 10);

            $key = $activityDay . "\0" . $object . "\0" . $action;

            $byKey[$key] ??= [
                'activity_day' => $activityDay,
                'object' => $object,
                'action' => $action,
                'counter' => 0,
            ];
            $byKey[$key]['counter']++;
        }

        return array_values($byKey);
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
