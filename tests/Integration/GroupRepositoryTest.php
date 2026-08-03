<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;

final class GroupRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private GroupRepository $repo;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class);
    }

    /**
     * @param list<GroupId|UserId|CategoryId> $ids
     * @return list<int>
     */
    private static function values(array $ids): array
    {
        return array_map(static fn (GroupId|UserId|CategoryId $id): int => $id->value, $ids);
    }

    public function test_find_all_basic_returns_fixture_groups_ordered_by_name(): void
    {
        $groups = $this->repo->findAllBasic();

        $names = array_column($groups, 'name');
        self::assertSame(['Editors', 'Guests', 'Reviewers'], $names);
        self::assertFalse($groups[0]->isDefault);
    }

    public function test_exists_is_true_for_a_fixture_group_and_false_for_a_bogus_id(): void
    {
        self::assertTrue($this->repo->exists(GroupId::from(1)));
        self::assertFalse($this->repo->exists(GroupId::from(999999)));
    }

    public function test_name_exists_matches_a_fixture_name(): void
    {
        self::assertTrue($this->repo->nameExists('Editors'));
        self::assertFalse($this->repo->nameExists('Does Not Exist'));
    }

    public function test_name_exists_excludes_the_given_group_id(): void
    {
        self::assertFalse($this->repo->nameExists('Editors', GroupId::from(1)));
        self::assertTrue($this->repo->nameExists('Editors', GroupId::from(2)));
    }

    public function test_find_existing_ids_returns_only_the_ids_that_exist(): void
    {
        $ids = $this->repo->findExistingIds([GroupId::from(1), GroupId::from(2), GroupId::from(999999)]);

        self::assertSame([1, 2], self::values($ids));
    }

    public function test_find_existing_ids_returns_empty_for_an_empty_input(): void
    {
        self::assertSame([], $this->repo->findExistingIds([]));
    }

    public function test_find_name_returns_a_fixture_groups_name(): void
    {
        self::assertSame('Editors', $this->repo->findName(GroupId::from(1)));
        self::assertNull($this->repo->findName(GroupId::from(999999)));
    }

    public function test_is_default_reflects_the_fixture_column(): void
    {
        self::assertFalse($this->repo->isDefault(GroupId::from(1)));
    }

    public function test_insert_then_find_name_round_trips(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insert($name, false);

        self::assertSame($name, $this->repo->findName($id));
        self::assertFalse($this->repo->isDefault($id));

        $this->repo->delete([$id]);
    }

    public function test_insert_with_is_default_true_round_trips(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insert($name, true);

        self::assertTrue($this->repo->isDefault($id));

        $this->repo->delete([$id]);
    }

    public function test_update_changes_name_and_is_default(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insert($name, false);

        $newName = $name . '-renamed';
        $this->repo->update($id, ['name' => $newName, 'is_default' => true]);

        self::assertSame($newName, $this->repo->findName($id));
        self::assertTrue($this->repo->isDefault($id));

        $this->repo->delete([$id]);
    }

    public function test_update_with_no_fields_is_a_noop(): void
    {
        $this->repo->update(GroupId::from(1), []);

        self::assertSame('Editors', $this->repo->findName(GroupId::from(1)));
    }

    public function test_update_on_a_nonexistent_group_id_is_a_silent_noop(): void
    {
        // 999999 isn't in the fixture (piwigo_groups has ids 1-3 only) --
        // find() returns null, and update() must return without throwing
        // rather than crash on a null entity.
        $this->repo->update(GroupId::from(999999), ['name' => 'should-never-be-written']);

        self::assertNull($this->repo->findName(GroupId::from(999999)));
    }

    public function test_find_member_user_ids_returns_fixture_members(): void
    {
        $ids = $this->repo->findMemberUserIds(GroupId::from(1));

        self::assertSame([1, 3], self::values($ids));
    }

    public function test_find_member_user_ids_is_empty_for_a_group_with_no_members(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insert($name, false);

        self::assertSame([], $this->repo->findMemberUserIds($id));

        $this->repo->delete([$id]);
    }

    public function test_find_member_usernames_returns_fixture_usernames(): void
    {
        $names = $this->repo->findMemberUsernames(GroupId::from(1));

        self::assertSame(['fixture_admin', 'regular_user'], $names);
    }

    public function test_add_members_then_remove_members_round_trips(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);

        $this->repo->addMembers($groupId, [UserId::from(2)]);
        self::assertSame([2], self::values($this->repo->findMemberUserIds($groupId)));

        $this->repo->removeMembers($groupId, [UserId::from(2)]);
        self::assertSame([], $this->repo->findMemberUserIds($groupId));

        $this->repo->delete([$groupId]);
    }

    public function test_add_members_silently_ignores_an_already_existing_membership(): void
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

    public function test_remove_members_with_an_empty_list_is_a_noop(): void
    {
        $this->repo->removeMembers(GroupId::from(1), []);

        self::assertSame([1, 3], self::values($this->repo->findMemberUserIds(GroupId::from(1))));
    }

    public function test_remove_all_memberships_for_users_deletes_every_membership_row_for_that_user_across_groups(): void
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

    public function test_remove_all_memberships_for_users_with_an_empty_list_is_a_noop(): void
    {
        $this->repo->removeAllMembershipsForUsers([]);

        self::assertSame([1, 3], self::values($this->repo->findMemberUserIds(GroupId::from(1))));
    }

    public function test_find_members_by_group_ids_returns_the_raw_user_id_group_id_pairs_for_the_given_groups(): void
    {
        $pairs = $this->repo->findMembersByGroupIds([1]);

        $userIds = array_map(static fn (array $row): int => is_numeric($row['user_id']) ? (int) $row['user_id'] : 0, $pairs);
        sort($userIds);
        self::assertSame([1, 3], $userIds);
    }

    public function test_find_members_by_group_ids_returns_empty_for_an_empty_input(): void
    {
        self::assertSame([], $this->repo->findMembersByGroupIds([]));
    }

    public function test_find_memberships_for_user_ids_returns_the_raw_user_id_group_id_pairs_for_the_given_users(): void
    {
        $pairs = $this->repo->findMembershipsForUserIds([1]);

        $groupIds = array_map(static fn (array $row): int => is_numeric($row['group_id']) ? (int) $row['group_id'] : 0, $pairs);
        self::assertContains(1, $groupIds);
    }

    public function test_find_memberships_for_user_ids_returns_empty_for_an_empty_input(): void
    {
        self::assertSame([], $this->repo->findMembershipsForUserIds([]));
    }

    public function test_find_names_by_ids_returns_names_keyed_by_id(): void
    {
        $name = 'p18-test-' . bin2hex(random_bytes(4));
        $groupId = $this->repo->insert($name, false);

        $names = $this->repo->findNamesByIds([1, $groupId->value]);

        self::assertSame('Editors', $names[1] ?? null);
        self::assertSame($name, $names[$groupId->value] ?? null);

        $this->repo->delete([$groupId]);
    }

    public function test_find_names_by_ids_returns_empty_for_an_empty_input(): void
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

    public function test_delete_removes_groups_and_their_access_and_membership_rows(): void
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

    public function test_delete_returns_empty_array_when_none_of_the_ids_exist(): void
    {
        self::assertSame([], $this->repo->delete([GroupId::from(999999)]));
    }

    public function test_delete_with_an_empty_list_is_a_noop(): void
    {
        self::assertSame([], $this->repo->delete([]));
    }

    public function test_get_authorized_category_ids_returns_fixture_access(): void
    {
        $ids = $this->repo->getAuthorizedCategoryIds(GroupId::from(1));

        self::assertSame([1, 2], self::values($ids));
    }

    public function test_add_access_then_remove_access_round_trips(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);

        $this->repo->addAccess($groupId, [CategoryId::from(1), CategoryId::from(2)]);
        self::assertSame([1, 2], self::values($this->repo->getAuthorizedCategoryIds($groupId)));

        $this->repo->removeAccess($groupId, [CategoryId::from(1)]);
        self::assertSame([2], self::values($this->repo->getAuthorizedCategoryIds($groupId)));

        $this->repo->delete([$groupId]);
    }

    public function test_remove_access_with_an_empty_list_is_a_noop(): void
    {
        $this->repo->removeAccess(GroupId::from(1), []);

        self::assertSame([1, 2], self::values($this->repo->getAuthorizedCategoryIds(GroupId::from(1))));
    }

    public function test_get_accessible_category_ids_for_user_follows_group_membership(): void
    {
        // Fixture: user 1 is in group 1, group 1 has access to cats 1 and 2.
        $ids = $this->repo->getAccessibleCategoryIdsForUser(UserId::from(1));

        self::assertSame([1, 2], self::values($ids));
    }

    public function test_get_accessible_category_ids_for_user_is_empty_for_a_groupless_user(): void
    {
        self::assertSame([], $this->repo->getAccessibleCategoryIdsForUser(UserId::from(2)));
    }

    public function test_find_with_member_counts_returns_fixture_groups_with_counts(): void
    {
        $rows = $this->repo->findWithMemberCounts([], null, 'name ASC', 10, 0);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row['nb_users'];
        }

        self::assertSame(2, $byName['Editors']);
        self::assertSame(1, $byName['Guests']);
        self::assertSame(1, $byName['Reviewers']);
    }

    public function test_find_with_member_counts_filters_by_group_ids(): void
    {
        $rows = $this->repo->findWithMemberCounts([GroupId::from(1)], null, 'name ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertSame('Editors', $rows[0]['name']);
    }

    public function test_find_with_member_counts_filters_by_name_like(): void
    {
        $rows = $this->repo->findWithMemberCounts([], 'editors', 'name ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertSame('Editors', $rows[0]['name']);
    }

    public function test_find_with_member_counts_respects_per_page_and_page(): void
    {
        $rows = $this->repo->findWithMemberCounts([], null, 'name ASC', 1, 1);

        self::assertCount(1, $rows);
        self::assertSame('Guests', $rows[0]['name']);
    }

    public function test_find_ids_by_name_like_matches_a_wildcard_pattern(): void
    {
        // Item 14 DQL audit regression test: g.id is the custom `group_id`
        // Doctrine Type, but getSingleColumnResult() uses HYDRATE_SCALAR_COLUMN
        // (a raw fetchFirstColumn(), no per-field Type conversion at all) --
        // an earlier version of this method wrongly assumed VO hydration
        // here (an instanceof GroupId check that never matched a raw int),
        // silently always returning [] regardless of $namePattern.
        self::assertSame([1], $this->repo->findIdsByNameLike('%dit%'));
    }

    public function test_find_ids_by_name_like_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findIdsByNameLike('%no-such-group%'));
    }
}
