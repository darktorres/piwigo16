<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Db\AbstractRepository;

use Doctrine\DBAL\ArrayParameterType;

/** Persistence layer for the user domain. */
final class UserRepository extends AbstractRepository
{
    /**
     * Delete favorites entries for the given image ids.
     *
     * @param int[] $imageIds
     */
    public function deleteFavoritesByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete all user-related records from every dependent table.
     * Call this before deleting the user from the users table itself.
     */
    public function deleteAllRelatedData(int $userId): void
    {
        foreach ([
            'user_access', 'user_mail_notification', 'user_feed',
            'user_cache', 'user_cache_categories', 'user_group',
            'favorites', 'caddie', 'user_infos', 'user_auth_keys',
        ] as $suffix) {
            $this->conn->createQueryBuilder()
                ->delete($this->table($suffix))
                ->where('user_id = :userId')
                ->setParameter('userId', $userId)
                ->executeStatement();
        }
    }

    /**
     * Delete the user row from the users table.
     *
     * $usersTable and $idField come from admin config (Config::usersTable,
     * Config::userFields), not from user input — safe to embed in SQL.
     */
    public function deleteByUserId(int $userId, string $usersTable, string $idField): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . $usersTable . ' WHERE ' . $idField . ' = ?',
            [$userId]
        );
    }

    /**
     * Set last_visit to now for the given user.
     * Uses PHP time so the repository stays DB-engine agnostic.
     */
    public function updateLastVisit(int $userId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('last_visit', ':now')
            ->where('user_id = :userId')
            ->setParameter('now', $now)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Return the webmaster's email address.
     * $emailField, $idField, $usersTable come from Config — admin-configured,
     * not user-supplied, safe to embed in SQL.
     */
    public function getWebmasterEmail(string $emailField, string $idField, string $usersTable, int $webmasterId): string
    {
        $value = $this->conn->executeQuery(
            "SELECT $emailField FROM $usersTable WHERE $idField = ?",
            [$webmasterId]
        )->fetchOne();
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Delete rows from any user-related table where user_id is not in the base
     * users table.  $tableName is a PHP constant (e.g. USER_INFOS_TABLE) —
     * not user-supplied, safe to embed directly.
     *
     * @param int[] $userIds
     */
    public function deleteOrphanedFromTable(string $tableName, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($tableName);
        $qb->where($qb->expr()->in('user_id', ':ids'))
           ->setParameter('ids', $userIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete user→category access grants for the given category ids.
     *
     * @param int[] $categoryIds
     */
    public function deleteUserAccessByCategoryIds(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_access'));
        $qb->where($qb->expr()->in('cat_id', ':ids'))
           ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete user category cache for the given category ids.
     *
     * @param int[] $categoryIds
     */
    public function deleteUserCacheByCategoryIds(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_cache_categories'));
        $qb->where($qb->expr()->in('cat_id', ':ids'))
           ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /** Total number of registered users. */
    public function countAll(string $usersTable): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($usersTable)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Count users whose status is one of the given statuses.
     *
     * @param string[] $statuses
     */
    public function countByStatus(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_infos'));
        $qb->where($qb->expr()->in('status', ':statuses'))
           ->setParameter('statuses', $statuses, ArrayParameterType::STRING);
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total number of groups. */
    public function countGroups(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('groups'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return a map of user_id → username for the given ids.
     * Column names come from admin config — not user-supplied.
     *
     * @param  int[]  $ids
     * @return array<string, string>  keyed by string user_id
     */
    public function findUsernamesByIds(
        string $idField, string $usernameField, string $usersTable, array $ids
    ): array {
        if ($ids === []) {
            return [];
        }
        $rows = $this->conn->executeQuery(
            "SELECT $idField AS id, $usernameField AS username FROM $usersTable WHERE $idField IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['id']] = (string) $row['username'];
        }
        return $result;
    }

    /**
     * Count users whose email (case-insensitive) matches $email, optionally excluding $excludeUserId.
     * $emailField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function countByEmail(
        string $emailField, string $idField, string $usersTable,
        string $email, ?int $excludeUserId = null
    ): int {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($usersTable)
            ->where("UPPER($emailField) = UPPER(:email)")
            ->setParameter('email', $email);
        if ($excludeUserId !== null) {
            $qb->andWhere("$idField != :excludeId")
               ->setParameter('excludeId', $excludeUserId);
        }
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Count users whose username matches $login (case-insensitive).
     * $usernameField and $usersTable are admin-configured — not user-supplied.
     */
    public function countByUsernameInsensitive(
        string $usernameField, string $usersTable, string $login
    ): int {
        $value = $this->conn->createQueryBuilder()
            ->select("COUNT($usernameField)")
            ->from($usersTable)
            ->where("LOWER($usernameField) = :login")
            ->setParameter('login', strtolower($login))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return all username values from the users table.
     * $usernameField and $usersTable are admin-configured — not user-supplied.
     *
     * @return string[]
     */
    public function findAllUsernames(string $usernameField, string $usersTable): array
    {
        $rows = $this->conn->executeQuery(
            "SELECT $usernameField FROM $usersTable"
        )->fetchFirstColumn();
        return array_map('strval', $rows);
    }

    /**
     * Return ids of all groups that have is_default = 'true', ordered by id.
     *
     * @return int[]
     */
    public function findDefaultGroupIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('groups'))
            ->where("is_default = 'true'")
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Return the user id whose username exactly matches $username, or false.
     * $usernameField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function findIdByUsername(
        string $usernameField, string $idField, string $usersTable, string $username
    ): int|false {
        $value = $this->conn->createQueryBuilder()
            ->select($idField)
            ->from($usersTable)
            ->where("$usernameField = :username")
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : false;
    }

    /**
     * Return the user id whose email (case-insensitive) matches $email, or false.
     * $emailField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function findIdByEmail(
        string $emailField, string $idField, string $usersTable, string $email
    ): int|false {
        $value = $this->conn->createQueryBuilder()
            ->select($idField)
            ->from($usersTable)
            ->where("UPPER($emailField) = UPPER(:email)")
            ->setParameter('email', $email)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : false;
    }

    /**
     * Return the user_infos row for the default user, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultUserInfo(int $defaultUserId): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('user_infos'))
            ->where('user_id = :userId')
            ->setParameter('userId', $defaultUserId)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Return username + password for a user, used to compute the auto-login key.
     * Column names are admin-configured — not user-supplied.
     *
     * @return array{username: string, password: string}|null
     */
    public function findAuthFieldsById(
        string $usernameField, string $passwordField, string $idField,
        string $usersTable, int $userId
    ): ?array {
        $row = $this->conn->executeQuery(
            "SELECT $usernameField AS username, $passwordField AS password FROM $usersTable WHERE $idField = ?",
            [$userId]
        )->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Find a user by exact username or exact email match (username tried first).
     * Returns id/username/email/password/status or null.
     * All column/table names are admin-configured — not user-supplied.
     *
     * @return array<string, mixed>|null
     */
    public function findByUsernameOrEmail(
        string $usernameField, string $emailField, string $idField, string $passwordField,
        string $usersTable, string $usernameOrEmail
    ): ?array {
        $select = "$idField AS id, $usernameField AS username, $emailField AS email, $passwordField AS password, status";
        $userInfos = $this->table('user_infos');
        $joinCond  = "u.$idField = i.user_id";

        $sql = "SELECT $select FROM $usersTable AS u LEFT JOIN $userInfos AS i ON $joinCond WHERE";

        $row = $this->conn->executeQuery("$sql $usernameField = ?", [$usernameOrEmail])->fetchAssociative();
        if ($row === false) {
            $row = $this->conn->executeQuery("$sql $emailField = ?", [$usernameOrEmail])->fetchAssociative();
        }

        return $row !== false ? $row : null;
    }
}
