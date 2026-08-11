<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Override;
use Piwigo\Activity\Projection\ActionCount;
use Piwigo\Activity\Projection\CoreUpdateHistoryRow;
use Piwigo\Activity\Projection\DailyActionCount;
use Piwigo\Activity\Projection\PaginatedActivityRow;
use Piwigo\Activity\Projection\SystemActionCount;
use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserActivityLogEntry;
use Piwigo\Activity\Projection\UserAgentBreakdown;
use Piwigo\Auth\LoginActivityLookupInterface;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Core\ActivitySystem;
use Piwigo\Users\UserEntity;

/**
 * Persistence layer for the activity domain: `activity` (an append-only
 * log of admin/user actions app-wide, not to be confused with
 * {@see \Piwigo\Audit\AuditService}, a separate, dedicated
 * tamper-evident log). Also serves admin/user_activity.php's own
 * dashboard read queries.
 *
 * @extends EntityRepository<ActivityEntity>
 */
final class ActivityRepository extends EntityRepository implements LoginActivityLookupInterface
{
    /**
     * Implements {@see \Piwigo\Auth\LoginActivityLookupInterface} so
     * `Auth` (`L2aCoreDomain`) can query login activity without
     * depending directly on `Activity` (`L2bExtendedDomain`);
     * `AuthRepository` constructor-injects the interface, wired to this
     * class at the composition root.
     */
    #[Override]
    public function countLoginActivity(int $userId): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(a.activityId)')
            ->from(ActivityEntity::class, 'a')
            ->where("a.action = 'login'")
            ->andWhere('a.performedBy = :userId')
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * performedBy uses UserId::tryFrom(), not from() -- ActivityService::
     * record()'s own logout branch reads it back from $_SESSION['pwg_uid'],
     * a session round-trip only defensively is_int()/is_string()-checked,
     * not guaranteed positive.
     *
     * @param list<array{
     *   object: string,
     *   objectId: int|string,
     *   action: string,
     *   performedBy: ?int,
     *   sessionIdx: string,
     *   ipAddress: ?IpAddress,
     *   occuredOn: SqlDateTime,
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
                performedBy: $row['performedBy'] !== null ? UserId::tryFrom($row['performedBy']) : null,
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
     * `performed_by` is nullable: a non-'system' row's acting user may
     * have since been deleted, in which case `performed_by` is NULL
     * (ON DELETE SET NULL foreign key) rather than referencing a deleted
     * user id -- a case distinct from the system-row NULL case
     * {@see findSystemObjectLogWithUsernames()} documents. The
     * `is_numeric()` check below filters those out before using the value
     * as an array key.
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
            if (! $row['performed_by'] instanceof UserId) {
                continue;
            }

            $counts[$row['performed_by']->value] = $row['counter'];
        }

