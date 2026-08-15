<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use LogicException;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Env;
use Piwigo\Group\Projection\Group;
use Piwigo\Group\Projection\GroupListing;
use Piwigo\Users\UserEntity;

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
 * the single-value path, per the installed doctrine/dbal source. Plain-DBAL
 * (`getConnection()`) queries always bind
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
            assert($g->id instanceof GroupId);

            return $g->id;
        }, $entities);
    }

    /**
     * Ids of every group whose name matches $namePattern (an already-built
     * SQL LIKE pattern, e.g. `%foo%`) -- Ws\Users::getList()'s own
     * "filter" param, which also searches group membership.
     *
     * @return list<int>
     */
    public function findIdsByNameLike(string $namePattern): array
    {
        $ids = $this->createQueryBuilder('g')
            ->select('g.id')
            ->where('g.name LIKE :namePattern')
            ->setParameter('namePattern', $namePattern)
            ->getQuery()
            ->getSingleColumnResult();

        // getSingleColumnResult() uses Doctrine's HYDRATE_SCALAR_COLUMN
        // mode (ScalarColumnHydrator), which does a raw
        // Statement::fetchFirstColumn() with NO per-field Type conversion
        // at all -- unlike getArrayResult() (HYDRATE_ARRAY), it does NOT
        // hydrate g.id into a GroupId VO despite the custom `group_id`
        // Doctrine Type, so this reads the raw int/numeric-string
        // directly rather than an instanceof GroupId check (which would
        // silently produce [] here -- that gotcha is specific to
        // getArrayResult()/getResult()-shaped hydration, not this method).
        $result = [];
        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $result[] = (int) $id;
        }

        return $result;
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
                assert($g->id instanceof GroupId);

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
     * Stays on DBAL -- $order is a genuinely dynamic runtime ORDER BY
     * fragment; DQL's orderBy() takes a fixed field path, not a
     * caller-supplied string. {@see \Piwigo\Core\ValidationPattern::ORDER}'s
     * real regex (`/^(rand(om)?|[a-z_]+(\s+(asc|desc))?)(\s*,\s*...)*$/i`)
     * matches any `[a-z_]+` token, not a small fixed vocabulary --
     * genuinely open-ended, same "caller composes trusted ORDER BY text"
     * architecture as {@see \Piwigo\Sort\PhotoSortField}'s own documented
     * exception, so no enum can replace it here.
     *
     * @param list<GroupId> $groupIds when non-empty, restricts to these ids
     * @return list<GroupListing>
     */
    public function findWithMemberCounts(
        array $groupIds,
        ?string $nameLike,
        string $order,
        int $perPage,
        int $page
    ): array {
        $conn = $this->getEntityManager()
            ->getConnection();
        // 'groups' became a reserved keyword on MySQL 8.0.2+ (its own
        // window-frame GROUPS clause) -- QueryBuilder::from() takes a raw
        // table-name string, with none of the automatic quoting a mapped
        // entity's own #[ORM\Table(name: '`groups`')] attribute gets, so
        // this needs the same explicit, per-platform quoteSingleIdentifier()
        // this codebase already uses elsewhere (e.g. CategoryRepository's
        // own `rank` column quoting).
        $groupsTable = $conn->getDatabasePlatform()
            ->quoteSingleIdentifier('groups');
        $qb = $conn->createQueryBuilder()
            ->select('g.*', 'COUNT(ug.user_id) AS nb_users')
            ->from($groupsTable, 'g')
            ->leftJoin('g', 'user_group', 'ug', 'ug.group_id = g.id')
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
        // straight through (Groups::getList()), and its schema
        // (tests/Contract/schemas/pwg.groups.getList.json) only allows
        // string|boolean, not the raw int a tinyint fetch would otherwise
        // produce. Normalized to real bool here, once, matching every
        // other domain's own retype convention.
        return array_map(GroupListing::fromRow(...), $rows);
    }

    public function nameExists(string $name, ?GroupId $excludeGroupId = null): bool
    {
        $qb = $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.name = :name')
            ->setParameter('name', $name);

        if ($excludeGroupId instanceof GroupId) {
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
            assert($g->id instanceof GroupId);

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
        // invisible to Env::now()'s PIWIGO_TEST_NOW freeze. Redundant with
        // LastModifiedListener's own prePersist handling but harmless (same
        // value) -- not worth omitting for a required constructor param.
        $entity = new GroupEntity($name, $isDefault, SqlDateTime::fromDateTime(Env::now()));

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id instanceof GroupId);

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
     * Usernames of a group's members.
     *
     * `users` is mapped ({@see \Piwigo\Users\UserEntity}).
     *
     * @return list<string>
     */
    public function findMemberUsernames(GroupId $groupId): array
    {
        $names = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u.username')
            ->from(UserEntity::class, 'u')
            ->innerJoin(UserGroupEntity::class, 'ug', Join::WITH, 'u.id = ug.userId')
            ->where('ug.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names
        ));
    }

    /**
     * Adds the given users to the group. Re-adding an existing member, or
     * a nonexistent group_id/user_id (both real FKs --
     * `fk_user_group_group_id`/`fk_user_group_user_id`), is a silent
     * no-op (matching MySQL's own `INSERT IGNORE`, which downgrades both
     * a duplicate-key error and a foreign-key violation to a warning,
     * same as `Caddie\CaddieRepository::addElements()`'s own identical
     * fix) -- (group_id, user_id) is the table's primary key, and callers
     * (ws_groups_addUser, merge, duplicate) can legitimately pass an
     * already-member user id. Plain DBAL `Connection::insert()` (a
     * portable, bound `INSERT`, no MySQL-specific `IGNORE` syntax) per
     * user, with both cases caught as a real
     * {@see ConstraintViolationException} -- both ids unwrap to raw ints,
     * matching every other plain-DBAL query in this file.
     *
     * Not `persist()`/`flush()`: a caught
     * {@see ConstraintViolationException} from a failed `flush()` leaves
     * the EntityManager permanently closed
     * (`Doctrine\ORM\UnitOfWork::commit()`'s own `finally` branch calls
     * `$em->close()` on any failure, and `clear()` cannot undo that),
     * which would break every other repository sharing this request's
     * EntityManager -- a real regression an already-a-member $userId
     * would trigger in normal operation, not just a theoretical edge
     * case. Plain DBAL `insert()` never touches the ORM's unit of work,
     * so a caught failure here has no such blast radius.
     *
     * @param list<UserId> $userIds
     */
    public function addMembers(GroupId $groupId, array $userIds): void
    {
        $conn = $this->getEntityManager()
            ->getConnection();
        foreach ($userIds as $userId) {
            try {
                $conn->insert(
                    'user_group',
                    [
                        'group_id' => $groupId->value,
                        'user_id' => $userId->value,
                    ],
                    [
                        'group_id' => ParameterType::INTEGER,
                        'user_id' => ParameterType::INTEGER,
                    ],
                );
            } catch (ConstraintViolationException) {
                // Already a member, or group_id/user_id doesn't reference
                // a real row -- same "IGNORE" semantic the raw INSERT
                // IGNORE this replaces had.
            }
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
            ->innerJoin(GroupAccessEntity::class, 'ga', Join::WITH, 'ug.groupId = ga.groupId')
            ->where('ug.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): CategoryId {
            // DQL scalar-field selects against a custom-typed column
            // (ga.catId) hydrate straight into the real VO, same as
            // full-entity hydration -- getResult()'s own generic array-row
            // return type just can't express that statically, so this
            // narrows explicitly rather than casting a value that could
            // never actually be a raw int here.
            if (! $row['catId'] instanceof CategoryId) {
                throw new LogicException('Expected DQL hydration to produce a CategoryId for ga.catId.');
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
     * @return list<array{user_id: int, group_id: int}>
     */
    public function findMembersByGroupIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ug.userId', 'ug.groupId')
            ->from(UserGroupEntity::class, 'ug')
            ->where('ug.groupId IN (:groupIds)')
            ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        // ug.userId/ug.groupId are custom-typed columns (user_id/group_id) --
        // a partial-field DQL select hydrates them into real UserId/GroupId
        // VOs, not raw ints, so this extracts ->value explicitly with an
        // instanceof check rather than an is_numeric()-style raw-DBAL row
        // check (Gotcha #1). Callers (CatPermPageRenderer,
        // AlbumNotificationPageRenderer) expect raw scalars under the
        // 'user_id'/'group_id' keys, unchanged from the DBAL shape.
        $result = [];
        foreach ($rows as $row) {
            if (! $row['userId'] instanceof UserId || ! $row['groupId'] instanceof GroupId) {
                continue;
            }

            $result[] = [
                'user_id' => $row['userId']->value,
                'group_id' => $row['groupId']->value,
            ];
        }

        return $result;
    }

    /**
     * Raw (user_id, group_id) membership pairs for every id in $userIds --
     * Ws\Users::getList()'s own "which groups does each returned user
     * belong to" step, the reverse direction of
     * {@see findMembersByGroupIds()} above (that one is keyed by group,
     * this one by user).
     *
     * @param  list<int>  $userIds
     * @return list<array{user_id: int, group_id: int}>
     */
    public function findMembershipsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ug.userId', 'ug.groupId')
            ->from(UserGroupEntity::class, 'ug')
            ->where('ug.userId IN (:userIds)')
            ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        // Same VO-hydration caveat as findMembersByGroupIds() above
        // (Gotcha #1) -- ug.userId/ug.groupId hydrate into real
        // UserId/GroupId VOs under a partial-field DQL select, so this
        // extracts ->value explicitly with an instanceof check. Callers
        // (Ws\Users::getList()) expect raw scalars under the
        // 'user_id'/'group_id' keys, unchanged from the DBAL shape.
        $result = [];
        foreach ($rows as $row) {
            if (! $row['userId'] instanceof UserId || ! $row['groupId'] instanceof GroupId) {
                continue;
            }

            $result[] = [
                'user_id' => $row['userId']->value,
                'group_id' => $row['groupId']->value,
            ];
        }

        return $result;
    }

    /**
     * Total row count of `groups` -- Admin\InstallationStats's own
     * "nb_groups" summary figure.
     */
    public function countAll(): int
    {
        $value = $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->getQuery()
            ->getSingleScalarResult();

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

        $rows = $this->createQueryBuilder('g')
            ->select('g.id', 'g.name')
            ->where('g.id IN (:groupIds)')
            ->orderBy('g.name', 'ASC')
            ->setParameter('groupIds', $groupIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        // g.id is the custom `group_id` Doctrine Type -- a partial-field DQL
        // select hydrates it into a real GroupId VO, not a raw int, so this
        // uses an instanceof check rather than the previous is_numeric()
        // raw-DBAL row check (Gotcha #1). g.name is a
        // plain `string` column -- phpstan-doctrine's own DQL metadata
        // already narrows $row['name'] to string, so no is_string() guard
        // is needed for it (unlike the id field above).
        $byId = [];
        foreach ($rows as $row) {
            if (! $row['id'] instanceof GroupId) {
                continue;
            }

            $byId[$row['id']->value] = $row['name'];
        }

        return $byId;
    }
}
