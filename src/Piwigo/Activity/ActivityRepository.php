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
