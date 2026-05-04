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
        return (int) $count > 0;
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
