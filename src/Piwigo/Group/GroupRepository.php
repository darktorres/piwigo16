<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\ORM\EntityRepository;
use Piwigo\Core\Env;
use Piwigo\Group\Projection\Group;

/**
 * Persistence layer for the group domain: `groups`, `user_group` (group
 * membership), `group_access` (per-group category permissions).
 *
 * @extends EntityRepository<GroupEntity>
 */
final class GroupRepository extends EntityRepository
{
    /**
     * Ids of every group marked is_default, ordered by id ascending.
     * Used to assign a newly registered user to the default groups.
     *
     * @return list<int>
     */
    public function findDefaultGroupIds(): array
    {
        $entities = $this->createQueryBuilder('g')
            ->where('g.isDefault = :isDefault')
            ->orderBy('g.id', 'ASC')
            ->setParameter('isDefault', true)
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupEntity $g): int => $g->id ?? 0, $entities);
    }

    /**
     * Every group's id/name/is_default, ordered by name.
     *
     * @return list<Group>
     */
    public function findAllBasic(): array
    {
        $entities = $this->createQueryBuilder('g')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (GroupEntity $g): Group => new Group($g->id ?? 0, $g->name, $g->isDefault),
            $entities,
        );
    }

    /**
     * Groups matching the given filters, each augmented with its member
     * count (`nb_users`). $order is a pre-validated raw SQL fragment (see
     * ValidationPattern::ORDER at the ws_groups_getList call site) -- not
     * user-controlled free text, but genuinely dynamic, same shape as
     * Search/Calendar/Section's own documented DQL exceptions -- stays
     * plain DBAL via the entity manager's own connection rather than DQL.
     *
     * @param array<int, int> $groupIds when non-empty, restricts to these ids
     * @return list<array{id: int, name: string, is_default: bool, lastmodified: string, nb_users: int}>
     */
    public function findWithMemberCounts(
        array $groupIds,
        ?string $nameLike,
        string $order,
        int $perPage,
        int $page
    ): array {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('g.*', 'COUNT(ug.user_id) AS nb_users')
            ->from(\Piwigo\Db\Tables::groups(), 'g')
            ->leftJoin('g', \Piwigo\Db\Tables::userGroup(), 'ug', 'ug.group_id = g.id')
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
                ->setParameter('groupIds', $groupIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        // is_default is a real tinyint(1) column now (Group domain Stage
        // 1a) -- ws.groups.getList's own JSON response returns this row
        // straight through (PwgGroups::getList()), and its schema
        // (tests/Contract/schemas/pwg.groups.getList.json) only allows
        // string|boolean, not the raw int a tinyint fetch would otherwise
        // produce. Normalized to real bool here, once, matching every
        // other domain's own retype convention.
        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'name' => is_string($row['name']) ? $row['name'] : '',
                'is_default' => (bool) $row['is_default'],
                'lastmodified' => is_string($row['lastmodified']) ? $row['lastmodified'] : '',
                'nb_users' => is_numeric($row['nb_users']) ? (int) $row['nb_users'] : 0,
            ],
            $rows
        );
    }

    public function nameExists(string $name, ?int $excludeGroupId = null): bool
    {
        $qb = $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.name = :name')
            ->setParameter('name', $name);

        if ($excludeGroupId !== null) {
            $qb->andWhere('g.id != :excludeGroupId')
                ->setParameter('excludeGroupId', $excludeGroupId);
        }

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    public function exists(int $groupId): bool
    {
        return $this->find($groupId) !== null;
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

        $entities = $this->createQueryBuilder('g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupEntity $g): int => $g->id ?? 0, $entities);
    }

    public function findName(int $groupId): ?string
    {
        return $this->find($groupId)?->name;
    }

    public function isDefault(int $groupId): bool
    {
        $entity = $this->find($groupId);

        return $entity !== null && $entity->isDefault;
    }

    public function insert(string $name, bool $isDefault): int
    {
        // lastmodified set explicitly rather than left to the schema's own
        // DEFAULT CURRENT_TIMESTAMP, which reads the real DB-server clock --
        // invisible to Env::now()'s PIWIGO_TEST_NOW freeze.
        $entity = new GroupEntity($name, $isDefault, \DateTimeImmutable::createFromInterface(Env::now()));

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id !== null);

        return $entity->id;
    }

    /**
     * @param array{name?: string, is_default?: bool} $updates
     */
    public function update(int $groupId, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $entity = $this->find($groupId);
        if ($entity === null) {
            return;
        }

        if (isset($updates['name'])) {
            $entity->name = $updates['name'];
        }

        if (isset($updates['is_default'])) {
            $entity->isDefault = $updates['is_default'];
        }

        $this->getEntityManager()
            ->flush();
    }

    /**
     * @return list<int>
     */
    public function findMemberUserIds(int $groupId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ug')
            ->from(UserGroupEntity::class, 'ug')
            ->where('ug.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserGroupEntity $ug): int => $ug->userId, $entities);
    }

    /**
     * Usernames of a group's members, via the configurable user-id/username
     * DB column names (see \Piwigo\Config\CurrentConfig::userFields()).
     * `users` isn't ORM-mapped (Users\UserRepository's own domain) -- plain
     * DBAL via the entity manager's own connection.
     *
     * @return list<string>
     */
    public function findMemberUsernames(int $groupId, string $usernameColumn, string $idColumn): array
    {
        $names = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('u.' . $usernameColumn . ' AS username')
            ->from(\Piwigo\Db\Tables::users(), 'u')
            ->innerJoin('u', \Piwigo\Db\Tables::userGroup(), 'ug', 'u.' . $idColumn . ' = ug.user_id')
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
     * legitimately pass an already-member user id. No query-builder/DQL
     * equivalent for INSERT IGNORE; raw SQL + bindings on the entity
     * manager's own connection is safe here (no string concatenation of
     * values), same precedent as SessionRepository::write()'s REPLACE INTO.
     *
     * @param array<int, int> $userIds
     */
    public function addMembers(int $groupId, array $userIds): void
    {
        $conn = $this->getEntityManager()
            ->getConnection();
        foreach ($userIds as $userId) {
            $conn->executeStatement(
                'INSERT IGNORE INTO ' . \Piwigo\Db\Tables::userGroup() . ' (group_id, user_id) VALUES (?, ?)',
                [$groupId, $userId],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER],
            );
        }

        // Bypasses the ORM entirely -- any UserGroupEntity this
        // EntityManager already loaded for this group would otherwise
        // stay stale (same identity-map reasoning as SessionRepository::
        // gc()'s own comment).
        $this->getEntityManager()
            ->clear();
    }

    /**
     * @param array<int, int> $userIds
     */
    public function removeMembers(int $groupId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(UserGroupEntity::class, 'ug')
            ->where('ug.groupId = :groupId')
            ->andWhere('ug.userId IN (:userIds)')
            ->setParameter('groupId', $groupId)
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->execute();
        $em->clear();
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

        $entities = $this->createQueryBuilder('g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        $deleted = [];
        foreach ($entities as $entity) {
            assert($entity->id !== null);
            $deleted[$entity->id] = $entity->name;
        }

        if ($deleted === []) {
            return [];
        }

        $ids = array_keys($deleted);
        $em = $this->getEntityManager();

        $em->createQueryBuilder()
            ->delete(GroupAccessEntity::class, 'ga')
            ->where('ga.groupId IN (:groupIds)')
            ->setParameter('groupIds', $ids)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(UserGroupEntity::class, 'ug')
            ->where('ug.groupId IN (:groupIds)')
            ->setParameter('groupIds', $ids)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(GroupEntity::class, 'g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $ids)
            ->getQuery()
            ->execute();

        $em->clear();

        return $deleted;
    }

    /**
     * @return list<int>
     */
    public function getAuthorizedCategoryIds(int $groupId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ga')
            ->from(GroupAccessEntity::class, 'ga')
            ->where('ga.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupAccessEntity $ga): int => $ga->catId, $entities);
    }

    /**
     * @param array<int, int> $catIds
     */
    public function removeAccess(int $groupId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(GroupAccessEntity::class, 'ga')
            ->where('ga.groupId = :groupId')
            ->andWhere('ga.catId IN (:catIds)')
            ->setParameter('groupId', $groupId)
            ->setParameter('catIds', $catIds)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param array<int, int> $catIds
     */
    public function addAccess(int $groupId, array $catIds): void
    {
        $em = $this->getEntityManager();
        foreach ($catIds as $catId) {
            $em->persist(new GroupAccessEntity($groupId, $catId));
        }

        $em->flush();
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
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ga.catId')
            ->from(UserGroupEntity::class, 'ug')
            ->innerJoin(GroupAccessEntity::class, 'ga', \Doctrine\ORM\Query\Expr\Join::WITH, 'ug.groupId = ga.groupId')
            ->where('ug.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['catId'], $rows);
    }
}
