<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping investigation): begin before any
        // container resolution below, so the container's own first-
        // resolution-per-test-lifetime caching of Connection::class/
        // EntityManagerInterface::class (config/container.php's own
        // documented behavior) picks up this wrapped connection, not a
        // pre-override one.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new PermissionRepository(EntityManagerFactory::build($this->conn));
        // PILOT fix: this class's own tests (e.g.
        // testFindGrantedGroupIdsByCategoryGroupsRowsByCatId, inserting
        // group_id=1/cat_id=1) previously relied on tearDown()'s DELETEs
        // below having *really* run for a prior test in this class,
        // leaving user_access/group_access genuinely empty rather than
        // matching the fixture's own 4 pre-seeded group_access rows --
        // a real pre-existing fragility (only worked because of
        // whichever test happened to run first), made structurally
        // impossible to rely on now that nothing persists across tests.
        // Clearing here too makes every test self-contained regardless
        // of run order, matching AuditRepositoryTest's own established
        // setUp()-clears-too convention.
        $this->conn->executeStatement('DELETE FROM user_access');
        $this->conn->executeStatement('DELETE FROM group_access');
    }

    #[Override]
    protected function tearDown(): void
    {
        // Both fixture categories default to status='public', visible=1
        // -- restore that baseline regardless of which mutation test ran.
        // visible is a genuine boolean column -- a bare `1` literal in the
        // SQL text (unlike a bound parameter, which the driver coerces
        // implicitly) is rejected outright by Postgres.
        $visibleLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement("UPDATE categories SET status = 'public', visible = {$visibleLiteral}");
        $this->conn->executeStatement('DELETE FROM user_access');
        $this->conn->executeStatement('DELETE FROM group_access');
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFindPrivateCategoryIdsIsEmptyAgainstTheUnmodifiedFixture(): void
    {
        self::assertSame([], $this->repo->findPrivateCategoryIds());
    }

    public function testFindPrivateCategoryIdsReflectsAPrivateCategory(): void
    {
        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        self::assertSame([1], $this->repo->findPrivateCategoryIds());
    }

    public function testFindLockedCategoryIdsIsEmptyAgainstTheUnmodifiedFixture(): void
    {
        self::assertSame([], $this->repo->findLockedCategoryIds());
    }

    public function testFindLockedCategoryIdsReflectsAnInvisibleCategory(): void
    {
        $visibleLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
        $this->conn->executeStatement("UPDATE categories SET visible = {$visibleLiteral} WHERE id = 2");

        self::assertSame([2], $this->repo->findLockedCategoryIds());
    }

    public function testFindDirectlyAuthorizedCategoryIdsIsEmptyForAnUnauthorizedUser(): void
    {
        self::assertSame([], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function testFindDirectlyAuthorizedCategoryIdsReflectsAUserAccessRow(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO user_access (user_id, cat_id) VALUES (2, 1)'
        );

        self::assertSame([1], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function testDeleteUserAccessRemovesOnlyTheGivenCategories(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO user_access (user_id, cat_id) VALUES (2, 1), (2, 2)'
        );

        $this->repo->deleteUserAccess(2, [1]);

        self::assertSame([2], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function testDeleteUserAccessWithAnEmptyIdListDoesNothing(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO user_access (user_id, cat_id) VALUES (2, 1)'
        );

        $this->repo->deleteUserAccess(2, []);

        self::assertSame([1], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }

    public function testFindGrantedGroupIdsByCategoryGroupsRowsByCatId(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO group_access (group_id, cat_id) VALUES (1, 1), (2, 1), (3, 2)'
        );

        self::assertSame(
            [
                1 => [1, 2],
                2 => [3],
            ],
            $this->repo->findGrantedGroupIdsByCategory([1, 2])
        );
    }

    public function testFindGrantedGroupIdsByCategoryWithAnEmptyIdListReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->findGrantedGroupIdsByCategory([]));
    }

    public function testFindGrantedUserIdsByCategoryGroupsRowsByCatId(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO user_access (user_id, cat_id) VALUES (2, 1), (3, 1), (2, 2)'
        );

        self::assertSame(
            [
                1 => [2, 3],
                2 => [2],
            ],
            $this->repo->findGrantedUserIdsByCategory([1, 2])
        );
    }

    public function testFindGrantedUserIdsByCategoryWithAnEmptyIdListReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->findGrantedUserIdsByCategory([]));
    }

    public function testFindPrivateCategoryIdsAmongWithAnEmptyIdListReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->findPrivateCategoryIdsAmong([]));
    }

    public function testMassInsertUserAccessWithNoInsertsDoesNothing(): void
    {
        $this->repo->massInsertUserAccess([]);

        self::assertSame([], $this->repo->findDirectlyAuthorizedCategoryIds(2));
    }
}
