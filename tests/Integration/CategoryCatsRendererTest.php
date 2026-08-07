<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\FilterState;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Url\RootPathOverride;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Core\Logger;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\DateHelper;
use Error;
use Doctrine\DBAL\Connection;
use Piwigo\Cache\CachePools;
use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\CurrentLogger;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;

/**
 * Real fake for FilterUpdaterInterface -- lets a test force a specific
 * category's post-filter count_images down to 0 (simulating a real tag/date
 * filter that hides every one of its images) without needing a real
 * Piwigo\Filter\FilterService + active filter session, which CategoryCatsRenderer
 * itself can't depend on directly (L2aCoreDomain, see the interface's own
 * docblock).
 */
final class CategoryCatsRendererFakeFilterUpdater implements FilterUpdaterInterface
{
    /** @var list<int> */
    public array $forceCountImagesZeroFor = [];

    #[Override]
    public function updateCatsWithFilteredData(array &$cats): void
    {
        foreach ($cats as $idx => $cat) {
            $catId = $cat['id'] ?? null;
            if (is_int($catId) && in_array($catId, $this->forceCountImagesZeroFor, true)) {
                $cats[$idx]['count_images'] = 0;
            }
        }
    }
}

/**
 * Real no-op fake for createVirtualCategory()'s per-call
 * ActivityLoggerInterface parameter -- this suite only uses it to create a
 * disposable, zero-image category fixture, never asserts on the log itself.
 */
final class CategoryCatsRendererFakeActivityLogger implements ActivityLoggerInterface
{
    #[Override]
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void {}
}

/**
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql), same as
 * CategoryRepositoryTest/CategoryServiceTest/CategoryTreeCacheTest: category
 * 1 "Sample Album" (root, uppercats="1", representative_picture_id=1, 3
 * direct images: 1, 2, 3), category 2 "Nested Sub Album" (child of 1,
 * uppercats="1,2", representative_picture_id=4, 2 direct images: 4, 5).
 * group_access grants groups 1/2/3 -> category 1, group 1 -> category 2.
 *
 * render()'s "category was deleted between the rollup and the full-row
 * fetch a moment later" `continue` branch has no fake-able collaborator
 * seam (CategoryRepository is `final`, and render() builds its own internal
 * CategoryTreeCache from this renderer's own injected -- also `final` --
 * CategoryRepository/CategoryService) -- but IS reachable another way: the
 * rollup side ($tree) comes from CategoryTreeCache::getForUser(), backed by
 * the same real, persistent CachePools::categoryTree() this file already
 * manipulates directly for its own repr_* assertions above. Priming that
 * cache with a real render() call, then deleting the category from the live
 * DB *without* invalidating the cache, reproduces the exact race
 * deterministically: the next render() call's $tree (a cache hit) still
 * lists the now-gone category, but findFullCategoriesByIds() (always a live
 * read) no longer finds it. See
 * test_render_skips_a_category_present_in_a_stale_cached_tree_but_deleted_from_the_db()
 * below.
 */
