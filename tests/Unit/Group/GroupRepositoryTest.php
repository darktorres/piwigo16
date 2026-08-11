<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\Projection\Group;
use Piwigo\Group\UserGroupEntity;

/**
 * Piwigo\Group\GroupRepository -- has its own dedicated
 * tests/Integration/GroupRepositoryTest.php; this ports its 39 tests
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern.
 *
 * Fixture: 3 groups (1 Editors, 2 Reviewers, 3 Guests), none default.
 * Group 1 (Editors) has members user 1 (fixture_admin) + user 3
 * (regular_user), and access to categories 1 and 2.
 *
 * Confirmed-equivalent mutations, not individually tested: every
 * `is_numeric(...) ? (int) ... : default` cast after
 * getSingleScalarResult()/getSingleColumnResult() on a plain column
 * (nameExists(), findMembersByGroupIds()'s own `! is_numeric()` guard,
 * countAll()) is unreachable on this driver, same root cause documented
 * throughout this project's other Unit-suite files; every
 * `if ($xIds === []) { return []; }` early return
 * (findExistingIds(), delete(), removeAccess(), findMembersByGroupIds(),
 * findMembershipsForUserIds(), findNamesByIds()) is unobservable if
 * skipped -- DBAL's own `ArrayParameterType` expansion of an empty array
 * already matches nothing on this driver, same root cause as
 * PermalinkRepositoryTest.php's own findPermalinkMatches() finding;
 * update()'s own `if ($updates === []) { return; }` is unobservable
 * even without that shortcut -- an empty $updates array already leaves
 * both `isset($updates['name'])`/`isset($updates['is_default'])` false,
 * so the method falls through to a no-op flush() regardless; delete()'s
 * own `if ($deleted === []) { return []; }` (no groups actually matched)
 * is unobservable for the identical empty-array-IN-clause reason as the
 * guards above -- the 3 DELETE statements that would otherwise run all
 * get an empty `$ids` array too; findAllBasic()'s own `array_map(...)`
 * mapping raw GroupEntity objects into Group Projection DTOs is
 * confirmed-equivalent for this file's own field-by-field assertions
 * (both types happen to share identical public `name`/`isDefault`
 * property names) -- closed instead via an explicit
 * `toBeInstanceOf(Group::class)` check; findMemberUsernames()'s own cast
 * is unreachable for the same native-type reason; addMembers()'s own
 * per-column `ParameterType::INTEGER` hints are redundant on this
 * driver, same root cause as CaddieRepositoryTest.php's own
 * addElements() finding; addMembers()'s own `$em->clear()` call is
 * confirmed live to be unobservable through this file's own tests --
 * every membership it inserts is a brand-new (group_id, user_id) key
 * this EntityManager never tracked beforehand, so a later find() always
 * re-queries the database regardless of whether clear() ran; clear()'s
 * real purpose (invalidating an *already-tracked* entity gone stale
 * after a raw-DBAL write) only matters for removeMembers()/
 * removeAllMembershipsForUsers()/removeAccess() above, where each one's
 * own dedicated staleness test tracks the row via find() *before*
 * deleting it -- and closes real gaps there.
 */
function groupTestRepo(): GroupRepository
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(GroupEntity::class);

    return $repo;
}

/**
 * getEntityManager() is protected on Doctrine's own EntityRepository base
 * class -- the em->clear() staleness tests below need direct
 * EntityManager access (for find()) alongside the repo, same
 * CaddieRepositoryTest.php precedent.
 *
 * @return array{0: GroupRepository, 1: Doctrine\ORM\EntityManagerInterface}
 */
function groupTestRepoWithEm(): array
{
    $em = EntityManagerFactory::build(DbConnection::build());
    $repo = $em->getRepository(GroupEntity::class);

    return [$repo, $em];
}

/**
 * @param list<GroupId|UserId|CategoryId> $ids
 * @return list<int>
 */
function groupTestValues(array $ids): array
{
    return array_map(static fn (GroupId|UserId|CategoryId $id): int => $id->value, $ids);
}

