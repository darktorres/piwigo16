<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;

final class GroupRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private GroupRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class);
    }

    /**
     * @param list<GroupId|UserId|CategoryId> $ids
     * @return list<int>
     */
    private static function values(array $ids): array
    {
        return array_map(static fn (GroupId|UserId|CategoryId $id): int => $id->value, $ids);
    }

    public function testFindAllBasicReturnsFixtureGroupsOrderedByName(): void
    {
        $groups = $this->repo->findAllBasic();

        $names = array_column($groups, 'name');
        self::assertSame(['Editors', 'Guests', 'Reviewers'], $names);
        self::assertFalse($groups[0]->isDefault);
    }

    public function testExistsIsTrueForAFixtureGroupAndFalseForABogusId(): void
    {
        self::assertTrue($this->repo->exists(GroupId::from(1)));
        self::assertFalse($this->repo->exists(GroupId::from(999999)));
    }

    public function testNameExistsMatchesAFixtureName(): void
    {
        self::assertTrue($this->repo->nameExists('Editors'));
        self::assertFalse($this->repo->nameExists('Does Not Exist'));
    }

    public function testNameExistsExcludesTheGivenGroupId(): void
    {
        self::assertFalse($this->repo->nameExists('Editors', GroupId::from(1)));
        self::assertTrue($this->repo->nameExists('Editors', GroupId::from(2)));
    }

    public function testFindExistingIdsReturnsOnlyTheIdsThatExist(): void
    {
        $ids = $this->repo->findExistingIds([GroupId::from(1), GroupId::from(2), GroupId::from(999999)]);

        self::assertSame([1, 2], self::values($ids));
    }

    public function testFindExistingIdsReturnsEmptyForAnEmptyInput(): void
    {
        self::assertSame([], $this->repo->findExistingIds([]));
    }

    public function testFindNameReturnsAFixtureGroupsName(): void
    {
        self::assertSame('Editors', $this->repo->findName(GroupId::from(1)));
        self::assertNull($this->repo->findName(GroupId::from(999999)));
    }

    public function testIsDefaultReflectsTheFixtureColumn(): void
    {
        self::assertFalse($this->repo->isDefault(GroupId::from(1)));
    }

    public function testInsertThenFindNameRoundTrips(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insert($name, false);

        self::assertSame($name, $this->repo->findName($id));
        self::assertFalse($this->repo->isDefault($id));

        $this->repo->delete([$id]);
    }

    public function testInsertWithIsDefaultTrueRoundTrips(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insert($name, true);

        self::assertTrue($this->repo->isDefault($id));

        $this->repo->delete([$id]);
    }

    public function testUpdateChangesNameAndIsDefault(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insert($name, false);

        $newName = $name . '-renamed';
        $this->repo->update($id, [
            'name' => $newName,
            'is_default' => true,
        ]);

        self::assertSame($newName, $this->repo->findName($id));
        self::assertTrue($this->repo->isDefault($id));

        $this->repo->delete([$id]);
    }

    public function testUpdateWithNoFieldsIsANoop(): void
    {
        $this->repo->update(GroupId::from(1), []);

        self::assertSame('Editors', $this->repo->findName(GroupId::from(1)));
    }

    public function testUpdateOnANonexistentGroupIdIsASilentNoop(): void
    {
        // 999999 isn't in the fixture (groups has ids 1-3 only) --
        // find() returns null, and update() must return without throwing
        // rather than crash on a null entity.
        $this->repo->update(GroupId::from(999999), [
            'name' => 'should-never-be-written',
        ]);

        self::assertNull($this->repo->findName(GroupId::from(999999)));
    }

    public function testFindMemberUserIdsReturnsFixtureMembers(): void
    {
        $ids = $this->repo->findMemberUserIds(GroupId::from(1));

        self::assertSame([1, 3], self::values($ids));
    }

    public function testFindMemberUserIdsIsEmptyForAGroupWithNoMembers(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insert($name, false);

        self::assertSame([], $this->repo->findMemberUserIds($id));

        $this->repo->delete([$id]);
    }

    public function testFindMemberUsernamesReturnsFixtureUsernames(): void
    {
        $names = $this->repo->findMemberUsernames(GroupId::from(1));

        self::assertSame(['fixture_admin', 'regular_user'], $names);
    }

    public function testAddMembersThenRemoveMembersRoundTrips(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);

        $this->repo->addMembers($groupId, [UserId::from(2)]);
        self::assertSame([2], self::values($this->repo->findMemberUserIds($groupId)));

        $this->repo->removeMembers($groupId, [UserId::from(2)]);
        self::assertSame([], $this->repo->findMemberUserIds($groupId));

        $this->repo->delete([$groupId]);
    }

    public function testAddMembersSilentlyIgnoresAnAlreadyExistingMembership(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);
        $this->repo->addMembers($groupId, [UserId::from(2)]);

        // Re-adding the same (group_id, user_id) pair must not throw a
        // duplicate-key error -- this is the whole point of using INSERT
        // IGNORE here.
        $this->repo->addMembers($groupId, [UserId::from(2)]);

        self::assertSame([2], self::values($this->repo->findMemberUserIds($groupId)));

        $this->repo->delete([$groupId]);
    }

    public function testRemoveMembersWithAnEmptyListIsANoop(): void
    {
        $this->repo->removeMembers(GroupId::from(1), []);

        self::assertSame([1, 3], self::values($this->repo->findMemberUserIds(GroupId::from(1))));
    }

    public function testRemoveAllMembershipsForUsersDeletesEveryMembershipRowForThatUserAcrossGroups(): void
    {
        $groupA = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);
        $groupB = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);
        $this->repo->addMembers($groupA, [UserId::from(2)]);
        $this->repo->addMembers($groupB, [UserId::from(2)]);

        $this->repo->removeAllMembershipsForUsers([UserId::from(2)]);

        self::assertSame([], $this->repo->findMemberUserIds($groupA));
        self::assertSame([], $this->repo->findMemberUserIds($groupB));

        $this->repo->delete([$groupA, $groupB]);
    }

    public function testRemoveAllMembershipsForUsersWithAnEmptyListIsANoop(): void
    {
        $this->repo->removeAllMembershipsForUsers([]);

        self::assertSame([1, 3], self::values($this->repo->findMemberUserIds(GroupId::from(1))));
    }

    public function testFindMembersByGroupIdsReturnsTheRawUserIdGroupIdPairsForTheGivenGroups(): void
    {
        $pairs = $this->repo->findMembersByGroupIds([1]);

        $userIds = array_map(static fn (array $row): int => $row['user_id'], $pairs);
        sort($userIds);
        self::assertSame([1, 3], $userIds);
    }

    public function testFindMembersByGroupIdsReturnsEmptyForAnEmptyInput(): void
    {
        self::assertSame([], $this->repo->findMembersByGroupIds([]));
    }

    public function testFindMembershipsForUserIdsReturnsTheRawUserIdGroupIdPairsForTheGivenUsers(): void
    {
        $pairs = $this->repo->findMembershipsForUserIds([1]);

        $groupIds = array_map(static fn (array $row): int => $row['group_id'], $pairs);
        self::assertContains(1, $groupIds);
    }

    public function testFindMembershipsForUserIdsReturnsEmptyForAnEmptyInput(): void
    {
        self::assertSame([], $this->repo->findMembershipsForUserIds([]));
    }

    public function testFindNamesByIdsReturnsNamesKeyedById(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));
        $groupId = $this->repo->insert($name, false);

        $names = $this->repo->findNamesByIds([1, $groupId->value]);

        self::assertSame('Editors', $names[1] ?? null);
        self::assertSame($name, $names[$groupId->value] ?? null);

        $this->repo->delete([$groupId]);
    }

    public function testFindNamesByIdsReturnsEmptyForAnEmptyInput(): void
    {
        self::assertSame([], $this->repo->findNamesByIds([]));
    }

    // findNamesByIds()'s own `! is_numeric($row['id']) || ! is_string($row['name'])`
    // `continue` guard is not chased here -- same "id is a native-int
    // NOT NULL AUTO_INCREMENT primary key, name is a native-string NOT
    // NULL column" reasoning already documented for this file's sibling
    // guards (`groups`.`id`/`groups`.`name`, tests/Fixtures/piwigo-17.0.sql),
    // so it's unreachable through any real fetched row. Also
    // GroupRepository::getAccessibleCategoryIdsForUser()'s own
    // `! $row['catId'] instanceof CategoryId` `throw` (a couple hundred
    // lines up) is already documented dead code right at its own call
    // site -- a pure DQL-scalar-hydration narrowing guard PHPStan can't
    // otherwise verify statically.

    public function testDeleteRemovesGroupsAndTheirAccessAndMembershipRows(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);
        $this->repo->addMembers($groupId, [UserId::from(2)]);
        $this->repo->addAccess($groupId, [CategoryId::from(1)]);

        $deleted = $this->repo->delete([$groupId]);

        self::assertArrayHasKey($groupId->value, $deleted);
        self::assertFalse($this->repo->exists($groupId));
        self::assertSame([], $this->repo->findMemberUserIds($groupId));
        self::assertSame([], $this->repo->getAuthorizedCategoryIds($groupId));
    }

    public function testDeleteReturnsEmptyArrayWhenNoneOfTheIdsExist(): void
    {
        self::assertSame([], $this->repo->delete([GroupId::from(999999)]));
    }

    public function testDeleteWithAnEmptyListIsANoop(): void
    {
        self::assertSame([], $this->repo->delete([]));
    }

    public function testGetAuthorizedCategoryIdsReturnsFixtureAccess(): void
    {
        $ids = $this->repo->getAuthorizedCategoryIds(GroupId::from(1));

        self::assertSame([1, 2], self::values($ids));
    }

    public function testAddAccessThenRemoveAccessRoundTrips(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);

        $this->repo->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);
        self::assertSame([1, 2], self::values($this->repo->getAuthorizedCategoryIds($groupId)));

        $this->repo->removeAccess($groupId, [CategoryId::from(1)]);
        self::assertSame([2], self::values($this->repo->getAuthorizedCategoryIds($groupId)));

        $this->repo->delete([$groupId]);
    }

    public function testRemoveAccessWithAnEmptyListIsANoop(): void
    {
        $this->repo->removeAccess(GroupId::from(1), []);

        self::assertSame([1, 2], self::values($this->repo->getAuthorizedCategoryIds(GroupId::from(1))));
    }

    public function testGetAccessibleCategoryIdsForUserFollowsGroupMembership(): void
    {
        // Fixture: user 1 is in group 1, group 1 has access to cats 1 and 2.
        $ids = $this->repo->getAccessibleCategoryIdsForUser(UserId::from(1));

        self::assertSame([1, 2], self::values($ids));
    }

    public function testGetAccessibleCategoryIdsForUserIsEmptyForAGrouplessUser(): void
    {
        self::assertSame([], $this->repo->getAccessibleCategoryIdsForUser(UserId::from(2)));
    }

    public function testFindWithMemberCountsReturnsFixtureGroupsWithCounts(): void
    {
        $rows = $this->repo->findWithMemberCounts([], null, 'name ASC', 10, 0);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] = $row->nbUsers;
        }

        self::assertSame(2, $byName['Editors']);
        self::assertSame(1, $byName['Guests']);
        self::assertSame(1, $byName['Reviewers']);
    }

    public function testFindWithMemberCountsFiltersByGroupIds(): void
    {
        $rows = $this->repo->findWithMemberCounts([GroupId::from(1)], null, 'name ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertSame('Editors', $rows[0]->name);
    }

    public function testFindWithMemberCountsFiltersByNameLike(): void
    {
        $rows = $this->repo->findWithMemberCounts([], 'editors', 'name ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertSame('Editors', $rows[0]->name);
    }

    public function testFindWithMemberCountsRespectsPerPageAndPage(): void
    {
        $rows = $this->repo->findWithMemberCounts([], null, 'name ASC', 1, 1);

        self::assertCount(1, $rows);
        self::assertSame('Guests', $rows[0]->name);
    }

    public function testFindIdsByNameLikeMatchesAWildcardPattern(): void
    {
        // g.id is the custom `group_id` Doctrine Type, but
        // getSingleColumnResult() uses HYDRATE_SCALAR_COLUMN (a raw
        // fetchFirstColumn(), no per-field Type conversion at all) -- an
        // instanceof GroupId check here would never match a raw int,
        // silently always returning [] regardless of $namePattern.
        self::assertSame([1], $this->repo->findIdsByNameLike('%dit%'));
    }

    public function testFindIdsByNameLikeReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findIdsByNameLike('%no-such-group%'));
    }
}
