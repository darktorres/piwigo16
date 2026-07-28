<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionRepository;

/**
 * findGrantedGroupIdsByCategory()/findGrantedUserIdsByCategory()'s own
 * `! is_numeric(...)` `continue` branches (guarding against a non-numeric
 * cat_id/group_id/user_id in a fetched row) are NOT exercised by any test
 * here -- confirmed unreachable: `group_access.group_id`/`.cat_id` and
 * `user_access.user_id`/`.cat_id` are all `NOT NULL` unsigned int columns
 * and each pair is the table's own PRIMARY KEY (see
 * tests/Fixtures/piwigo-17.0.sql's own CREATE TABLE statements), so no
 * real query against either table can ever produce a row with a
 * non-numeric value in one of those columns. Same defensive-dead-code
 * shape as Lang\Translator's own `! ($entry instanceof Translation)`
 * branches.
 */
final class PermissionRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PermissionRepository $repo;

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
        $this->repo = new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn));
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Both fixture categories default to status='public', visible=1
        // -- restore that baseline regardless of which mutation test ran.
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = 1");
        $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess());
        $this->conn->executeStatement('DELETE FROM ' . Tables::groupAccess());
        parent::tearDown();
    }

    public function test_find_private_category_ids_is_empty_against_the_unmodified_fixture(): void
    {
        self::assertSame([], $this->repo->findPrivateCategoryIds());
    }

    public function test_find_private_category_ids_reflects_a_private_category(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        self::assertSame([1], $this->repo->findPrivateCategoryIds());
    }

    public function test_find_locked_category_ids_is_empty_against_the_unmodified_fixture(): void
    {
        self::assertSame([], $this->repo->findLockedCategoryIds());
    }

    public function test_find_locked_category_ids_reflects_an_invisible_category(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET visible = 0 WHERE id = 2');

        self::assertSame([2], $this->repo->findLockedCategoryIds());
    }

    public function test_find_directly_authorized_category_ids_is_empty_for_an_unauthorized_user(): void
    {
        self::assertSame([], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function test_find_directly_authorized_category_ids_reflects_a_user_access_row(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1)'
        );

        self::assertSame([1], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function test_delete_user_access_removes_only_the_given_categories(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1), (2, 2)'
        );

        $this->repo->deleteUserAccess(2, [1]);

        self::assertSame([2], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function test_delete_user_access_with_an_empty_id_list_does_nothing(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1)'
        );

        $this->repo->deleteUserAccess(2, []);

        self::assertSame([1], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function test_find_granted_group_ids_by_category_groups_rows_by_cat_id(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::groupAccess() . ' (group_id, cat_id) VALUES (1, 1), (2, 1), (3, 2)'
        );

        self::assertSame(
            [1 => [1, 2], 2 => [3]],
            $this->repo->findGrantedGroupIdsByCategory([1, 2])
        );
    }

    public function test_find_granted_group_ids_by_category_with_an_empty_id_list_returns_empty(): void
    {
        self::assertSame([], $this->repo->findGrantedGroupIdsByCategory([]));
    }

    public function test_find_granted_user_ids_by_category_groups_rows_by_cat_id(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (2, 1), (3, 1), (2, 2)'
        );

        self::assertSame(
            [1 => [2, 3], 2 => [2]],
            $this->repo->findGrantedUserIdsByCategory([1, 2])
        );
    }

    public function test_find_granted_user_ids_by_category_with_an_empty_id_list_returns_empty(): void
    {
        self::assertSame([], $this->repo->findGrantedUserIdsByCategory([]));
    }

    public function test_find_private_category_ids_among_with_an_empty_id_list_returns_empty(): void
    {
        self::assertSame([], $this->repo->findPrivateCategoryIdsAmong([]));
    }

    public function test_mass_insert_user_access_with_no_inserts_does_nothing(): void
    {
        $this->repo->massInsertUserAccess([]);

        self::assertSame([], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }
}
