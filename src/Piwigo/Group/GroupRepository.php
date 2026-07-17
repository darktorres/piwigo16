<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Core\Env;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the group domain: `groups`, `user_group` (group
 * membership), `group_access` (per-group category permissions).
 */
final class GroupRepository extends AbstractRepository
{
    /**
     * Ids of every group marked is_default, ordered by id ascending.
     * Used to assign a newly registered user to the default groups.
     *
     * @return list<int>
     */
    public function findDefaultGroupIds(): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::groups())
            ->where('is_default = :isDefault')
            ->orderBy('id', 'ASC')
            ->setParameter('isDefault', 'true')
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * Every group's id/name/is_default, ordered by name.
     *
     * @return list<array{id: int, name: string, is_default: bool}>
     */
    public function findAllBasic(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'is_default')
            ->from(Tables::groups())
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'name' => is_string($row['name']) ? $row['name'] : '',
                'is_default' => $row['is_default'] === 'true',
            ],
            $rows
        );
    }

    /**
     * Groups matching the given filters, each augmented with its member
     * count (`nb_users`). $order is a pre-validated raw SQL fragment (see
     * ValidationPattern::ORDER at the ws_groups_getList call site) -- not
     * user-controlled free text.
     *
     * @param array<int, int> $groupIds when non-empty, restricts to these ids
     * @return list<array<string, mixed>>
     */
    public function findWithMemberCounts(
        array $groupIds,
        ?string $nameLike,
        string $order,
        int $perPage,
        int $page
    ): array {
        $qb = $this->conn->createQueryBuilder()
            ->select('g.*', 'COUNT(ug.user_id) AS nb_users')
            ->from(Tables::groups(), 'g')
            ->leftJoin('g', Tables::userGroup(), 'ug', 'ug.group_id = g.id')
            ->groupBy('g.id')
            ->orderBy($order)
            ->setMaxResults($perPage)
            ->setFirstResult($perPage * $page);

        if ($nameLike !== null) {
            $qb->andWhere('LOWER(g.name) LIKE :nameLike')
                ->setParameter('nameLike', $nameLike);
        }

        if ($groupIds !== []) {
            $qb->andWhere('g.id IN (:groupIds)')
                ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER);
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    public function nameExists(string $name, ?int $excludeGroupId = null): bool
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::groups())
            ->where('name = :name')
            ->setParameter('name', $name);

        if ($excludeGroupId !== null) {
            $qb->andWhere('id != :excludeGroupId')
                ->setParameter('excludeGroupId', $excludeGroupId);
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    public function exists(int $groupId): bool
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::groups())
            ->where('id = :id')
            ->setParameter('id', $groupId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Returns the subset of $groupIds that actually exist.
     *
     * @param array<int, int> $groupIds
     * @return list<int>
     */
    public function findExistingIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $ids = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::groups())
            ->where('id IN (:groupIds)')
            ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    public function findName(int $groupId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::groups())
            ->where('id = :id')
            ->setParameter('id', $groupId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    public function isDefault(int $groupId): bool
    {
        $value = $this->conn->createQueryBuilder()
            ->select('is_default')
            ->from(Tables::groups())
            ->where('id = :id')
            ->setParameter('id', $groupId)
            ->executeQuery()
            ->fetchOne();

        return $value === 'true';
    }

    public function insert(string $name, bool $isDefault): int
    {
        // lastmodified set explicitly rather than left to the schema's own
        // DEFAULT CURRENT_TIMESTAMP, which reads the real DB-server clock --
        // invisible to Env::now()'s PIWIGO_TEST_NOW freeze.
        $this->conn->createQueryBuilder()
            ->insert(Tables::groups())
            ->values([
                'name' => ':name',
                'is_default' => ':isDefault',
                'lastmodified' => ':lastmodified',
            ])
            ->setParameter('name', $name)
            ->setParameter('isDefault', $isDefault ? 'true' : 'false')
            ->setParameter('lastmodified', Env::now()->format('Y-m-d H:i:s'))
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    /**
     * @param array{name?: string, is_default?: bool} $updates
     */
    public function update(int $groupId, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $qb = $this->conn->createQueryBuilder()
            ->update(Tables::groups())
            ->where('id = :id')
            ->setParameter('id', $groupId);

        if (isset($updates['name'])) {
            $qb->set('name', ':name')
                ->setParameter('name', $updates['name']);
        }

        if (isset($updates['is_default'])) {
            $qb->set('is_default', ':isDefault')
                ->setParameter('isDefault', $updates['is_default'] ? 'true' : 'false');
        }

        $qb->executeStatement();
    }

    /**
     * @return list<int>
     */
    public function findMemberUserIds(int $groupId): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from(Tables::userGroup())
            ->where('group_id = :groupId')
            ->setParameter('groupId', $groupId)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * Usernames of a group's members, via the configurable user-id/username
     * DB column names (see $conf['user_fields']).
     *
     * @return list<string>
     */
    public function findMemberUsernames(int $groupId, string $usernameColumn, string $idColumn): array
    {
        $names = $this->conn->createQueryBuilder()
            ->select('u.' . $usernameColumn . ' AS username')
            ->from(Tables::users(), 'u')
            ->innerJoin('u', Tables::userGroup(), 'ug', 'u.' . $idColumn . ' = ug.user_id')
            ->where('ug.group_id = :groupId')
            ->setParameter('groupId', $groupId)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names
        );
    }

    /**
     * Adds the given users to the group. Re-adding an existing member is a
     * silent no-op (INSERT IGNORE, matching the original \Piwigo\Db\MysqliDb::massInserts(...,
     * ['ignore' => true]) semantics) -- (group_id, user_id) is the table's
     * primary key, and callers (ws_groups_addUser, merge, duplicate) can
     * legitimately pass an already-member user id. No query-builder
     * equivalent for INSERT IGNORE in DBAL; raw SQL + bindings is safe here
     * (no string concatenation of values), same precedent as
     * SessionRepository::write()'s REPLACE INTO.
     *
     * @param array<int, int> $userIds
     */
    public function addMembers(int $groupId, array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->conn->executeStatement(
                'INSERT IGNORE INTO ' . Tables::userGroup() . ' (group_id, user_id) VALUES (?, ?)',
                [$groupId, $userId],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
        }
    }

    /**
     * @param array<int, int> $userIds
     */
    public function removeMembers(int $groupId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->delete(Tables::userGroup())
            ->where('group_id = :groupId')
            ->andWhere('user_id IN (:userIds)')
            ->setParameter('groupId', $groupId)
            ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * Deletes the given groups and everything referencing them
     * (group_access rows, user_group rows), returning the id => name of
     * every group actually deleted.
     *
     * @param array<int, int> $groupIds
     * @return array<int, string>
     */
    public function delete(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from(Tables::groups())
            ->where('id IN (:groupIds)')
            ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $deleted = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['id']) || ! is_string($row['name'])) {
                continue;
            }

            $deleted[(int) $row['id']] = $row['name'];
        }

        if ($deleted === []) {
            return [];
        }

        $ids = array_keys($deleted);

        $this->conn->createQueryBuilder()
            ->delete(Tables::groupAccess())
            ->where('group_id IN (:groupIds)')
            ->setParameter('groupIds', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();

        $this->conn->createQueryBuilder()
            ->delete(Tables::userGroup())
            ->where('group_id IN (:groupIds)')
            ->setParameter('groupIds', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();

        $this->conn->createQueryBuilder()
            ->delete(Tables::groups())
            ->where('id IN (:groupIds)')
            ->setParameter('groupIds', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();

        return $deleted;
    }

    /**
     * @return list<int>
     */
    public function getAuthorizedCategoryIds(int $groupId): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('cat_id')
            ->from(Tables::groupAccess())
            ->where('group_id = :groupId')
            ->setParameter('groupId', $groupId)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * @param array<int, int> $catIds
     */
    public function removeAccess(int $groupId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->delete(Tables::groupAccess())
            ->where('group_id = :groupId')
            ->andWhere('cat_id IN (:catIds)')
            ->setParameter('groupId', $groupId)
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @param array<int, int> $catIds
     */
    public function addAccess(int $groupId, array $catIds): void
    {
        foreach ($catIds as $catId) {
            $this->conn->createQueryBuilder()
                ->insert(Tables::groupAccess())
                ->values([
                    'group_id' => ':groupId',
                    'cat_id' => ':catId',
                ])
                ->setParameter('groupId', $groupId)
                ->setParameter('catId', $catId)
                ->executeStatement();
        }
    }

    /**
     * Category ids accessible to a user through group membership (i.e. via
     * group_access, not the user's own direct user_access grants). Used by
     * the Permission domain's forbidden-categories computation.
     *
     * @return list<int>
     */
    public function getAccessibleCategoryIdsForUser(int $userId): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('ga.cat_id')
            ->from(Tables::userGroup(), 'ug')
            ->innerJoin('ug', Tables::groupAccess(), 'ga', 'ug.group_id = ga.group_id')
            ->where('ug.user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $values
        );
    }
}
