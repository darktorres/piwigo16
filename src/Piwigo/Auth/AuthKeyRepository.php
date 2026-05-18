<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\DBAL\ParameterType;
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

    /**
     * Resolve an auth_key string to its row joined with the owning user's
     * id/email/username plus server-computed dbnow / days_left / 48h_ago.
     * Used by AuthService::validateKey for auth/api authentication.
     *
     * $idField/$usernameField/$emailField/$usersTable come from Config —
     * admin-configured, not user-supplied, safe to embed in SQL.
     *
     * @return array<string, mixed>|null
     */
    public function findAuthKeyDetails(
        string $authKey,
        string $idField,
        string $usernameField,
        string $emailField,
        string $usersTable,
    ): ?array {
        $query = 'SELECT *,'
            . " u.$usernameField AS username,"
            . " u.$emailField AS email,"
            . ' NOW() AS dbnow,'
            . ' DATEDIFF(uak.expired_on, NOW()) AS days_left,'
            . ' SUBDATE(NOW(), INTERVAL 48 HOUR) AS 48h_ago'
            . ' FROM ' . $this->table('user_auth_keys') . ' AS uak'
            . ' JOIN ' . $this->table('user_infos') . ' AS ui ON uak.user_id = ui.user_id'
            . " JOIN $usersTable AS u ON u.$idField = ui.user_id"
            . ' WHERE auth_key = ?';
        $row = $this->conn->executeQuery($query, [$authKey], [ParameterType::STRING])->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** Update last_used_on for the given (user_id, auth_key) row. */
    public function updateLastUsedOn(int $userId, string $authKey, string $dbnow): void
    {
        $this->conn->update(
            $this->table('user_auth_keys'),
            ['last_used_on' => $dbnow],
            ['user_id' => $userId, 'auth_key' => $authKey],
        );
    }

    /**
     * Mark a (user_id, auth_key) row as revoked (sets revoked_on = $now).
     */
    public function revokeKey(int $userId, string $authKey, string $now): void
    {
        $this->conn->update(
            $this->table('user_auth_keys'),
            ['revoked_on' => $now],
            ['auth_key' => $authKey, 'user_id' => $userId],
        );
    }

    /** Update apikey_name for a (user_id, auth_key) row. */
    public function updateApiKeyName(int $userId, string $authKey, string $apiName): void
    {
        $this->conn->update(
            $this->table('user_auth_keys'),
            ['apikey_name' => $apiName],
            ['auth_key' => $authKey, 'user_id' => $userId],
        );
    }

    /**
     * Return all api_key rows for the given user.
     *
     * @return list<array<string, mixed>>
     */
    public function findApiKeysByUserId(int $userId): array
    {
        return $this->conn->executeQuery(
            'SELECT * FROM ' . $this->table('user_auth_keys')
            . " WHERE user_id = ? AND key_type = 'api_key'",
            [$userId],
            [ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    /**
     * Insert a new user_auth_keys row and return its lastInsertId.
     *
     * @param array<string, mixed> $row
     */
    public function insertKey(array $row): int
    {
        $this->conn->insert($this->table('user_auth_keys'), $row);
        return (int) $this->conn->lastInsertId();
    }
}
