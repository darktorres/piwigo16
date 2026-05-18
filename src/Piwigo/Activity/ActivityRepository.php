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
}