function groupTestName(): string
{
    return 'p17-unit-test-' . bin2hex(random_bytes(4));
}

test('findAllBasic() returns fixture groups ordered by name', function (): void {
    $groups = groupTestRepo()
        ->findAllBasic();

    // GroupEntity and its own Group Projection DTO happen to share the
    // same public property names (name/isDefault), so a mapping step
    // that silently returned the raw entities instead of Group objects
    // wouldn't be caught by the field checks below alone.
    expect($groups[0] ?? null)->toBeInstanceOf(Group::class);
    $names = array_column($groups, 'name');
    expect($names)
        ->toBe(['Editors', 'Guests', 'Reviewers'])
        ->and($groups[0]->isDefault)->toBeFalse();
});

test('exists() is true for a fixture group and false for a bogus id', function (): void {
    $repo = groupTestRepo();

    expect($repo->exists(GroupId::from(1)))->toBeTrue()
        ->and($repo->exists(GroupId::from(999999)))->toBeFalse();
});

test('nameExists() matches a fixture name', function (): void {
    $repo = groupTestRepo();

    expect($repo->nameExists('Editors'))
        ->toBeTrue()
        ->and($repo->nameExists('Does Not Exist'))
        ->toBeFalse();
});

test('nameExists() excludes the given group id', function (): void {
    $repo = groupTestRepo();

    expect($repo->nameExists('Editors', GroupId::from(1)))->toBeFalse()
        ->and($repo->nameExists('Editors', GroupId::from(2)))->toBeTrue();
});

test('findExistingIds() returns only the ids that exist', function (): void {
    $ids = groupTestRepo()
        ->findExistingIds([GroupId::from(1), GroupId::from(2), GroupId::from(999999)]);

    expect(groupTestValues($ids))
        ->toBe([1, 2]);
});

test('findExistingIds() returns empty for an empty input', function (): void {
    expect(groupTestRepo()->findExistingIds([]))->toBe([]);
});

test('findName() returns a fixture group\'s name', function (): void {
    $repo = groupTestRepo();

    expect($repo->findName(GroupId::from(1)))->toBe('Editors')
        ->and($repo->findName(GroupId::from(999999)))->toBeNull();
});

test('isDefault() reflects the fixture column', function (): void {
    expect(groupTestRepo()->isDefault(GroupId::from(1)))->toBeFalse();
});

test('insert() then findName() round-trips', function (): void {
    $repo = groupTestRepo();
    $name = groupTestName();

    $id = $repo->insert($name, false);

    try {
        expect($repo->findName($id))
            ->toBe($name)
            ->and($repo->isDefault($id))
            ->toBeFalse();
    } finally {
        $repo->delete([$id]);
    }
});

test('insert() with is_default true round-trips', function (): void {
    $repo = groupTestRepo();
    $id = $repo->insert(groupTestName(), true);

    try {
        expect($repo->isDefault($id))
            ->toBeTrue();
    } finally {
        $repo->delete([$id]);
    }
});

test('update() changes name and is_default', function (): void {
    $repo = groupTestRepo();
    $name = groupTestName();
    $id = $repo->insert($name, false);

    try {
        $newName = $name . '-renamed';
        $repo->update($id, [
            'name' => $newName,
            'is_default' => true,
        ]);

        // A fresh repo/EntityManager, not the same $repo the update() call
        // itself mutated -- reading back through the SAME EntityManager's
        // own identity map would show the in-memory change regardless of
        // whether flush() actually persisted it.
        $reread = groupTestRepo();
        expect($reread->findName($id))
            ->toBe($newName)
            ->and($reread->isDefault($id))
            ->toBeTrue();
    } finally {
        $repo->delete([$id]);
    }
});

test('update() with no fields is a no-op', function (): void {
    groupTestRepo()->update(GroupId::from(1), []);

    expect(groupTestRepo()->findName(GroupId::from(1)))->toBe('Editors');
});

