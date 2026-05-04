<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for category access/permission data. */
final class PermissionRepository extends AbstractRepository
{
    /**
     * Return (user_id, cat_id) rows from user_access, optionally filtered by cat_ids.
     *
     * @param  int[]|null $catIds  null = no filter; empty array = return nothing
     * @return list<array<string, mixed>>
     */
    public function findUserCategoryAccess(?array $catIds = null): array
    {
        if ($catIds !== null && $catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('user_id', 'cat_id')
            ->from($this->table('user_access'));
        if ($catIds !== null) {
            $qb->where($qb->expr()->in('cat_id', ':catIds'))
               ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return (user_id, cat_id) rows from user_group × group_access join,
     * optionally filtered by cat_ids from group_access.
     *
     * @param  int[]|null $catIds
     * @return list<array<string, mixed>>
     */
    public function findGroupUserCategoryAccess(?array $catIds = null): array
    {
        if ($catIds !== null && $catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('ug.user_id', 'ga.cat_id')
            ->from($this->table('user_group'), 'ug')
            ->innerJoin('ug', $this->table('group_access'), 'ga', 'ug.group_id = ga.group_id');
        if ($catIds !== null) {
            $qb->where($qb->expr()->in('ga.cat_id', ':catIds'))
               ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return (group_id, cat_id) rows from group_access, optionally filtered by cat_ids.
     *
     * @param  int[]|null $catIds
     * @return list<array<string, mixed>>
     */
    public function findGroupCategoryAccess(?array $catIds = null): array
    {
        if ($catIds !== null && $catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('group_id', 'cat_id')
            ->from($this->table('group_access'));
        if ($catIds !== null) {
            $qb->where($qb->expr()->in('cat_id', ':catIds'))
               ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Delete access entries for a single group from the given category ids.
     * Used by group_perm.php when removing permissions for a specific group.
     *
     * @param int[] $catIds
     */
    public function deleteGroupAccessForGroup(int $groupId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('group_access'))
            ->where('group_id = :groupId')
            ->setParameter('groupId', $groupId);
        $qb->andWhere($qb->expr()->in('cat_id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return category ids that the given group has explicit access to.
     *
     * @return int[]
     */
    public function findAuthorizedCatIdsByGroup(int $groupId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('cat_id')
            ->from($this->table('group_access'))
            ->where('group_id = :groupId')
            ->setParameter('groupId', $groupId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Delete access entries for a single user from the given category ids.
     *
     * @param int[] $catIds
     */
    public function deleteUserAccessForUser(int $userId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_access'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);
        $qb->andWhere($qb->expr()->in('cat_id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return ids of private categories that the given group has explicit access to.
     * Used to build the "authorized" list in group_perm.php.
     *
     * @return int[]
     */
    public function findAuthorizedPrivateCatIdsByGroup(int $groupId): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT c.id FROM ' . $this->table('categories') . ' c
             INNER JOIN ' . $this->table('group_access') . ' ga ON ga.cat_id = c.id
             WHERE c.status = ? AND ga.group_id = ?',
            ['private', $groupId]
        )->fetchFirstColumn();
        return array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return (cat_id, c.uppercats, c.global_rank) rows for private categories
     * accessible to the given user via their group memberships.
     * Used by user_perm.php to show inherited permissions.
     *
     * @return list<array<string, mixed>>
     */
    public function findGroupAuthorizedCategoriesForUser(int $userId): array
    {
        return $this->conn->executeQuery(
            'SELECT DISTINCT ga.cat_id, c.uppercats, c.global_rank
             FROM ' . $this->table('user_group') . ' ug
             INNER JOIN ' . $this->table('group_access') . ' ga ON ug.group_id = ga.group_id
             INNER JOIN ' . $this->table('categories') . ' c ON c.id = ga.cat_id
             WHERE ug.user_id = ?',
            [$userId]
        )->fetchAllAssociative();
    }

    /**
     * Return ids of private categories that the given user has direct access to,
     * excluding any categories in $excludedCatIds.
     *
     * @param int[] $excludedCatIds
     * @return int[]
     */
    public function findDirectUserCatIds(int $userId, array $excludedCatIds = []): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('c.id')
            ->from($this->table('categories'), 'c')
            ->innerJoin('c', $this->table('user_access'), 'ua', 'ua.cat_id = c.id')
            ->where("c.status = 'private'")
            ->andWhere('ua.user_id = :userId')
            ->setParameter('userId', $userId);

        if ($excludedCatIds !== []) {
            $qb->andWhere($qb->expr()->notIn('ua.cat_id', ':excludedCatIds'))
               ->setParameter('excludedCatIds', $excludedCatIds, ArrayParameterType::INTEGER);
        }

        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Delete all group_access entries for the given groups (no category filter).
     *
     * @param int[] $groupIds
     */
    public function deleteGroupAccessByGroups(array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('group_access'));
        $qb->where($qb->expr()->in('group_id', ':groupIds'))
           ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete group access entries for the given groups and categories.
     *
     * @param int[] $groupIds
     * @param int[] $catIds
     */
    public function deleteGroupAccess(array $groupIds, array $catIds): void
    {
        if ($groupIds === [] || $catIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('group_access'));
        $qb->where($qb->expr()->in('group_id', ':groupIds'))
           ->andWhere($qb->expr()->in('cat_id', ':catIds'))
           ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER)
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete user access entries for the given users and categories.
     *
     * @param int[] $userIds
     * @param int[] $catIds
     */
    public function deleteUserAccess(array $userIds, array $catIds): void
    {
        if ($userIds === [] || $catIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_access'));
        $qb->where($qb->expr()->in('user_id', ':userIds'))
           ->andWhere($qb->expr()->in('cat_id', ':catIds'))
           ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER)
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }
}