final class CategoryCatsRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryCatsRenderer $renderer;

    private CategoryCatsRendererFakeFilterUpdater $filterUpdater;

    private CategoryService $categoryService;

    private Template $template;

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
        // See CategoryDefaultRendererTest's identical comment: skips
        // Template's own data_dir_checked write, which would otherwise
        // reach for a full RequestBootstrap dependency this test never
        // boots.
        $currentConfig->setDataDirChecked('1');

        $this->conn = DbConnection::build();

        $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
        $configService->loadConfFromDb();
        ImageStdParamsTestFactory::get()->load_from_db();

        // render() builds its own internal CategoryTreeCache from
        // CachePools::categoryTree() (not injectable) -- a stale repr_*/
        // tree_* entry left over from an earlier test run (this is a real
        // persistent filesystem-backed pool, not an in-memory fake) would
        // silently make an assertion here pass or fail for the wrong
        // reason.
        CachePools::categoryTree()->clear();

        $em = EntityManagerFactory::build($this->conn);
        $categoryRepo = new CategoryRepository($em, $currentConfig);
        $imageRepo = $em->getRepository(ImageEntity::class);
        self::assertInstanceOf(ImageRepository::class, $imageRepo);

        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }

        $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        $permissionService = new PermissionService(
            new PermissionRepository($em),
            $em->getRepository(GroupEntity::class),
            $categoryRepo,
            CurrentUserTestFactory::get(),
            $filterState,
            $accessLevelChecker
        );
        $this->categoryService = new CategoryService(LangTestFactory::get(), $categoryRepo, $permissionService, CurrentConfigTestFactory::get(), new EventDispatcher(), TranslatorTestFactory::get(), $accessLevelChecker);

        $htmlService = HtmlServiceTestFactory::build();
        // mainpage_categories.tpl's own {assign var=derivative
        // value=$pwg->derivative(...)} constructs a real DerivativeImage per
        // category thumbnail, whose get_url() resolves UrlServiceInterface
        // live from the container -- $urlService below must share the same
        // container-shared RootPathOverride, see that class's own docblock.
        $rootPathOverride = Kernel::container()->get(RootPathOverride::class);
        if (! $rootPathOverride instanceof RootPathOverride) {
            throw new LogicException('Container returned an unexpected type for ' . RootPathOverride::class);
        }
        $urlService = UrlServiceTestFactory::build($htmlService, $rootPathOverride);

        $this->filterUpdater = new CategoryCatsRendererFakeFilterUpdater();

        $this->template = TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default');

        $currentLogger = new CurrentLogger();
        $currentLogger->set(new Logger(['severity' => Logger::OFF]));

        $processCache = Kernel::container()->get(ProcessCache::class);
        if (! $processCache instanceof ProcessCache) {
            throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
        }

        $this->renderer = new CategoryCatsRenderer(
            $this->filterUpdater,
            $htmlService,
            $this->template,
            $categoryRepo,
            $this->categoryService,
            $permissionService,
            $imageRepo,
            $urlService,
            $currentLogger,
            EventDispatcherTestFactory::get(),
            ImageStdParamsTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            LangTestFactory::get(),
            $processCache,
            PageStateTestFactory::get(),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public'");
        CachePools::categoryTree()->clear();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedUser(array $overrides = []): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray(array_merge([
            'id' => 1,
            'level' => 0,
            'forbidden_categories' => '',
            'status' => 'normal',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
            'recent_period' => 30,
            'last_photo_date' => null,
        ], $overrides)));
    }

    private function renderedCategoriesHtml(): string
    {
        $vars = $this->template->get_template_vars('CATEGORIES');

        return is_string($vars) ? $vars : '';
    }

    public function test_render_renders_the_root_categories_with_their_own_representative_picture(): void
    {
        $this->seedUser();

        $this->renderer->render('', null, 0);

        $html = $this->renderedCategoriesHtml();
        self::assertStringContainsString('Sample Album', $html);
        self::assertStringNotContainsString('Nested Sub Album', $html);
    }

    public function test_render_recent_cats_excludes_a_category_with_zero_images(): void
    {
        $this->seedUser();
        $result = $this->categoryService->createVirtualCategory('Empty Recent Test', new CategoryCatsRendererFakeActivityLogger(), CurrentUserTestFactory::get());
        $newIdRaw = $result['id'] ?? null;
        self::assertTrue(is_numeric($newIdRaw));
        $newId = (int) $newIdRaw;

        try {
            // The zero-image guard is checked (and returns false) before
            // isRecentCategory() ever runs, so this proves the category
            // never reaches the recency check at all, regardless of
            // whether cat 1/2 themselves qualify as "recent".
            $this->renderer->render('recent_cats', null, 0);

            $html = $this->renderedCategoriesHtml();
            self::assertStringNotContainsString('Empty Recent Test', $html);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $newId);
        }
    }

    public function test_render_skips_a_category_present_in_a_stale_cached_tree_but_deleted_from_the_db(): void
    {
        $this->seedUser();

        // A disposable root category with a real image, so it reaches
        // count_images > 0 -- unlike createVirtualCategory()'s own
        // zero-image "Empty Recent Test" sibling above (which never gets
        // anywhere near findFullCategoriesByIds() at all).
        $result = $this->categoryService->createVirtualCategory('Toctou Probe Album', new CategoryCatsRendererFakeActivityLogger(), CurrentUserTestFactory::get());
        $newIdRaw = $result['id'] ?? null;
        self::assertTrue(is_numeric($newIdRaw));
        $newId = (int) $newIdRaw;

        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('toctou-probe.jpg', 'upload/toctou-probe.jpg', '2026-08-01 00:00:00')"
        );
        $newImageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, ?)',
            [$newImageId, $newId]
        );
        // Real bug, found live: without this, render()'s own representative-
        // picture resolution (CategoryCatsRenderer, the class under test)
        // has no cached representative, no explicit representative_picture_id,
        // allowRandomRepresentative() is off by default, and this category
        // has no sub-categories -- every resolution branch fails, so the
        // category is legitimately, correctly skipped and logged as "no
        // image_id found", not rendered at all. createVirtualCategory()
        // itself never sets this (no images exist yet at creation time),
        // and a raw image_category insert (unlike a real upload flow)
        // doesn't either -- set it explicitly, matching what a real upload
        // actually does.
        $this->conn->executeStatement(
            'UPDATE ' . Tables::categories() . ' SET representative_picture_id = ? WHERE id = ?',
            [$newImageId, $newId]
        );

        try {
            // Primes CachePools::categoryTree()'s per-user 'tree_*' entry
            // (300s TTL, not cleared again until this test's own tearDown)
            // with a snapshot that includes the new category.
            $this->renderer->render('', null, 0);
            self::assertStringContainsString('Toctou Probe Album', $this->renderedCategoriesHtml());

            // Deleted from the live DB *without* touching the cache --
            // simulates the real race: the next render() call's own $tree
            // (a cache hit) still lists this category, but
            // findFullCategoriesByIds() (always a live read) no longer
            // finds it.
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $newId);

            // Must not throw/warn, and the real, still-existing category
            // must still render normally around the now-vanished one.
            $this->renderer->render('', null, 0);
            $html = $this->renderedCategoriesHtml();
            self::assertStringNotContainsString('Toctou Probe Album', $html);
            self::assertStringContainsString('Sample Album', $html);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $newId);
        }
    }

    public function test_render_picks_a_random_representative_when_allow_random_representative_is_enabled(): void
    {
        $this->seedUser();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setAllowRandomRepresentative(true);
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = NULL WHERE id = 2');

        try {
            $this->renderer->render('', ['id' => 1], 0);

            $item = CachePools::categoryTree()->getItem('repr_1_2');
            self::assertTrue($item->isHit());
            self::assertContains($item->get(), ['4', '5']);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 4 WHERE id = 2');
        }
    }

    public function test_render_picks_a_random_representative_among_subcategories_when_none_is_set_directly(): void
    {
        $this->seedUser();
        // allow_random_representative stays disabled (the real fixture
        // default) -- category 1 has sub-categories with images, so it
        // must fall back to a sub-category's own representative instead.
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = NULL WHERE id = 1');

        try {
            $this->renderer->render('', null, 0);

            // category 2 (the only sub-category) has exactly one
            // representative_picture_id set (4) -- the only possible match.
            $item = CachePools::categoryTree()->getItem('repr_1_1');
            self::assertTrue($item->isHit());
            self::assertSame('4', $item->get());
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');
        }
    }

    public function test_render_skips_a_category_with_no_resolvable_representative(): void
    {
        $this->seedUser();
        // category 2 has no sub-categories of its own, so with no direct
        // representative and allow_random_representative disabled, no
        // branch can resolve an image_id at all.
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = NULL WHERE id = 2');

        try {
            $this->renderer->render('', ['id' => 1], 0);

            $html = $this->renderedCategoriesHtml();
            self::assertStringNotContainsString('Nested Sub Album', $html);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 4 WHERE id = 2');
        }
    }

    public function test_render_shows_the_date_range_when_display_fromto_is_enabled(): void
    {
        $this->seedUser();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setDisplayFromto(true);
        // Only image 1's date_creation is set -- the other 2 direct images
        // of category 1 stay NULL, so MIN/MAX both resolve to this single
        // real value rather than just echoing back a NULL.
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2021-03-10 08:00:00' WHERE id = 1");

        try {
            $this->renderer->render('', null, 0);

            $expected = DateHelper::formatFromto('2021-03-10 08:00:00', '2021-03-10 08:00:00');
            $html = $this->renderedCategoriesHtml();
            self::assertStringContainsString($expected, $html);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id = 1');
        }
    }

    public function test_render_substitutes_a_representative_photo_whose_privacy_level_is_too_high(): void
    {
        // image_access_list='4' simulates a precomputed permission snapshot
        // that already excludes image 4 for this user (real production
        // shape -- see PermissionService::getSqlConditionFandF()'s own
        // 'image_access_list'/'image_access_type' fields); the images.level
        // column (checked directly, not via SQL, against the *fetched*
        // representative row) is what actually triggers the substitution.
        $this->seedUser(['image_access_list' => '4']);
        $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET level = 5 WHERE id = 4');

        try {
            $this->renderer->render('', ['id' => 1], 0);

            // category 2's real representative (4) is too high-level;
            // image 5 (also directly in category 2, not excluded) is the
            // only possible substitute.
            $item = CachePools::categoryTree()->getItem('repr_1_2');
            self::assertTrue($item->isHit());
            self::assertSame('5', $item->get());
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET level = 0 WHERE id = 4');
        }
    }

    public function test_render_caches_a_null_representative_when_no_privacy_safe_substitute_exists(): void
    {
        // Both of category 2's own images (4 and 5) excluded this time --
        // no substitute exists anywhere in the category.
        $this->seedUser(['image_access_list' => '4,5']);
        $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET level = 5 WHERE id = 4');

        try {
            $this->renderer->render('', ['id' => 1], 0);

            $item = CachePools::categoryTree()->getItem('repr_1_2');
            self::assertTrue($item->isHit());
            self::assertNull($item->get());
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET level = 0 WHERE id = 4');
        }
    }

    public function test_render_skips_a_category_whose_filtered_count_images_becomes_zero(): void
    {
        $this->seedUser();
        // Simulates a real tag/date filter that hides every one of
        // category 1's images post-rollup.
        $this->filterUpdater->forceCountImagesZeroFor[] = 1;

        $this->renderer->render('', null, 0);

        $html = $this->renderedCategoriesHtml();
        self::assertStringNotContainsString('Sample Album', $html);
    }

    public function test_render_throws_when_the_render_category_name_handler_returns_something_other_than_a_render_category_name_instance(): void
    {
        // addEventHandler(), not addTypedHandler() -- a real plugin
        // handler is untyped from PHPStan's perspective, and this test
        // exercises dispatchChange()'s own runtime enforcement, not a
        // static one.
        $this->seedUser();
        EventDispatcherTestFactory::get()->addEventHandler(RenderCategoryName::class, static fn (): int => 42);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('must return an instance of');

        try {
            $this->renderer->render('', null, 0);
        } finally {
            EventDispatcherTestFactory::get()->reset();
        }
    }
}
