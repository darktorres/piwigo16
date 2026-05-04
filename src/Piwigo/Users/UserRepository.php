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
}
