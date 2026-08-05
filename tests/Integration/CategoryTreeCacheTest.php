<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Users\CurrentUser;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Lang\Translator;
use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
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
                Lang::current(),
                new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
                new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUser::current(), $filterState),
                CurrentConfig::current(),
                new EventDispatcher(),
                Translator::get()
            ),
            new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig),
            $this->pool
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public'");
        parent::tearDown();
    }

    /**
     * @return array{id: int, level: int, forbidden_categories: string}
     */
    private static function userdata(string $forbiddenCategories = ''): array
    {
        return ['id' => 1, 'level' => 0, 'forbidden_categories' => $forbiddenCategories];
    }

    public function test_get_for_user_merges_rollup_with_name_and_permalink(): void
    {
        $cats = $this->cache->getForUser(self::userdata());

        self::assertSame('Sample Album', $cats[1]['name']);
        self::assertArrayHasKey('permalink', $cats[1]);
        self::assertSame(5, $cats[1]['count_images']);
        self::assertSame(2, $cats[2]['count_images']);
        self::assertSame('Nested Sub Album', $cats[2]['name']);
    }

    public function test_get_for_user_excludes_a_forbidden_category(): void
    {
        $cats = $this->cache->getForUser(self::userdata('2'));

        self::assertArrayHasKey(1, $cats);
        self::assertArrayNotHasKey(2, $cats, 'a forbidden category must never appear in the merged output, including its name/permalink');
    }

    public function test_get_for_user_does_not_mutate_the_caller_supplied_userdata(): void
    {
        $userdata = self::userdata();
        self::assertArrayNotHasKey('last_photo_date', $userdata);

        $this->cache->getForUser($userdata);

        // getComputedCategories() no longer mutates its $userdata argument
        // at all (Legacy Coupling Retirement Phase 8, 8i -- it returns the
        // computed 'last_photo_date' alongside categories instead), so
        // this assertion is now trivially true rather than guarding a real
        // copy-before-call -- kept as a regression guard against that
        // mutation ever coming back.
        self::assertArrayNotHasKey('last_photo_date', $userdata);
    }

    public function test_get_for_user_serves_a_cache_hit_without_reflecting_a_db_change(): void
    {
        $first = $this->cache->getForUser(self::userdata());
        self::assertSame('Sample Album', $first[1]['name']);

        $this->conn->executeStatement(
            'UPDATE ' . Tables::categories() . " SET name = 'Renamed' WHERE id = 1"
        );

        $second = $this->cache->getForUser(self::userdata());
        self::assertSame('Sample Album', $second[1]['name'], 'a cache hit must not re-query the DB');

        $this->conn->executeStatement(
            'UPDATE ' . Tables::categories() . " SET name = 'Sample Album' WHERE id = 1"
        );
    }

    public function test_get_for_user_uses_a_separate_cache_entry_per_user(): void
    {
        $this->cache->getForUser(self::userdata());
        $forbiddenForOtherUser = $this->cache->getForUser(['id' => 2, 'level' => 0, 'forbidden_categories' => '2']);

        self::assertArrayNotHasKey(2, $forbiddenForOtherUser);
    }
}