        return $counts;
    }

    public function findMinOccuredOn(): ?string
    {
        return $this->findOneBy([], [
            'activityId' => 'ASC',
        ])?->occuredOn->value;
    }

    public function findMaxOccuredOn(): ?string
    {
        return $this->findOneBy([], [
            'activityId' => 'DESC',
        ])?->occuredOn->value;
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
        ])?->occuredOn->value;
    }

    /**
     * Number of logged actions per (object, action) pair, excluding
     * object='system' and optionally restricted to a single object type.
     *
     * @return list<ActionCount>
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
            static fn (array $row): ActionCount => new ActionCount(
                object: $row['object'],
                action: $row['action'],
                counter: $row['counter'],
            ),
            $rows
        );
    }

    /**
     * Every activity-log line for object='user', joined with the acting
     * user's own username -- used only by the CSV export
     * (admin/user_activity.php's `type=download_logs`).
     *
     * `details` is decoded to an array by the entity's `json` Doctrine
     * Type; it's re-encoded here via `json_encode()` to match this
     * method's own return contract. The CSV export
     * ({@see \Piwigo\Admin\UserActivityPageRenderer}) writes it out as
     * one opaque cell and doesn't depend on exact byte-for-byte JSON
     * formatting.
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
            $username = $row['username'] ?? null;

            $result[] = UserActivityLogEntry::fromRow([
                'activity_id' => $row['activity_id'] ?? null,
                'performed_by' => $row['performed_by'] ?? null,
                'object' => $row['object'] ?? null,
                'object_id' => $row['object_id'] ?? null,
                'action' => $row['action'] ?? null,
                'ip_address' => $ipAddress instanceof IpAddress ? $ipAddress->value : null,
                'occured_on' => $row['occured_on'] ?? null,
                'details' => $encodedDetails !== false ? $encodedDetails : null,
                'username' => $username instanceof Username ? $username->value : null,
            ]);
        }

        return $result;
    }

    /**
     * admin/maintenance_sys.php's own log ('object' = 'system', core/
     * plugin/theme install/update/activate/etc.) -- a LEFT JOIN (not the
     * INNER JOIN findUserObjectLogWithUsernames() uses), since a
     * system-triggered row's performed_by is NULL (no known actor, e.g. a
     * plugin autoupdate that ran before $user was loaded) and has no
     * matching user row. 'username' renders as "System" when performed_by
     * IS NULL; performed_by = 0 is also treated as "System" defensively,
     * though the column's ON DELETE SET NULL foreign key means a
     * since-deleted user's row is always nulled, never left referencing a
     * stale id.
     *
     * `details` is decoded to `?array` here (unlike
     * {@see findUserObjectLogWithUsernames()}'s own raw string) -- its one
     * real consumer, {@see \Piwigo\Admin\Maintenance\ActivityLogEntryFormatter},
     * does structured `$details['key']` access.
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
     * Paginated activity rows matching $criteria -- Ws\Core::getActivityList()'s
     * own WS listing, one real caller.
     *
     * $criteria (an immutable {@see ActivityListCriteria} value object) is
     * translated into bound `andWhere()` calls via Doctrine's `Criteria`/
     * `Criteria::expr()`, applied through `QueryBuilder::addCriteria()` --
     * eligible here because this query targets a single mapped entity
     * (`ActivityEntity`, alias `a`) with no JOIN (see
     * {@see \Piwigo\Comment\CommentRepository::applyApiConditions()}'s own
     * docblock for why most other Criteria-consuming methods in this
     * codebase aren't). `addCriteria()` only touches WHERE/ORDER/LIMIT;
     * this method's own `->select()`/`->orderBy()`/`->setMaxResults()`/
     * `->setFirstResult()` calls are set separately.
     *
     * Returned rows carry whatever type each `ActivityEntity` column
     * really is under DQL array hydration -- `ip_address` is an
     * `IpAddress` VO, `occured_on` is a `SqlDateTime` VO (neither is a
     * raw string), `details` is an already-decoded `array` (not a raw
     * JSON string).
     *
     * @return list<PaginatedActivityRow>
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

        $expr = Criteria::expr();
        $criteriaObj = Criteria::create();

        if ($criteria->performedBy !== null) {
            $criteriaObj->andWhere($expr->eq('performedBy', $criteria->performedBy));
        }

        if ($criteria->action !== null) {
            $criteriaObj->andWhere($expr->eq('action', $criteria->action));
        }

        if ($criteria->object !== null) {
            $criteriaObj->andWhere($expr->eq('object', $criteria->object));
        }

        if ($criteria->minDate !== null) {
            $criteriaObj->andWhere($expr->gte('occuredOn', $criteria->minDate));
        }

        if ($criteria->maxDate !== null) {
            $criteriaObj->andWhere($expr->lte('occuredOn', $criteria->maxDate));
        }

        if ($criteria->objectId !== null) {
            $criteriaObj->andWhere($expr->eq('objectId', $criteria->objectId));
        }

        if ($criteria->connectionsMode === 'none') {
            $criteriaObj->andWhere($expr->notIn('action', ['login', 'logout']));
        } elseif ($criteria->connectionsMode === 'admins_only') {
            $criteriaObj->andWhere($expr->not($expr->andX(
                $expr->in('action', ['login', 'logout']),
                $expr->notIn('objectId', array_map(static fn (UserId $id): int => $id->value, $criteria->adminIds)),
            )));
        }

        $qb->addCriteria($criteriaObj);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['object'] ?? null) || ! is_int($row['object_id'] ?? null)
                || ! is_string($row['action'] ?? null) || ! is_string($row['session_idx'] ?? null)
                || ! $row['occured_on'] instanceof SqlDateTime) {
                continue;
            }

            $performedBy = $row['performed_by'] ?? null;
            $ipAddress = $row['ip_address'] ?? null;
            $userAgent = $row['user_agent'] ?? null;
            $details = $row['details'] ?? null;

            $result[] = new PaginatedActivityRow(
                performedBy: $performedBy instanceof UserId ? $performedBy : null,
                object: $row['object'],
                objectId: $row['object_id'],
                action: $row['action'],
                sessionIdx: $row['session_idx'],
                ipAddress: $ipAddress instanceof IpAddress ? $ipAddress : null,
                occuredOn: $row['occured_on'],
                details: is_array($details) ? array_filter($details, is_string(...), ARRAY_FILTER_USE_KEY) : null,
                userAgent: is_string($userAgent) ? $userAgent : null,
            );
        }

        return $result;
    }

    /**
     * Per (day, object, action) counts among rows on/after $sinceDate --
     * Controller\Admin\IntroSubController's own dashboard "recent
     * activity" chart data.
     *
     * DQL has no portable equivalent for MySQL's `DATE_FORMAT(occured_on,
     * '%Y-%m-%d')`, and it can't group by a SELECT alias, so this fetches
     * `occuredOn`/`object`/`action` per row and groups in PHP instead --
     * acceptable because this admin dashboard chart is already bounded to
     * a small multi-week window by its own caller
     * ({@see \Piwigo\Controller\Admin\IntroSubController}) and
     * session-cached for 5 minutes. `occuredOn` is a `Y-m-d H:i:s` string
     * (`ActivityEntity`'s own `length: 19`), so `substr($occuredOn, 0, 10)`
     * reproduces `DATE_FORMAT(..., '%Y-%m-%d')`'s output exactly.
     *
     * @return list<DailyActionCount>
     */
    public function findDailyActionCountsSince(string $sinceDate): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.occuredOn AS occured_on', 'a.object AS object', 'a.action AS action')
            ->where('a.occuredOn >= :sinceDate')
            ->setParameter('sinceDate', $sinceDate)
            ->getQuery()
            ->getArrayResult();

        // DailyActionCount is readonly, so the running counter is tracked
        // in a parallel mutable array keyed the same way, and DTOs are
        // only constructed in the final loop below.
        $counterByKey = [];
        $fieldsByKey = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $occuredOn = ($row['occured_on'] ?? null) instanceof SqlDateTime ? $row['occured_on']->value : '';
            $object = is_string($row['object'] ?? null) ? $row['object'] : '';
            $action = is_string($row['action'] ?? null) ? $row['action'] : '';
            $activityDay = substr($occuredOn, 0, 10);

            $key = $activityDay . "\0" . $object . "\0" . $action;

            $fieldsByKey[$key] ??= [$activityDay, $object, $action];
            $counterByKey[$key] = ($counterByKey[$key] ?? 0) + 1;
        }

        $result = [];
        foreach ($fieldsByKey as $key => [$activityDay, $object, $action]) {
            $result[] = new DailyActionCount(
                activityDay: $activityDay,
                object: $object,
                action: $action,
                counter: $counterByKey[$key],
            );
        }

        return $result;
    }

    /**
     * Every action/occured_on/details row for core (Piwigo\Core\
     * ActivitySystem::Core) update/autoupdate system-activity entries,
     * oldest first -- Admin\PiwigoInfosSender's own "version upgrade
     * history" telemetry field.
     *
     * @return list<CoreUpdateHistoryRow>
     */
    public function findCoreUpdateHistory(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.action', 'a.occuredOn AS occured_on', 'a.details')
            ->where("a.object = 'system'")
            ->andWhere('a.objectId = :activitySystemCore')
            ->andWhere("a.action IN ('update', 'autoupdate')")
            ->orderBy('a.activityId', 'ASC')
            ->setParameter('activitySystemCore', ActivitySystem::Core, ParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $history = [];
        foreach ($rows as $row) {
            $details = is_array($row) ? ($row['details'] ?? null) : null;
            // DQL array hydration decodes the json-typed `details` column
            // into a PHP array; re-encode explicitly to keep this DTO's
            // own `?string` contract -- PiwigoInfosSender's consumer only
            // needs it to round-trip through json_decode(), not
            // byte-identically.
            $encodedDetails = is_array($details) ? json_encode($details) : false;

            $occuredOn = is_array($row) ? ($row['occured_on'] ?? null) : null;

            $history[] = new CoreUpdateHistoryRow(
                action: is_array($row) && is_string($row['action']) ? $row['action'] : '',
                occuredOn: $occuredOn instanceof SqlDateTime ? $occuredOn->value : null,
                details: $encodedDetails === false ? null : $encodedDetails,
            );
        }

        return $history;
    }

    /**
     * Per (object_id, action) counts among object='system' rows --
     * Admin\PiwigoInfosSender's own telemetry "activities.system" bucket.
     * Unlike {@see findSystemObjectLogWithUsernames()}, this doesn't join
     * `users` (only the counts matter here, not who performed each one).
     * `object`/`object_id`/`action` are all non-nullable columns, so
     * unlike {@see countByUser()}, no null-key filtering is needed here.
     *
     * @return list<SystemActionCount>
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
            static fn (array $row): SystemActionCount => new SystemActionCount(
                object: $row['object'],
                objectId: $row['object_id'],
                action: $row['action'],
                counter: $row['counter'],
            ),
            $rows
        );
    }

    /**
     * Per non-browser `user_agent` counts and first/last-seen dates --
     * Admin\PiwigoInfosSender's own "which apps have been used" telemetry
     * breakdown. Excludes real browser traffic (Mozilla/5.x user agents).
     *
     * `user_agent` is nullable in the entity mapping, but the `NOT LIKE`
     * WHERE excludes NULL rows at the SQL level (`NULL NOT LIKE '...'` is
     * NULL, not TRUE), so the row-mapping below stays defensive only for
     * phpstan-doctrine's own static type, not a real runtime case.
     *
     * @return list<UserAgentBreakdown>
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
            static fn (array $row): UserAgentBreakdown => new UserAgentBreakdown(
                userAgent: is_string($row['user_agent']) ? $row['user_agent'] : null,
                counter: $row['counter'],
                // MIN()/MAX() around a custom-Typed column don't get the
                // column's own Type applied during hydration (confirmed
                // live) -- these come back as plain driver strings, not
                // SqlDateTime instances, unlike a bare `a.occuredOn` select.
                firstEncounter: is_string($row['first_encounter']) ? $row['first_encounter'] : null,
                lastEncounter: is_string($row['last_encounter']) ? $row['last_encounter'] : null,
            ),
            $rows
        );
    }
}
