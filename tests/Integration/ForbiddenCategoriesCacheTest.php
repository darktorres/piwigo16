<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Core\FilterState;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Users\CurrentUser;
use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
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

        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }

        $this->conn = DbConnection::build();
        $this->pool = new ArrayAdapter();
        $this->cache = new ForbiddenCategoriesCache(
            new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($this->conn)),
                EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                CurrentUser::current(),
                $filterState,
                new AccessLevelChecker(CurrentUser::current(), $currentConfig),
            ),
            $this->pool,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $visibleLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = {$visibleLiteral}");
        parent::tearDown();
    }

    public function test_get_for_user_returns_the_underlying_forbidden_categories(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        self::assertSame('1', $this->cache->getForUser(2, 'normal'));
    }

    public function test_get_for_user_serves_a_cache_hit_without_reflecting_a_db_change(): void
    {
        self::assertSame('0', $this->cache->getForUser(2, 'normal'));

        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        self::assertSame('0', $this->cache->getForUser(2, 'normal'), 'a cache hit must not re-query the DB');
    }

    public function test_get_for_user_uses_a_separate_cache_entry_per_user(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        self::assertSame('1', $this->cache->getForUser(2, 'normal'));
        // Fixture: user 1 is a member of group 1 ("Editors"), which has
        // group_access to cat 1 -- not forbidden for this user, so a
        // shared cache entry would incorrectly return '1' here too.
        self::assertSame('0', $this->cache->getForUser(1, 'normal'));
    }
}
