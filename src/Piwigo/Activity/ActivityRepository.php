<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the activity-log domain. */
final class ActivityRepository extends AbstractRepository
{
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
}
