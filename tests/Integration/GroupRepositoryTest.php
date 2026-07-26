<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class);
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
        self::assertTrue($this->repo->exists(1));
        self::assertFalse($this->repo->exists(999999));
    }

    public function test_name_exists_matches_a_fixture_name(): void
    {
        self::assertTrue($this->repo->nameExists('Editors'));
        self::assertFalse($this->repo->nameExists('Does Not Exist'));
    }

    public function test_name_exists_excludes_the_given_group_id(): void
    {
        self::assertFalse($this->repo->nameExists('Editors', 1));
        self::assertTrue($this->repo->nameExists('Editors', 2));
    }

    public function test_find_existing_ids_returns_only_the_ids_that_exist(): void
    {
        $ids = $this->repo->findExistingIds([1, 2, 999999]);

        self::assertSame([1, 2], $ids);
    }

    public function test_find_existing_ids_returns_empty_for_an_empty_input(): void
    {
        self::assertSame([], $this->repo->findExistingIds([]));
    }

    public function test_find_name_returns_a_fixture_groups_name(): void
    {
        self::assertSame('Editors', $this->repo->findName(1));
        self::assertNull($this->repo->findName(999999));
    }

    public function test_is_default_reflects_the_fixture_column(): void
    {
        self::assertFalse($this->repo->isDefault(1));
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
        $this->repo->update(1, []);

        self::assertSame('Editors', $this->repo->findName(1));
    }

    public function test_find_member_user_ids_returns_fixture_members(): void
    {
        $ids = $this->repo->findMemberUserIds(1);

        self::assertSame([1, 3], $ids);
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
        $names = $this->repo->findMemberUsernames(1, 'username', 'id');

        self::assertSame(['fixture_admin', 'regular_user'], $names);
    }

    public function test_add_members_then_remove_members_round_trips(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);

        $this->repo->addMembers($groupId, [2]);
        self::assertSame([2], $this->repo->findMemberUserIds($groupId));

        $this->repo->removeMembers($groupId, [2]);
        self::assertSame([], $this->repo->findMemberUserIds($groupId));

        $this->repo->delete([$groupId]);
    }

    public function test_add_members_silently_ignores_an_already_existing_membership(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);
        $this->repo->addMembers($groupId, [2]);

        // Re-adding the same (group_id, user_id) pair must not throw a
        // duplicate-key error -- this is the whole point of using INSERT
        // IGNORE here.
        $this->repo->addMembers($groupId, [2]);

        self::assertSame([2], $this->repo->findMemberUserIds($groupId));

        $this->repo->delete([$groupId]);
    }

    public function test_remove_members_with_an_empty_list_is_a_noop(): void
    {
        $this->repo->removeMembers(1, []);

        self::assertSame([1, 3], $this->repo->findMemberUserIds(1));
    }

    public function test_delete_removes_groups_and_their_access_and_membership_rows(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);
        $this->repo->addMembers($groupId, [2]);
        $this->repo->addAccess($groupId, [1]);

        $deleted = $this->repo->delete([$groupId]);

        self::assertArrayHasKey($groupId, $deleted);
        self::assertFalse($this->repo->exists($groupId));
        self::assertSame([], $this->repo->findMemberUserIds($groupId));
        self::assertSame([], $this->repo->getAuthorizedCategoryIds($groupId));
    }

    public function test_delete_returns_empty_array_when_none_of_the_ids_exist(): void
    {
        self::assertSame([], $this->repo->delete([999999]));
    }

    public function test_delete_with_an_empty_list_is_a_noop(): void
    {
        self::assertSame([], $this->repo->delete([]));
    }

    public function test_get_authorized_category_ids_returns_fixture_access(): void
    {
        $ids = $this->repo->getAuthorizedCategoryIds(1);

        self::assertSame([1, 2], $ids);
    }

    public function test_add_access_then_remove_access_round_trips(): void
    {
        $groupId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), false);

        $this->repo->addAccess($groupId, [1, 2]);
        self::assertSame([1, 2], $this->repo->getAuthorizedCategoryIds($groupId));

        $this->repo->removeAccess($groupId, [1]);
        self::assertSame([2], $this->repo->getAuthorizedCategoryIds($groupId));

        $this->repo->delete([$groupId]);
    }

    public function test_remove_access_with_an_empty_list_is_a_noop(): void
    {
        $this->repo->removeAccess(1, []);

        self::assertSame([1, 2], $this->repo->getAuthorizedCategoryIds(1));
    }

    public function test_get_accessible_category_ids_for_user_follows_group_membership(): void
    {
        // Fixture: user 1 is in group 1, group 1 has access to cats 1 and 2.
        $ids = $this->repo->getAccessibleCategoryIdsForUser(1);

        self::assertSame([1, 2], $ids);
    }

    public function test_get_accessible_category_ids_for_user_is_empty_for_a_groupless_user(): void
    {
        self::assertSame([], $this->repo->getAccessibleCategoryIdsForUser(2));
    }

    public function test_find_with_member_counts_returns_fixture_groups_with_counts(): void
    {
        $rows = $this->repo->findWithMemberCounts([], null, 'name ASC', 10, 0);

        $byName = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $nbUsers = $row['nb_users'];
            self::assertIsString($name);
            self::assertIsNumeric($nbUsers);
            $byName[$name] = (int) $nbUsers;
        }

        self::assertSame(2, $byName['Editors']);
        self::assertSame(1, $byName['Guests']);
        self::assertSame(1, $byName['Reviewers']);
    }

    public function test_find_with_member_counts_filters_by_group_ids(): void
    {
        $rows = $this->repo->findWithMemberCounts([1], null, 'name ASC', 10, 0);

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
}
