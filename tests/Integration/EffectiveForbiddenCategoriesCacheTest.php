<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\EffectiveForbiddenCategoriesCache;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Same fixture shape as ForbiddenCategoriesCacheTest's own coverage: 2
 * categories (1 "Sample Album", 2 "Nested Sub Album" under it), 5 images
 * (3 in cat 1, 2 in cat 2), all at level 0 -- so `imageAccessType`/
 * `imageAccessList` are always the '0' fallback ("nothing above this
 * user's level 0") for every scenario below; `nbTotalImages` counts all 5
 * unless a category is structurally forbidden.
 */
final class EffectiveForbiddenCategoriesCacheTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private EffectiveForbiddenCategoriesCache $cache;

    private ArrayAdapter $pool;

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
        $this->pool = new ArrayAdapter();

        $permissionService = new PermissionService(
            new PermissionRepository($this->conn),
            new GroupRepository($this->conn),
            new CategoryRepository($this->conn),
        );
        $this->cache = new EffectiveForbiddenCategoriesCache(
            $permissionService,
            new CategoryService(new CategoryRepository($this->conn), $permissionService),
            $this->conn,
            $this->pool,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public', visible = 1");
        parent::tearDown();
    }

    public function test_get_for_user_computes_image_access_and_total_images_for_the_plain_fixture(): void
    {
        $result = $this->cache->getForUser(2, 'normal', '0');

        self::assertSame('0', $result['forbiddenCategories']);
        self::assertSame('NOT IN', $result['imageAccessType']);
        self::assertSame('0', $result['imageAccessList']);
        self::assertSame('5', $result['nbTotalImages']);
    }

    public function test_get_for_user_reflects_a_structurally_forbidden_category(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        $result = $this->cache->getForUser(2, 'normal', '0');

        self::assertSame('1', $result['forbiddenCategories']);
        // category 1's own 3 images are excluded from the total, leaving
        // only category 2's 2.
        self::assertSame('2', $result['nbTotalImages']);
    }

    /**
     * Critical finding this class exists to preserve, verified end to end:
     * `PermissionService::getForbiddenCategories()`'s own structural value
     * does NOT include an empty-of-images album, but the *effective* value
     * this class computes does -- for non-admins only (feature 1053).
     */
    public function test_get_for_user_widens_forbidden_categories_for_a_non_admin_with_an_empty_album(): void
    {
        $categoryRepo = new CategoryRepository($this->conn);
        // 'uppercats' is deliberately omitted here, not passed as '' --
        // BatchWriter::singleInsert() maps an empty-string value to a bare
        // SQL NULL, which the NOT NULL column rejects; the schema's own
        // default ('') applies when the key is absent, same as
        // CategoryService::createVirtualCategory()'s real insert.
        $emptyId = $categoryRepo->insertCategory([
            'name' => 'Empty Test Album',
        ]);
        $categoryRepo->updateCategoryAfterInsert($emptyId, [
            'uppercats' => (string) $emptyId,
        ]);

        try {
            $result = $this->cache->getForUser(2, 'normal', '0');
            // PermissionService::getForbiddenCategories() never returns a
            // bare '' -- '0' is its own baked-in sentinel for "nothing
            // forbidden" (see that method's own comment), so the widened
            // value appends onto '0', not onto an empty string.
            self::assertSame('0,' . $emptyId, $result['forbiddenCategories']);

            // Admins never get the feature-1053 widening -- a fresh pool
            // entry (different user id) proves this is the status gate,
            // not a stale cache hit from the assertion above.
            $adminResult = $this->cache->getForUser(999, 'admin', '0');
            self::assertSame('0', $adminResult['forbiddenCategories']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $emptyId);
        }
    }

    public function test_get_for_user_serves_a_cache_hit_without_reflecting_a_db_change(): void
    {
        $first = $this->cache->getForUser(2, 'normal', '0');
        self::assertSame('0', $first['forbiddenCategories']);

        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        $second = $this->cache->getForUser(2, 'normal', '0');
        self::assertSame($first, $second, 'a cache hit must not re-query the DB');
    }

    public function test_get_for_user_uses_a_separate_cache_entry_per_user(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

        $forUser2 = $this->cache->getForUser(2, 'normal', '0');
        self::assertSame('1', $forUser2['forbiddenCategories']);

        // Fixture: user 1 is a member of group 1 ("Editors"), which has
        // group_access to cat 1 -- not forbidden for this user, so a
        // shared cache entry would incorrectly return '1' here too.
        $forUser1 = $this->cache->getForUser(1, 'normal', '0');
        self::assertSame('0', $forUser1['forbiddenCategories']);
    }
}
