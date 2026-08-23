<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Permission\EffectiveForbiddenCategoriesCache;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Users\UserRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Same fixture shape as ForbiddenCategoriesCacheTest's own coverage: 2
 * categories (1 "Sample Album", 2 "Nested Sub Album" under it), 5 images
 * (3 in cat 1, 2 in cat 2), all at level 0 and all dated '2026-08-01
 * 00:00:00' -- so `imageAccessType`/`imageAccessList` are always the '0'
 * fallback ("nothing above this user's level 0") for every scenario below;
 * `nbTotalImages` counts all 5 unless a category is structurally forbidden;
 * `lastPhotoDate` is that one shared date unless every image is excluded.
 */
/**
 * compute()'s own `$structuralForbidden === '' ? implode(...) : ...`
 * ternary TRUE branch (the bare-implode() form, used when there is no
 * existing structural-forbidden prefix to append onto) is NOT exercised
 * anywhere here -- confirmed unreachable: $structuralForbidden's only
 * producer is PermissionService::getForbiddenCategories(), which itself
 * guarantees at least the '0' sentinel whenever its own internal
 * $forbiddenIds list would otherwise be empty (see that method's own
 * `if ($forbiddenIds === []) { $forbiddenIds[] = 0; }` guard) -- it can
 * never actually return a bare ''. PermissionService is `final`, so no
 * test double can be substituted in its place to force the ''  case
 * either. test_get_for_user_widens_forbidden_categories_for_a_non_admin_with_an_empty_album
 * below already covers this ternary's other (non-'') branch. Same
 * defensive-dead-code shape as Lang\Translator's own
 * `! ($entry instanceof Translation)` branches.
 */
final class EffectiveForbiddenCategoriesCacheTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private EffectiveForbiddenCategoriesCache $cache;

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

        $permissionService = new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($this->conn)),
            EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class),
            new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()),
            CurrentUserTestFactory::get(),
            $filterState,
            new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
        );
        $this->cache = new EffectiveForbiddenCategoriesCache(
            new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
            $permissionService,
            new CategoryService(LangTestFactory::get(), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), $permissionService, CurrentConfigTestFactory::get(), new EventDispatcher(), TranslatorTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get())),
            new PermissionRepository(EntityManagerFactory::build($this->conn)),
            $this->pool,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $visibleLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement("UPDATE categories SET status = 'public', visible = {$visibleLiteral}");
        parent::tearDown();
    }

    public function testGetForUserComputesImageAccessAndTotalImagesForThePlainFixture(): void
    {
        $result = $this->cache->getForUser(2, 'normal', '0');

        self::assertSame('0', $result->forbiddenCategories);
        self::assertSame('NOT IN', $result->imageAccessType);
        self::assertSame('0', $result->imageAccessList);
        self::assertSame('5', $result->nbTotalImages);
        self::assertSame('2026-08-01 00:00:00', $result->lastPhotoDate);
    }

    public function testGetForUserReflectsAStructurallyForbiddenCategory(): void
    {
        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        $result = $this->cache->getForUser(2, 'normal', '0');

        self::assertSame('1', $result->forbiddenCategories);
        // category 1's own 3 images are excluded from the total, leaving
        // only category 2's 2.
        self::assertSame('2', $result->nbTotalImages);
    }

    /**
     * Proves the forbidden-category exclusion genuinely reaches
     * lastPhotoDate too, not just nbTotalImages -- every fixture image
     * shares one date, so this backdates category 1's own images first to
     * make an exclusion actually observable.
     */
    public function testGetForUserLastPhotoDateReflectsOnlyVisibleCategories(): void
    {
        $this->conn->executeStatement(
            "UPDATE images SET date_available = '2020-01-01 00:00:00' WHERE id IN (1, 2, 3)"
        );

        try {
            $before = $this->cache->getForUser(2, 'normal', '0');
            self::assertSame('2026-08-01 00:00:00', $before->lastPhotoDate, 'category 2 (unmutated) should still be the most recent');

            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 2");

            // A fresh pool (not $this->cache's), same user -- the point
            // here is a genuinely fresh computation reflecting the new
            // forbidden category, not a per-user cache-entry distinction.
            $afterCacheFilterState = Kernel::container()->get(FilterState::class);
            if (! $afterCacheFilterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }
            $afterCache = new EffectiveForbiddenCategoriesCache(
                new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
                new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $afterCacheFilterState, new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get())),
                new CategoryService(LangTestFactory::get(), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $afterCacheFilterState, new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get())), CurrentConfigTestFactory::get(), new EventDispatcher(), TranslatorTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get())),
                new PermissionRepository(EntityManagerFactory::build($this->conn)),
                new ArrayAdapter(),
            );
            $after = $afterCache->getForUser(2, 'normal', '0');
            self::assertSame('2020-01-01 00:00:00', $after->lastPhotoDate, "once category 2 is forbidden, only category 1's backdated images remain visible");
        } finally {
            $this->conn->executeStatement(
                "UPDATE images SET date_available = '2026-08-01 00:00:00' WHERE id IN (1, 2, 3)"
            );
        }
    }

    /**
     * Critical finding this class exists to preserve, verified end to end:
     * `PermissionService::getForbiddenCategories()`'s own structural value
     * does NOT include an empty-of-images album, but the *effective* value
     * this class computes does -- for non-admins only (feature 1053).
     */
    public function testGetForUserWidensForbiddenCategoriesForANonAdminWithAnEmptyAlbum(): void
    {
        $categoryRepo = new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get());
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
            self::assertSame('0,' . $emptyId, $result->forbiddenCategories);

            // Admins never get the feature-1053 widening -- a fresh pool
            // entry (different user id) proves this is the status gate,
            // not a stale cache hit from the assertion above.
            // getComputedCategories() -- and therefore lastPhotoDate --
            // is called unconditionally, matching the legacy code's own
            // real behavior, so admins still get a real value even
            // though the widening loop itself never runs for them.
            $adminResult = $this->cache->getForUser(999, 'admin', '0');
            self::assertSame('0', $adminResult->forbiddenCategories);
            self::assertSame('2026-08-01 00:00:00', $adminResult->lastPhotoDate);
        } finally {
            $this->conn->executeStatement('DELETE FROM categories WHERE id = ' . $emptyId);
        }
    }

    public function testGetForUserServesACacheHitWithoutReflectingADbChange(): void
    {
        $first = $this->cache->getForUser(2, 'normal', '0');
        self::assertSame('0', $first->forbiddenCategories);

        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        $second = $this->cache->getForUser(2, 'normal', '0');
        self::assertEquals($first, $second, 'a cache hit must not re-query the DB');
    }

    public function testGetForUserUsesASeparateCacheEntryPerUser(): void
    {
        $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

        $forUser2 = $this->cache->getForUser(2, 'normal', '0');
        self::assertSame('1', $forUser2->forbiddenCategories);

        // Fixture: user 1 is a member of group 1 ("Editors"), which has
        // group_access to cat 1 -- not forbidden for this user, so a
        // shared cache entry would incorrectly return '1' here too.
        $forUser1 = $this->cache->getForUser(1, 'normal', '0');
        self::assertSame('0', $forUser1->forbiddenCategories);
    }
}
