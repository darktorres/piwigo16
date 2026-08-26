<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Same fixture shape as CategoryServiceTest/CategoryRepositoryTest:
 * category 1 "Sample Album" (root, 3 direct images), category 2 "Nested Sub
 * Album" (child of 1, 2 direct images).
 *
 * getForUser()'s `continue` branch (skipping a rollup row whose category
 * was deleted between the rollup query and findNamesByIds()'s own lookup a
 * moment later) is left uncovered -- confirmed untestable without a
 * production refactor: both CategoryService and CategoryRepository are
 * `final` with no interface seam, constructed directly by this class's own
 * constructor (not via DI), so there's no fake-able collaborator to make
 * one query see a category the other query's already-run result doesn't.
 * The real race window only exists between two sequential,
 * non-transactional queries inside one synchronous PHP call -- not
 * reproducible from a single-threaded test.
 */
final class CategoryTreeCacheTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryTreeCache $cache;

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
        $this->cache = new CategoryTreeCache(
            new CategoryService(
                LangTestFactory::get(),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), GroupRepository::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUserTestFactory::get(), $filterState, new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)),
                CurrentConfigTestFactory::get(),
                new EventDispatcher(),
                TranslatorTestFactory::get(),
                new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)
            ),
            new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
            $this->pool
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("UPDATE categories SET status = 'public'");
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testGetForUserMergesRollupWithNameAndPermalink(): void
    {
        $cats = $this->cache->getForUser(1, 0, '');

        self::assertSame('Sample Album', $cats[1]->name);
        self::assertSame(5, $cats[1]->countImages);
        self::assertSame(2, $cats[2]->countImages);
        self::assertSame('Nested Sub Album', $cats[2]->name);
    }

    public function testGetForUserExcludesAForbiddenCategory(): void
    {
        $cats = $this->cache->getForUser(1, 0, '2');

        self::assertArrayHasKey(1, $cats);
        self::assertArrayNotHasKey(2, $cats, 'a forbidden category must never appear in the merged output, including its name/permalink');
    }

    public function testGetForUserServesACacheHitWithoutReflectingADbChange(): void
    {
        $first = $this->cache->getForUser(1, 0, '');
        self::assertSame('Sample Album', $first[1]->name);

        $this->conn->executeStatement(
            "UPDATE categories SET name = 'Renamed' WHERE id = 1"
        );

        $second = $this->cache->getForUser(1, 0, '');
        self::assertSame('Sample Album', $second[1]->name, 'a cache hit must not re-query the DB');

        $this->conn->executeStatement(
            "UPDATE categories SET name = 'Sample Album' WHERE id = 1"
        );
    }

    public function testGetForUserUsesASeparateCacheEntryPerUser(): void
    {
        $this->cache->getForUser(1, 0, '');
        $forbiddenForOtherUser = $this->cache->getForUser(2, 0, '2');

        self::assertArrayNotHasKey(2, $forbiddenForOtherUser);
    }
}
