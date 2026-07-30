<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Env;
use Piwigo\Group\Projection\Group;

/**
 * Persistence layer for the group domain: `groups`, `user_group` (group
 * membership), `group_access` (per-group category permissions).
 *
 * Ids are typed VOs (`GroupId`/`UserId`/`CategoryId`) throughout, including
 * on the mapped entities themselves ({@see GroupEntity}, {@see UserGroupEntity},
 * {@see GroupAccessEntity} -- see their own docblocks for the custom
 * Doctrine Types backing this). Single-value DQL parameters bound against a
 * custom-typed field (`g.id = :x`) pass the VO directly -- Doctrine
 * resolves the field's mapped Type for the conversion. Array parameters
 * bound into an `IN (:x)` clause always unwrap to raw ints with an explicit
 * `ArrayParameterType::INTEGER` first -- Doctrine's array-parameter binding
 * does not reliably route each element through a custom field Type, unlike
 * the single-value path (verified against the installed doctrine/dbal
 * source, not assumed). Plain-DBAL (`getConnection()`) queries always bind
 * raw scalars, single or array -- DBAL itself has no notion of a custom
 * ORM field Type.
 *
 * @extends EntityRepository<GroupEntity>
 */
final class GroupRepository extends EntityRepository
{
    /**
     * Ids of every group marked is_default, ordered by id ascending.
     * Used to assign a newly registered user to the default groups.
     *
     * @return list<GroupId>
     */
    public function findDefaultGroupIds(): array
    {
        $entities = $this->createQueryBuilder('g')
            ->where('g.isDefault = :isDefault')
            ->orderBy('g.id', 'ASC')
            ->setParameter('isDefault', true)
            ->getQuery()
            ->getResult();

        return array_map(static function (GroupEntity $g): GroupId {
            assert($g->id !== null);

            return $g->id;
        }, $entities);
    }

