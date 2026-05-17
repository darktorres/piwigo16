<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the group domain. */
final class GroupRepository extends AbstractRepository
{
    /** Count groups with the given name (case-sensitive). */
    public function countByName(string $name): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('groups'))
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count groups with the given name excluding the group with $excludeId. */
    public function countByNameExcludingId(string $name, int $excludeId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('groups'))
            ->where('name = :name')
            ->andWhere('id != :excludeId')
            ->setParameter('name', $name)
            ->setParameter('excludeId', $excludeId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Return true when a group with the given id exists. */
    public function existsById(int $id): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('groups'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Count how many of the given ids exist in the groups table.
     * Used by ws_groups_merge to verify all groups are present.
     *
     * @param int[] $ids
     */
    public function countByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('groups'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Return the is_default value for a group by id, or null if not found. */
    public function findIsDefault(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('is_default')
            ->from($this->table('groups'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return (user_id, group_id) rows for the given group ids.
     * Used by cat_perm.php to find indirect user grants via group membership.
     *
     * @param int[] $groupIds
     * @return list<array<string, mixed>>
     */
    public function findUserGroupMembersByGroupIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('user_id', 'group_id')
            ->from($this->table('user_group'));
        $qb->where($qb->expr()->in('group_id', ':groupIds'))
           ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return usernames of all members of the given group.
     * Column names are admin-configured — not user-supplied.
     *
     * @return string[]
     */
    public function findMemberUsernamesByGroupId(
        string $usernameField,
        string $idField,
        string $usersTable,
        int $groupId
    ): array {
        $rows = $this->conn->executeQuery(
            "SELECT u.$usernameField AS username
             FROM $usersTable AS u
             INNER JOIN " . $this->table('user_group') . " AS ug ON u.$idField = ug.user_id
             WHERE ug.group_id = ?",
            [$groupId]
        )->fetchFirstColumn();
        return array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rows);
    }

    /** Return the name of a group by id, or null if not found. */
    public function findNameById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('name')
            ->from($this->table('groups'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Delete group rows by ids.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('groups'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete user_group rows for the given user ids.
     *
     * @param int[] $userIds
     */
    public function deleteUserGroupByUserIds(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_group'));
        $qb->where($qb->expr()->in('user_id', ':userIds'))
           ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return ids of groups whose name matches the given LIKE pattern.
     *
     * @return int[]
     */
    public function findIdsByNameLike(string $nameLike): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('groups'))
            ->where('name LIKE :pattern')
            ->setParameter('pattern', '%' . $nameLike . '%')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return all groups with their member counts.
     *
     * @return list<array<string, mixed>>
     */
    public function findWithMemberCounts(): array
    {
        return $this->conn->executeQuery(
            'SELECT g.id, g.name, COUNT(ug.user_id) AS nb_users_of
             FROM ' . $this->table('groups') . ' g
             LEFT JOIN ' . $this->table('user_group') . ' ug ON g.id = ug.group_id
             GROUP BY g.id, g.name
             ORDER BY g.name ASC'
        )->fetchAllAssociative();
    }

    /**
     * Return all groups ordered by name (id, name, is_default).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllOrdered(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('id', 'name', 'is_default')
            ->from($this->table('groups'))
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Remove the given users from a specific group.
     *
     * @param int[] $userIds
     */
    public function deleteUserGroupMembers(int $groupId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_group'))
            ->where('group_id = :groupId')
            ->setParameter('groupId', $groupId);
        $qb->andWhere($qb->expr()->in('user_id', ':userIds'))
           ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }
}
