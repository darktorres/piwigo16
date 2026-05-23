<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for piwigo_user_failed_logins. */
final class UserFailedLoginRepository extends AbstractRepository
{
    public function recentFailureCountForUser(int $userId, int $sinceSeconds): int
    {
        // $sinceSeconds is `int`-typed by the signature, so safe to embed.
        // INTERVAL inside MySQL doesn't reliably accept bound parameters in
        // every DBAL/driver combination, so inline the int.
        return $this->fetchOneInt(
            $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($this->table('user_failed_logins'))
                ->where('user_id = :userId')
                ->andWhere('attempted_at > (NOW() - INTERVAL ' . $sinceSeconds . ' SECOND)')
                ->setParameter('userId', $userId),
        );
    }

    /**
     * Insert a failure row. The $when parameter is interpreted in MySQL's
     * server timezone (the column is DATETIME, which has no zone of its
     * own) — we format it as an offset against MySQL's `NOW()` rather
     * than passing PHP's UTC clock through unchanged, otherwise tests
     * (and prod) running on a host whose PHP date.timezone differs from
     * MySQL's `time_zone` produce rows that don't match a window query.
     */
    public function insertFailure(?int $userId, string $ip, \DateTimeImmutable $when): void
    {
        $offsetSeconds = $when->getTimestamp() - time();
        $this->conn->executeStatement(
            'INSERT INTO ' . $this->table('user_failed_logins')
                . ' (user_id, ip, attempted_at)'
                . ' VALUES (?, ?, NOW() + INTERVAL ' . $offsetSeconds . ' SECOND)',
            [$userId, $ip],
        );
    }

    public function deleteForUser(int $userId): void
    {
        $this->conn->delete($this->table('user_failed_logins'), ['user_id' => $userId]);
    }

    /**
     * Delete failure rows older than the given cutoff. Cutoff is
     * expressed as an offset against MySQL's `NOW()` for the same
     * cross-timezone-safety reason as {@see insertFailure()}.
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        $offsetSeconds = $cutoff->getTimestamp() - time();
        return (int) $this->conn->executeStatement(
            'DELETE FROM ' . $this->table('user_failed_logins')
                . ' WHERE attempted_at < (NOW() + INTERVAL ' . $offsetSeconds . ' SECOND)',
        );
    }
}
