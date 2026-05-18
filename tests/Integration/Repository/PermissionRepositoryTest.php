<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see PermissionRepository}. Permission
 * tables (`user_access`, `group_access`, `user_group`) are not seeded in
 * the fixture; each test sets up its own rows.
 */
final class PermissionRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private PermissionRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new PermissionRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insertUserAccessIgnoreDuplicates_round_trips_to_findCatIdsByUserAccess(): void
    {
        $this->repo->insertUserAccessIgnoreDuplicates([
            ['user_id' => 3, 'cat_id' => 1],
            ['user_id' => 3, 'cat_id' => 2],
        ]);

        $ids = $this->repo->findCatIdsByUserAccess(3);
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function test_insertUserAccessIgnoreDuplicates_skips_duplicates(): void
    {
        $this->repo->insertUserAccessIgnoreDuplicates([['user_id' => 3, 'cat_id' => 1]]);
        $this->repo->insertUserAccessIgnoreDuplicates([['user_id' => 3, 'cat_id' => 1]]);

        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_user_access WHERE user_id = 3 AND cat_id = 1'
        )->fetchOne();
        self::assertSame(1, $count, 'INSERT IGNORE must collapse PK duplicates');
    }

    public function test_findCatIdsByUserAccess_returns_empty_for_user_without_grants(): void
    {
        self::assertSame([], $this->repo->findCatIdsByUserAccess(4));
    }

    /**
     * fk_user_access_user_id ON DELETE CASCADE: deleting the user must
     * remove all their user_access rows.
     */
    public function test_user_delete_cascades_to_user_access(): void
    {
        $this->repo->insertUserAccessIgnoreDuplicates([
            ['user_id' => 3, 'cat_id' => 1],
            ['user_id' => 3, 'cat_id' => 2],
        ]);
        self::assertCount(2, $this->repo->findCatIdsByUserAccess(3), 'precondition');

        $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = 3');

        self::assertSame([], $this->repo->findCatIdsByUserAccess(3));
    }

    /**
     * fk_user_access_cat_id ON DELETE CASCADE: deleting the category must
     * remove all user_access rows pointing at it.
     */
    public function test_category_delete_cascades_to_user_access(): void
    {
        $this->repo->insertUserAccessIgnoreDuplicates([['user_id' => 3, 'cat_id' => 2]]);
        self::assertSame([2], $this->repo->findCatIdsByUserAccess(3));

        $this->conn->executeStatement('DELETE FROM piwigo_categories WHERE id = 2');

        self::assertSame([], $this->repo->findCatIdsByUserAccess(3));
    }
}
