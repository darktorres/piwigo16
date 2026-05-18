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

    /**
     * Run the groups.getList query (groups with user_group LEFT JOIN counter)
     * and return the paged result.
     *
     * @param  list<string>                                                $whereClauses
     * @param  list<mixed>                                                 $params
     * @param  list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return list<array<string, mixed>>
     */
    public function findListPage(array $whereClauses, string $orderBy, int $perPage, int $offset, array $params = [], array $types = []): array
    {
        $where = $whereClauses !== [] ? implode(' AND ', $whereClauses) : '1=1';
        return $this->conn->executeQuery(
            'SELECT g.*, COUNT(user_id) AS nb_users FROM ' . $this->table('groups') . ' AS g'
            . ' LEFT JOIN ' . $this->table('user_group') . ' AS ug ON ug.group_id = g.id'
            . ' WHERE ' . $where
            . ' GROUP BY id'
            . ' ORDER BY ' . $orderBy
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params,
            $types,
        )->fetchAllAssociative();
    }

    /** Insert a new group row and return its id. */
    public function insertNew(string $name, int $isDefault): int
    {
        $this->conn->insert($this->table('groups'), ['name' => $name, 'is_default' => $isDefault]);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Update arbitrary columns on a single group.
     *
     * @param array<string, mixed> $fields
     */
    public function updateById(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->conn->update($this->table('groups'), $fields, ['id' => $id]);
    }

    /**
     * Insert user_group rows atomically with INSERT IGNORE on the
     * (group_id, user_id) PK.
     *
     * @param list<array{group_id: int, user_id: int}> $rows
     */
    public function insertUserGroupIgnoreDuplicates(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->executeStatement(
                    'INSERT IGNORE INTO ' . $this->table('user_group') . ' (group_id, user_id) VALUES (?, ?)',
                    [$row['group_id'], $row['user_id']],
                );
            }
        });
    }

    /**
     * Return DISTINCT user_ids that belong to any of the given groups.
     *
     * @param  list<int> $groupIds
     * @return list<int>
     */
    public function findDistinctUserIdsInGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT user_id')
            ->from($this->table('user_group'));
        $qb->where($qb->expr()->in('group_id', ':ids'))
           ->setParameter('ids', $groupIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return user_ids in the given group.
     *
     * @return list<int>
     */
    public function findUserIdsByGroupId(int $groupId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_group'))
            ->where('group_id = :groupId')
            ->setParameter('groupId', $groupId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return id → name map for the given group ids (no order).
     *
     * @param  int[] $ids
     * @return array<int|string, string>
     */
    public function findIdNameMapByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from($this->table('groups'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }
        return $out;
    }

    /**
     * Return ids of every group.
     *
     * @return list<int>
     */
    public function findAllIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('groups'))
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return id → name map for every group, ordered by name ASC.
     *
     * @return array<int|string, string>
     */
    public function findAllIdToNameMapOrderedByName(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from($this->table('groups'))
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }
        return $out;
    }

    /**
     * Return id → name map for the given group ids, ordered by name ASC.
     *
     * @param  list<int> $ids
     * @return array<int|string, string>
     */
    public function findIdToNameMapByIdsOrderedByName(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from($this->table('groups'))
            ->orderBy('name', 'ASC');
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }
        return $out;
    }

    /**
     * Return user_ids that belong to any of the given groups.
     *
     * @param  list<int> $groupIds
     * @return list<int>
     */
    public function findUserIdsByGroupIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_group'));
        $qb->where($qb->expr()->in('group_id', ':groupIds'))
           ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }
}