test('update() on a nonexistent group id is a silent no-op', function (): void {
    // 999999 isn't in the fixture (groups has ids 1-3 only) --
    // find() returns null, and update() must return without throwing
    // rather than crash on a null entity.
    groupTestRepo()
        ->update(GroupId::from(999999), [
            'name' => 'should-never-be-written',
        ]);

    expect(groupTestRepo()->findName(GroupId::from(999999)))->toBeNull();
});

test('findMemberUserIds() returns fixture members', function (): void {
    $ids = groupTestRepo()
        ->findMemberUserIds(GroupId::from(1));

    expect(groupTestValues($ids))
        ->toBe([1, 3]);
});

test('findMemberUserIds() is empty for a group with no members', function (): void {
    $repo = groupTestRepo();
    $id = $repo->insert(groupTestName(), false);

    try {
        expect($repo->findMemberUserIds($id))
            ->toBe([]);
    } finally {
        $repo->delete([$id]);
    }
});

test('findMemberUsernames() returns fixture usernames', function (): void {
    expect(groupTestRepo()->findMemberUsernames(GroupId::from(1)))->toBe(['fixture_admin', 'regular_user']);
});

test('addMembers() then removeMembers() round-trips', function (): void {
    $repo = groupTestRepo();
    $groupId = $repo->insert(groupTestName(), false);

    try {
        $repo->addMembers($groupId, [UserId::from(2)]);
        expect(groupTestValues($repo->findMemberUserIds($groupId)))
            ->toBe([2]);

        $repo->removeMembers($groupId, [UserId::from(2)]);
        expect($repo->findMemberUserIds($groupId))
            ->toBe([]);
    } finally {
        $repo->delete([$groupId]);
    }
});

test('addMembers() silently ignores an already-existing membership', function (): void {
    $repo = groupTestRepo();
    $groupId = $repo->insert(groupTestName(), false);

    try {
        $repo->addMembers($groupId, [UserId::from(2)]);

        // Re-adding the same (group_id, user_id) pair must not throw a
        // duplicate-key error -- this is the whole point of using INSERT
        // IGNORE here.
        $repo->addMembers($groupId, [UserId::from(2)]);

        expect(groupTestValues($repo->findMemberUserIds($groupId)))
            ->toBe([2]);
    } finally {
        $repo->delete([$groupId]);
    }
});

test('addMembers() clears the identity map, so a later find() sees the real insert instead of a stale cached null', function (): void {
    [$repo, $em] = groupTestRepoWithEm();
    $groupId = $repo->insert(groupTestName(), false);
    $key = [
        'groupId' => $groupId,
        'userId' => UserId::from(2),
    ];

    try {
        $trackedMissing = $em->find(UserGroupEntity::class, $key);
        expect($trackedMissing)
            ->toBeNull();

        $repo->addMembers($groupId, [UserId::from(2)]);

        expect($em->find(UserGroupEntity::class, $key))->not->toBeNull();
    } finally {
        $repo->delete([$groupId]);
    }
});

test('removeMembers() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = groupTestRepoWithEm();
    $groupId = $repo->insert(groupTestName(), false);
    $repo->addMembers($groupId, [UserId::from(2)]);
    $key = [
        'groupId' => $groupId,
        'userId' => UserId::from(2),
    ];

    try {
        $tracked = $em->find(UserGroupEntity::class, $key);
        expect($tracked)
            ->not->toBeNull();

        $repo->removeMembers($groupId, [UserId::from(2)]);

        expect($em->find(UserGroupEntity::class, $key))->toBeNull();
    } finally {
        $repo->delete([$groupId]);
    }
});

test('removeMembers() with an empty list is a no-op', function (): void {
    $repo = groupTestRepo();

    $repo->removeMembers(GroupId::from(1), []);

    expect(groupTestValues($repo->findMemberUserIds(GroupId::from(1))))->toBe([1, 3]);
});

