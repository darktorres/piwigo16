<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Same fixture shape as PermissionServiceTest's own
 * getForbiddenCategories() coverage: user 2 (fixture guest) has no
 * user_access row and isn't in any group with group_access to category 1.
 */
final class ForbiddenCategoriesCacheTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ForbiddenCategoriesCache $cache;

    private ArrayAdapter $pool;

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

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }

        $this->conn = DbConnection::build();
        $this->pool = new ArrayAdapter();
        $this->cache = new ForbiddenCategoriesCache(
            new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($this->conn)),
                TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), GroupRepository::class),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                CurrentUserTestFactory::get(),
                $filterState,
                new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig),
            ),
            $this->pool,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $visibleLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement("UPDATE categories SET status = 'public', visible = {$visibleLiteral}");
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testGetForUserReturnsTheUnderlyingForbiddenCategories(): void
    {
        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        self::assertSame('1', $this->cache->getForUser(2, 'normal'));
    }

    public function testGetForUserServesACacheHitWithoutReflectingADbChange(): void
    {
        self::assertSame('0', $this->cache->getForUser(2, 'normal'));

        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        self::assertSame('0', $this->cache->getForUser(2, 'normal'), 'a cache hit must not re-query the DB');
    }

    public function testGetForUserUsesASeparateCacheEntryPerUser(): void
    {
        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        self::assertSame('1', $this->cache->getForUser(2, 'normal'));
        // Fixture: user 1 is a member of group 1 ("Editors"), which has
        // group_access to cat 1 -- not forbidden for this user, so a
        // shared cache entry would incorrectly return '1' here too.
        self::assertSame('0', $this->cache->getForUser(1, 'normal'));
    }
}
