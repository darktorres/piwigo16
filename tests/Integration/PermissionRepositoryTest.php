<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionRepository;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new PermissionRepository($this->conn);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Both fixture categories default to status='public', visible='true'
        // -- restore that baseline regardless of which mutation test ran.
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = 'true'");
        $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess());
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
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET visible = 'false' WHERE id = 2");

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
}