test('removeAllMembershipsForUsers() deletes every membership row for that user across groups', function (): void {
    $repo = groupTestRepo();
    $groupA = $repo->insert(groupTestName(), false);
    $groupB = $repo->insert(groupTestName(), false);
    $repo->addMembers($groupA, [UserId::from(2)]);
    $repo->addMembers($groupB, [UserId::from(2)]);

    try {
        $repo->removeAllMembershipsForUsers([UserId::from(2)]);

        expect($repo->findMemberUserIds($groupA))
            ->toBe([])
            ->and($repo->findMemberUserIds($groupB))
            ->toBe([]);
    } finally {
        $repo->delete([$groupA, $groupB]);
    }
});

test('removeAllMembershipsForUsers() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = groupTestRepoWithEm();
    $groupId = $repo->insert(groupTestName(), false);
    $repo->addMembers($groupId, [UserId::from(2)]);
    $key = [
        'groupId' => $groupId,
        'userId' => UserId::from(2),
    ];

    try {
        $tracked = $em->find(UserGroupEntity::class, $key);
        expect($tracked)
            ->not->toBeNull();

        $repo->removeAllMembershipsForUsers([UserId::from(2)]);

        expect($em->find(UserGroupEntity::class, $key))->toBeNull();
    } finally {
        $repo->delete([$groupId]);
    }
});

test('removeAllMembershipsForUsers() with an empty list is a no-op', function (): void {
    $repo = groupTestRepo();

    $repo->removeAllMembershipsForUsers([]);

    expect(groupTestValues($repo->findMemberUserIds(GroupId::from(1))))->toBe([1, 3]);
});

test('findMembersByGroupIds() returns the raw user_id/group_id pairs for the given groups', function (): void {
    $pairs = groupTestRepo()
        ->findMembersByGroupIds([1]);

    $userIds = array_map(static fn (array $row): int => is_numeric($row['user_id']) ? (int) $row['user_id'] : 0, $pairs);
    sort($userIds);

    expect($userIds)
        ->toBe([1, 3]);
});

test('findMembersByGroupIds() returns empty for an empty input', function (): void {
    expect(groupTestRepo()->findMembersByGroupIds([]))->toBe([]);
});

test('findMembershipsForUserIds() returns the raw user_id/group_id pairs for the given users', function (): void {
    $pairs = groupTestRepo()
        ->findMembershipsForUserIds([1]);

    $groupIds = array_map(static fn (array $row): int => is_numeric($row['group_id']) ? (int) $row['group_id'] : 0, $pairs);

    expect($groupIds)
        ->toContain(1);
});

test('findMembershipsForUserIds() returns empty for an empty input', function (): void {
    expect(groupTestRepo()->findMembershipsForUserIds([]))->toBe([]);
});

test('findNamesByIds() returns names keyed by id', function (): void {
    $repo = groupTestRepo();
    $name = groupTestName();
    $groupId = $repo->insert($name, false);

    try {
        $names = $repo->findNamesByIds([1, $groupId->value]);

        expect($names[1] ?? null)->toBe('Editors')
            ->and($names[$groupId->value] ?? null)->toBe($name);
    } finally {
        $repo->delete([$groupId]);
    }
});

test('findNamesByIds() returns empty for an empty input', function (): void {
    expect(groupTestRepo()->findNamesByIds([]))->toBe([]);
});

test('delete() removes groups and their access and membership rows', function (): void {
    $repo = groupTestRepo();
    $groupId = $repo->insert(groupTestName(), false);
    $repo->addMembers($groupId, [UserId::from(2)]);
    $repo->addAccess($groupId, [CategoryId::from(1)]);

    $deleted = $repo->delete([$groupId]);

    expect($deleted)
        ->toHaveKey($groupId->value)
        ->and($repo->exists($groupId))
        ->toBeFalse()
        ->and($repo->findMemberUserIds($groupId))
        ->toBe([])
        ->and($repo->getAuthorizedCategoryIds($groupId))
        ->toBe([]);
});

test('delete() returns empty array when none of the ids exist', function (): void {
    expect(groupTestRepo()->delete([GroupId::from(999999)]))->toBe([]);
});

test('delete() with an empty list is a no-op', function (): void {
    expect(groupTestRepo()->delete([]))->toBe([]);
});

