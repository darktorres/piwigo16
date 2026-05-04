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
