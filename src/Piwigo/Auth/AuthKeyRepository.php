<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the user_auth_keys domain. */
final class AuthKeyRepository extends AbstractRepository
{
    /** Return true if a key with the given auth_key value already exists. */
    public function existsByKey(string $key): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_auth_keys'))
            ->where('auth_key = :key')
            ->setParameter('key', $key)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /** Return true if the given key belongs to the given user. */
    public function existsByKeyAndUser(string $key, int $userId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_auth_keys'))
            ->where('auth_key = :key')
            ->andWhere('user_id = :userId')
            ->setParameter('key', $key)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Mark all active auth_key rows for the given user as expired (set expired_on = NOW()).
     * Only affects rows where key_type = 'auth_key' and expired_on is in the future.
     */
    public function deactivateForUser(int $userId): void
    {
        // Uses MySQL NOW() — project is MySQL-only.
        $this->conn->executeStatement(
            'UPDATE ' . $this->table('user_auth_keys') .
            " SET expired_on = NOW() WHERE user_id = ? AND expired_on > NOW() AND key_type = 'auth_key'",
            [$userId]
        );
    }
}
