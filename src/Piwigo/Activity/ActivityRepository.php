<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the activity-log domain. */
final class ActivityRepository extends AbstractRepository
{
    /**
     * Return true if the given user has at least one 'login' activity entry.
     * Used by UserService::get()->hasAlreadyLoggedIn() to check first-time login for onboarding.
     */
    public function hasLoggedIn(int $userId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('activity'))
            ->where("action = 'login'")
            ->andWhere('performed_by = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Return the occurred_on value of the oldest activity entry, or null if none.
     * Used by user_activity.php to set the date-range minimum.
     */
    public function findOldestDate(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('occured_on')
            ->from($this->table('activity'))
            ->orderBy('activity_id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return the occurred_on value of the newest activity entry, or null if none.
     * Used by user_activity.php to set the date-range maximum.
     */
    public function findNewestDate(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('occured_on')
            ->from($this->table('activity'))
            ->orderBy('activity_id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Insert activity log rows atomically.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertActivityRowsBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('activity'), $row);
            }
        });
    }

    /**
     * Return the activity entries joined with the actor's username for the
     * given object kind. Used by the activity-log CSV export.
     *
     * $idField/$usernameField/$usersTable come from Config — admin-configured.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllByObjectWithUsername(
        string $object,
        string $idField,
        string $usernameField,
        string $usersTable,
    ): array {
        return $this->conn->executeQuery(
            "SELECT activity_id, performed_by, object, object_id, action, ip_address, occured_on, details, $usernameField AS username"
            . ' FROM ' . $this->table('activity')
            . " JOIN $usersTable AS u ON performed_by = u.$idField"
            . ' WHERE object = ?'
            . ' ORDER BY activity_id DESC',
            [$object]
        )->fetchAllAssociative();
    }

    /**
     * Return performed_by → row-count map for non-system activities.
     *
     * @return array<int|string, int>
     */
    public function findActivityCountByPerformer(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT performed_by, COUNT(*) AS counter FROM ' . $this->table('activity')
            . " WHERE object != 'system' GROUP BY performed_by"
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['performed_by']) ? (string) $row['performed_by'] : '';
            $out[$key] = is_numeric($row['counter']) ? (int) $row['counter'] : 0;
        }
        return $out;
    }

    /**
     * Return action breakdown across activity, optionally filtered to a
     * single object kind. Returns (object, action, counter) tuples.
     *
     * @return list<array<string, mixed>>
     */
    public function findActionCountsByObject(?string $objectFilter): array
    {
        $sql    = 'SELECT object, action, count(*) AS counter FROM ' . $this->table('activity') . " WHERE object != 'system'";
        $params = [];
        $types  = [];
        if ($objectFilter !== null && $objectFilter !== '') {
            $sql      .= ' AND object = ?';
            $params[]  = $objectFilter;
            $types[]   = \Doctrine\DBAL\ParameterType::STRING;
        }
        $sql .= ' GROUP BY action, object ORDER BY object ASC';
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Return the first `occurred_on` entry for the given (object, object_id, action) tuple.
     * Used by the admin album-modify page to show when an album was created.
     */
    public function findFirstOccurredOnForObject(string $object, int $objectId, string $action): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('occured_on')
            ->from($this->table('activity'))
            ->where('object = :object')
            ->andWhere('object_id = :objectId')
            ->andWhere('action = :action')
            ->orderBy('activity_id', 'ASC')
            ->setMaxResults(1)
            ->setParameter('object', $object)
            ->setParameter('objectId', $objectId)
            ->setParameter('action', $action)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * (object, action, counter) groupings for non-system activities, used by telemetry.
     *
     * @return list<array<string, mixed>>
     */
    public function findUserActivityGroupCounts(): array
    {
        return $this->conn->executeQuery(
            'SELECT object, action, COUNT(*) AS counter'
            . ' FROM ' . $this->table('activity')
            . " WHERE object != 'system' GROUP BY object, action",
        )->fetchAllAssociative();
    }

    /**
     * (object_id, action, counter) groupings restricted to object='system',
     * used by telemetry.
     *
     * @return list<array<string, mixed>>
     */
    public function findSystemActivityGroupCounts(): array
    {
        return $this->conn->executeQuery(
            'SELECT object, object_id, action, COUNT(*) AS counter'
            . ' FROM ' . $this->table('activity')
            . " WHERE object = 'system' GROUP BY object, object_id, action",
        )->fetchAllAssociative();
    }

    /**
     * Core update history (in ascending order) for telemetry's "updates" feed.
     * Filters object='system', object_id=$coreObjectId, action IN (update, autoupdate).
     *
     * @return list<array<string, mixed>>
     */
    public function findCoreUpdateActivities(int $coreObjectId): array
    {
        return $this->conn->executeQuery(
            'SELECT action, occured_on, details'
            . ' FROM ' . $this->table('activity')
            . " WHERE object = 'system' AND object_id = ? AND action IN ('update', 'autoupdate')"
            . ' ORDER BY activity_id ASC',
            [$coreObjectId],
            [\Doctrine\DBAL\ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    /**
     * Paginated activity rows for the admin history endpoint. Filters each
     * bind as DBAL parameters; the connections-mode controls visibility of
     * login/logout rows ('none' = hide all, 'admins_only' = hide unless the
     * object_id is in $adminIds).
     *
     * @param  list<int> $adminIds  required when $connectionsMode = 'admins_only'
     * @return list<array<string, mixed>>
     */
    public function findActivityPage(
        ?int $performedBy,
        ?string $action,
        ?string $object,
        ?string $dateMin,
        ?string $dateMax,
        ?int $objectId,
        string $connectionsMode,
        array $adminIds,
        int $limit,
        int $offset,
    ): array {
        $qb = $this->conn->createQueryBuilder()
            ->select('activity_id', 'performed_by', 'object', 'object_id', 'action', 'session_idx', 'ip_address', 'occured_on', 'details', 'user_agent')
            ->from($this->table('activity'))
            ->where("object != 'system'")
            ->orderBy('activity_id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        if ($performedBy !== null) {
            $qb->andWhere('performed_by = :uid')
               ->setParameter('uid', $performedBy);
        }
        if ($action !== null) {
            $qb->andWhere('action = :action')
               ->setParameter('action', $action);
        }
        if ($object !== null) {
            $qb->andWhere('object = :object')
               ->setParameter('object', $object);
        }
        if ($dateMin !== null && $dateMin !== '') {
            $qb->andWhere('occured_on >= :dmin')
               ->setParameter('dmin', $dateMin);
        }
        if ($dateMax !== null && $dateMax !== '') {
            $qb->andWhere('occured_on <= :dmax')
               ->setParameter('dmax', $dateMax);
        }
        if ($objectId !== null) {
            $qb->andWhere('object_id = :oid')
               ->setParameter('oid', $objectId);
        }
        if ($connectionsMode === 'none') {
            $qb->andWhere("action NOT IN ('login', 'logout')");
        } elseif ($connectionsMode === 'admins_only') {
            // Hide login/logout rows whose object_id is not an admin user id.
            if ($adminIds === []) {
                $qb->andWhere("action NOT IN ('login', 'logout')");
            } else {
                $qb->andWhere("NOT (action IN ('login', 'logout') AND object_id NOT IN (:adminIds))")
                   ->setParameter('adminIds', $adminIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
            }
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Per-user_agent counter + first/last sighting timestamps, excluding the
     * generic "Mozilla/5*" browser bucket. Used by telemetry's "apps" feed.
     *
     * @return list<array<string, mixed>>
     */
    public function findAppUserAgentStats(): array
    {
        return $this->conn->executeQuery(
            'SELECT user_agent, COUNT(*) AS counter,'
            . ' MIN(occured_on) AS first_encounter, MAX(occured_on) AS last_encounter'
            . ' FROM ' . $this->table('activity')
            . " WHERE user_agent NOT LIKE 'Mozilla/5%' GROUP BY user_agent",
        )->fetchAllAssociative();
    }
}