    /**
     * Ids of every group whose name matches $namePattern (an already-built
     * SQL LIKE pattern, e.g. `%foo%`) -- Ws\PwgUsers::getList()'s own
     * "filter" param, which also searches group membership.
     *
     * @return list<int>
     */
    public function findIdsByNameLike(string $namePattern): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->createQueryBuilder()
                ->select('id')
                ->from(\Piwigo\Db\Tables::groups())
                ->where('name LIKE :namePattern')
                ->setParameter('namePattern', $namePattern)
                ->executeQuery()
                ->fetchFirstColumn()
        );
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
            static function (GroupEntity $g): Group {
                assert($g->id !== null);

                return new Group($g->id, $g->name, $g->isDefault);
            },
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
     * Plain DBAL never sees the `group_id` custom Type, so `$groupIds`
     * unwraps to raw ints before binding regardless of the IN-clause rule
     * above being about DQL specifically -- this was already true before
     * this VO integration, unchanged behavior, just a typed input now.
     *
     * @param list<GroupId> $groupIds when non-empty, restricts to these ids
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
                ->setParameter('groupIds', array_map(static fn (GroupId $id): int => $id->value, $groupIds), ArrayParameterType::INTEGER);
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

    public function nameExists(string $name, ?GroupId $excludeGroupId = null): bool
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

    public function exists(GroupId $groupId): bool
    {
        return $this->find($groupId) !== null;
    }

    /**
     * Returns the subset of $groupIds that actually exist.
     *
     * @param list<GroupId> $groupIds
     * @return list<GroupId>
     */
    public function findExistingIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $entities = $this->createQueryBuilder('g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', array_map(static fn (GroupId $id): int => $id->value, $groupIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(static function (GroupEntity $g): GroupId {
            assert($g->id !== null);

            return $g->id;
        }, $entities);
    }

    public function findName(GroupId $groupId): ?string
    {
        return $this->find($groupId)?->name;
    }

    public function isDefault(GroupId $groupId): bool
    {
        $entity = $this->find($groupId);

        return $entity !== null && $entity->isDefault;
    }

    public function insert(string $name, bool $isDefault): GroupId
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
    public function update(GroupId $groupId, array $updates): void
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
     * @return list<UserId>
     */
    public function findMemberUserIds(GroupId $groupId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ug')
            ->from(UserGroupEntity::class, 'ug')
            ->where('ug.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserGroupEntity $ug): UserId => $ug->userId, $entities);
    }

    /**
     * Usernames of a group's members, via the configurable user-id/username
     * DB column names (see \Piwigo\Config\CurrentConfig::userFields()).
     * `users` isn't ORM-mapped (Users\UserRepository's own domain) -- plain
     * DBAL via the entity manager's own connection, so $groupId unwraps to
     * a raw int before binding regardless of the custom Type.
     *
     * @return list<string>
     */
    public function findMemberUsernames(GroupId $groupId, string $usernameColumn, string $idColumn): array
    {
        $names = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('u.' . $usernameColumn . ' AS username')
            ->from(\Piwigo\Db\Tables::users(), 'u')
            ->innerJoin('u', \Piwigo\Db\Tables::userGroup(), 'ug', 'u.' . $idColumn . ' = ug.user_id')
            ->where('ug.group_id = :groupId')
            ->setParameter('groupId', $groupId->value)
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
     * values) -- plain DBAL, so both ids unwrap to raw ints.
     *
     * @param list<UserId> $userIds
     */
    public function addMembers(GroupId $groupId, array $userIds): void
    {
        $conn = $this->getEntityManager()
            ->getConnection();
        $userGroupTable = \Piwigo\Db\Tables::userGroup();
        foreach ($userIds as $userId) {
            $conn->executeStatement(
                <<<SQL
                INSERT IGNORE INTO {$userGroupTable} (group_id, user_id) VALUES (?, ?)
                SQL
                ,
                [$groupId->value, $userId->value],
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
     * @param list<UserId> $userIds
     */
    public function removeMembers(GroupId $groupId, array $userIds): void
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
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Removes $userIds from every group they belong to (not just one) --
     * UserService::checkAndSaveUserInfos()'s own "replace this user's
     * entire group membership list" step, unlike removeMembers() above
     * which is scoped to a single $groupId.
     *
     * @param list<UserId> $userIds
     */
    public function removeAllMembershipsForUsers(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(UserGroupEntity::class, 'ug')
            ->where('ug.userId IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Deletes the given groups and everything referencing them
     * (group_access rows, user_group rows), returning the id => name of
     * every group actually deleted. Return stays int-keyed -- a GroupId
     * object can't be a PHP array key.
     *
     * @param list<GroupId> $groupIds
     * @return array<int, string>
     */
    public function delete(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rawIds = array_map(static fn (GroupId $id): int => $id->value, $groupIds);

        $entities = $this->createQueryBuilder('g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $rawIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        $deleted = [];
        foreach ($entities as $entity) {
            assert($entity->id !== null);
            $deleted[$entity->id->value] = $entity->name;
        }

        if ($deleted === []) {
            return [];
        }

        $ids = array_keys($deleted);
        $em = $this->getEntityManager();

        $em->createQueryBuilder()
            ->delete(GroupAccessEntity::class, 'ga')
            ->where('ga.groupId IN (:groupIds)')
            ->setParameter('groupIds', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(UserGroupEntity::class, 'ug')
            ->where('ug.groupId IN (:groupIds)')
            ->setParameter('groupIds', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(GroupEntity::class, 'g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();

        $em->clear();

        return $deleted;
    }

    /**
     * @return list<CategoryId>
     */
    public function getAuthorizedCategoryIds(GroupId $groupId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ga')
            ->from(GroupAccessEntity::class, 'ga')
            ->where('ga.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupAccessEntity $ga): CategoryId => $ga->catId, $entities);
    }

    /**
     * @param list<CategoryId> $catIds
     */
    public function removeAccess(GroupId $groupId, array $catIds): void
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
            ->setParameter('catIds', array_map(static fn (CategoryId $id): int => $id->value, $catIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param list<CategoryId> $catIds
     */
    public function addAccess(GroupId $groupId, array $catIds): void
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
     * @return list<CategoryId>
     */
    public function getAccessibleCategoryIdsForUser(UserId $userId): array
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

        return array_map(static function (array $row): CategoryId {
            // Confirmed via GroupRepositoryTest: DQL scalar-field selects
            // against a custom-typed column (ga.catId) hydrate straight
            // into the real VO, same as full-entity hydration -- getResult()'s
            // own generic array-row return type just can't express that
            // statically, so this narrows explicitly rather than casting a
            // value that could never actually be a raw int here.
            if (! $row['catId'] instanceof CategoryId) {
                throw new \LogicException('Expected DQL hydration to produce a CategoryId for ga.catId.');
            }

            return $row['catId'];
        }, $rows);
    }

    /**
     * Raw (user_id, group_id) membership pairs across every id in
     * $groupIds -- Admin\CatPermPageRenderer's own "which users belong to
     * these granted groups" breakdown, a bulk variant of
     * findMemberUserIds() above (that one is scoped to a single group).
     *
     * @param list<int> $groupIds
     * @return list<array<string, mixed>>
     */
    public function findMembersByGroupIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $userGroupTable = \Piwigo\Db\Tables::userGroup();
        $groupIdsCsv = implode(',', $groupIds);

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT user_id, group_id
                FROM {$userGroupTable}
                WHERE group_id IN ({$groupIdsCsv})
                SQL);
    }

    /**
     * Raw (user_id, group_id) membership pairs for every id in $userIds --
     * Ws\PwgUsers::getList()'s own "which groups does each returned user
     * belong to" step, the reverse direction of
     * {@see findMembersByGroupIds()} above (that one is keyed by group,
     * this one by user).
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function findMembershipsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $userGroupTable = \Piwigo\Db\Tables::userGroup();
        $userIdsCsv = implode(',', $userIds);

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT user_id, group_id
                FROM {$userGroupTable}
                WHERE user_id IN ({$userIdsCsv})
                SQL);
    }

    /**
     * Total row count of `groups` -- Admin\InstallationStats's own
     * "nb_groups" summary figure.
     */
    public function countAll(): int
    {
        $groupsTable = \Piwigo\Db\Tables::groups();
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*) FROM `{$groupsTable}`
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * name keyed by id for $groupIds, ordered by name --
     * Admin\AlbumNotificationPageRenderer's own "group_mail_options"
     * select dropdown.
     *
     * @param  list<int>  $groupIds
     * @return array<int, string> keyed by id
     */
    public function findNamesByIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $groupsTable = \Piwigo\Db\Tables::groups();
        $groupIdsCsv = implode(',', $groupIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    id,
                    name
                FROM `{$groupsTable}`
                WHERE id IN ({$groupIdsCsv})
                ORDER BY name ASC
                SQL);

        $byId = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['id']) || ! is_string($row['name'])) {
                continue;
            }

            $byId[(int) $row['id']] = $row['name'];
        }

        return $byId;
    }
}