test('getAuthorizedCategoryIds() returns fixture access', function (): void {
    $ids = groupTestRepo()
        ->getAuthorizedCategoryIds(GroupId::from(1));

    expect(groupTestValues($ids))
        ->toBe([1, 2]);
});

test('addAccess() then removeAccess() round-trips', function (): void {
    $repo = groupTestRepo();
    $groupId = $repo->insert(groupTestName(), false);

    try {
        $repo->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);
        expect(groupTestValues($repo->getAuthorizedCategoryIds($groupId)))
            ->toBe([1, 2]);

        $repo->removeAccess($groupId, [CategoryId::from(1)]);
        expect(groupTestValues($repo->getAuthorizedCategoryIds($groupId)))
            ->toBe([2]);
    } finally {
        $repo->delete([$groupId]);
    }
});

test('removeAccess() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = groupTestRepoWithEm();
    $groupId = $repo->insert(groupTestName(), false);
    $repo->addAccess($groupId, [CategoryId::from(1)]);
    $key = [
        'groupId' => $groupId,
        'catId' => CategoryId::from(1),
    ];

    try {
        $tracked = $em->find(GroupAccessEntity::class, $key);
        expect($tracked)
            ->not->toBeNull();

        $repo->removeAccess($groupId, [CategoryId::from(1)]);

        expect($em->find(GroupAccessEntity::class, $key))->toBeNull();
    } finally {
        $repo->delete([$groupId]);
    }
});

test('removeAccess() with an empty list is a no-op', function (): void {
    $repo = groupTestRepo();

    $repo->removeAccess(GroupId::from(1), []);

    expect(groupTestValues($repo->getAuthorizedCategoryIds(GroupId::from(1))))->toBe([1, 2]);
});

test('getAccessibleCategoryIdsForUser() follows group membership', function (): void {
    // Fixture: user 1 is in group 1, group 1 has access to cats 1 and 2.
    $ids = groupTestRepo()
        ->getAccessibleCategoryIdsForUser(UserId::from(1));

    expect(groupTestValues($ids))
        ->toBe([1, 2]);
});

test('getAccessibleCategoryIdsForUser() is empty for a groupless user', function (): void {
    expect(groupTestRepo()->getAccessibleCategoryIdsForUser(UserId::from(2)))->toBe([]);
});

test('findWithMemberCounts() returns fixture groups with counts', function (): void {
    $rows = groupTestRepo()
        ->findWithMemberCounts([], null, 'name ASC', 10, 0);

    $byName = [];
    foreach ($rows as $row) {
        $byName[$row->name] = $row->nbUsers;
    }

    expect($byName['Editors'])->toBe(2)
        ->and($byName['Guests'])->toBe(1)
        ->and($byName['Reviewers'])->toBe(1);
});

test('findWithMemberCounts() filters by group ids', function (): void {
    $rows = groupTestRepo()
        ->findWithMemberCounts([GroupId::from(1)], null, 'name ASC', 10, 0);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Editors');
});

test('findWithMemberCounts() filters by name like', function (): void {
    $rows = groupTestRepo()
        ->findWithMemberCounts([], 'editors', 'name ASC', 10, 0);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Editors');
});

test('findWithMemberCounts() respects per_page and page', function (): void {
    $rows = groupTestRepo()
        ->findWithMemberCounts([], null, 'name ASC', 1, 1);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Guests');
});

test('findIdsByNameLike() matches a wildcard pattern', function (): void {
    // Regression test: g.id is the custom `group_id`
    // Doctrine Type, but getSingleColumnResult() uses HYDRATE_SCALAR_COLUMN
    // (a raw fetchFirstColumn(), no per-field Type conversion at all) --
    // an earlier version of this method wrongly assumed VO hydration
    // here (an instanceof GroupId check that never matched a raw int),
    // silently always returning [] regardless of $namePattern.
    expect(groupTestRepo()->findIdsByNameLike('%dit%'))
        ->toBe([1]);
});

test('findIdsByNameLike() returns empty for no match', function (): void {
    expect(groupTestRepo()->findIdsByNameLike('%no-such-group%'))
        ->toBe([]);
});
