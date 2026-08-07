<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Override;
    use LogicException;
    use Piwigo\Auth\AccessLevelChecker;
    use RuntimeException;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\FilterState;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Tests\Support\TranslatorTestFactory;
    use Error;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Site\SiteEntity;
    use Exception;
    use Doctrine\DBAL\Connection;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
    use Piwigo\Core\ActivityLoggerInterface;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Core\HtmlRenderingInterface;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Event\Album\GetCategoryPreferredImageOrders;
    use Piwigo\Event\Site\DeleteSite;
    use Piwigo\Tests\Support\ImageStdParamsTestFactory;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Users\User;

/**
 * Real fake for the 4 write methods' per-call ActivityLoggerInterface
 * parameter -- same standalone-class shape as
 * CategoryAdminServiceFakeActivityLogger (CategoryAdminServiceTest.php),
 * just under this file's own name to avoid a cross-file class collision.
 */
final class CategoryServiceFakeActivityLogger implements ActivityLoggerInterface
{
    /** @var list<array{object: string, objectId: int|string|array<int, int|string>, action: string, details: array<string, mixed>}> */
    public array $calls = [];

    #[Override]
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void
    {
        $this->calls[] = [
            'object' => $object,
            'objectId' => $objectId,
            'action' => $action,
            'details' => $details,
        ];
    }
}

/**
 * checkRestrictions()'s only real effect is delegating to
 * HtmlRenderingInterface::accessDenied() -- a real HtmlService would need
 * a real RedirectServiceInterface all the way down to a genuine
 * Response/exit path, so this fake short-circuits with a distinctive
 * marker exception instead. Every other interface method throws: none of
 * them are reachable through checkRestrictions().
 */
final class CategoryServiceFakeHtmlRendererDeniesAccess implements HtmlRenderingInterface
{
    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new RuntimeException('CATEGORY_SERVICE_ACCESS_DENIED_MARKER');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function renderElementName(array $info): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }
}

/**
 * checkRestrictions() only ever reaches accessDenied() -- this
 * RedirectServiceInterface fake is never actually invoked (the
 * HtmlRenderingInterface fake above throws before touching it), so every
 * method just documents that.
 */
final class CategoryServiceFakeRedirectServiceNeverCalled implements RedirectServiceInterface
{
    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }
}

/**
 * Same fixture shape as CategoryRepositoryTest: category 1 "Sample Album"
 * (root, 3 direct images), category 2 "Nested Sub Album" (child of 1, 2
 * direct images).
 */
final class CategoryServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryService $service;

    private CategoryRepository $repo;

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
        $this->repo = new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig);
        $this->service = new CategoryService(
            LangTestFactory::get(),
            $this->repo,
            new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig), CurrentUserTestFactory::get(), $filterState, new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)),
            CurrentConfigTestFactory::get(),
            EventDispatcherTestFactory::get(),
            TranslatorTestFactory::get(),
            new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)
        );

        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1]));
        $currentConfig->setRateEnabled(true);
        // getCategoryRepresentantProperties()'s own DerivativeImage::thumb_url()/
        // url() calls need a real, populated ImageStdParams type map --
        // DerivativeImage::urlService() itself now resolves
        // UrlServiceInterface live from the container once Kernel is
        // booted (singleton/service-locator elimination campaign, Phase
        // 6), which this test already is via parent::setUp() -- no
        // explicit wiring needed here anymore, same reasoning as
        // NotificationByMailSenderTest's own identical setUp.
        // ImageStdParams::load_from_db() itself needs CurrentConfigService.
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
        ImageStdParamsTestFactory::get()->load_from_db();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public'");
        // Safety-net restore for tests that touch old_permalinks' hit
        // counter (findCategoryIdFromPermalinks()'s is_old branch) --
        // matches CategoryRepositoryTest's own tearDown restore value.
        $this->conn->executeStatement('UPDATE ' . Tables::oldPermalinks() . " SET hit = 42, last_hit = '2026-07-07 05:02:38'");
        parent::tearDown();
    }

    public function test_compare_by_global_rank_orders_naturally(): void
    {
        $rows = [
            ['global_rank' => '1.10'],
            ['global_rank' => '1.2'],
        ];
        usort($rows, CategoryService::compareByGlobalRank(...));

        self::assertSame('1.2', $rows[0]['global_rank']);
    }

    public function test_compare_by_rank_orders_numerically(): void
    {
        $rows = [
            ['rank' => 10],
            ['rank' => 2],
        ];
        usort($rows, CategoryService::compareByRank(...));

        self::assertSame(2, $rows[0]['rank']);
    }

    public function test_get_category_info_returns_null_for_missing_category(): void
    {
        self::assertNull($this->service->getCategoryInfo(999999));
    }

    public function test_get_category_info_returns_a_single_level_upper_names_for_a_root_category(): void
    {
        $info = $this->service->getCategoryInfo(1);

        self::assertNotNull($info);
        self::assertSame([['id' => 1, 'name' => 'Sample Album', 'permalink' => null]], $info['upper_names']);
    }

    public function test_get_category_info_resolves_upper_names_for_a_nested_category(): void
    {
        $info = $this->service->getCategoryInfo(2);

        self::assertNotNull($info);
        $upperNames = $info['upper_names'];
        self::assertCount(2, $upperNames);
        $first = $upperNames[0];
        $second = $upperNames[1];
        self::assertSame('Sample Album', $first['name']);
        self::assertSame('Nested Sub Album', $second['name']);
    }

    public function test_get_category_info_coerces_true_false_string_columns_to_bool(): void
    {
        $info = $this->service->getCategoryInfo(1);

        self::assertNotNull($info);
        self::assertTrue($info['visible']);
    }

    public function test_get_preferred_image_orders_returns_the_fixed_option_list(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'status' => 'normal']));

        $orders = $this->service->getPreferredImageOrders();

        self::assertNotEmpty($orders);
        self::assertSame('', $orders[0][1]);
        // 'Permissions' (level DESC) is only visible to admins.
        $permissionsOrder = array_values(array_filter($orders, static fn (array $o): bool => $o[1] === 'level DESC'));
        self::assertFalse($permissionsOrder[0][2]);
    }

    public function test_get_preferred_image_orders_permissions_option_visible_to_admin(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'status' => 'admin']));

        $orders = $this->service->getPreferredImageOrders();

        $permissionsOrder = array_values(array_filter($orders, static fn (array $o): bool => $o[1] === 'level DESC'));
        self::assertTrue($permissionsOrder[0][2]);
    }

    public function test_get_subcategory_ids_includes_the_category_and_its_children(): void
    {
        $ids = $this->service->getSubcategoryIds([1]);
        sort($ids);

        self::assertSame([1, 2], $ids);
    }

    public function test_find_category_id_from_permalinks_matches_the_last_permalink_first(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET permalink = 'sample-album' WHERE id = 1");

        $idx = null;
        $catId = $this->service->findCategoryIdFromPermalinks(['no-match', 'sample-album'], $idx);

        self::assertSame(1, $catId);
        self::assertSame(1, $idx);

        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET permalink = NULL WHERE id = 1');
    }

    public function test_find_category_id_from_permalinks_returns_null_when_nothing_matches(): void
    {
        $idx = null;
        $catId = $this->service->findCategoryIdFromPermalinks(['no-such-permalink'], $idx);

        self::assertNull($catId);
    }

    public function test_get_display_images_count_reports_photos_and_subalbums(): void
    {
        $text = CategoryService::getDisplayImagesCount(LangTestFactory::get(), 0, 5, 1, false, ' / ');

        self::assertStringContainsString('5', $text);
        self::assertStringContainsString('1', $text);
    }

    public function test_get_display_images_count_returns_empty_string_for_zero_images(): void
    {
        self::assertSame('', CategoryService::getDisplayImagesCount(LangTestFactory::get(), 0, 0, 0));
    }

    public function test_get_random_image_in_category_returns_null_for_an_empty_category(): void
    {
        self::assertNull($this->service->getRandomImageInCategory([
            'id' => 1,
            'uppercats' => '1',
            'count_images' => 0,
        ]));
    }

    public function test_get_random_image_in_category_returns_an_image_id(): void
    {
        $imageId = $this->service->getRandomImageInCategory([
            'id' => 1,
            'uppercats' => '1',
            'count_images' => 3,
        ], false);

        self::assertContains($imageId, [1, 2, 3]);
    }

    public function test_get_computed_categories_rolls_up_child_counts_into_parent(): void
    {
        $userdata = ['id' => 1, 'level' => 0, 'forbidden_categories' => ''];

        $result = $this->service->getComputedCategories($userdata);
        $cats = $result['categories'];

        // category 1 (root) should count its own 3 images plus category
        // 2's 2 images in its subtree total.
        self::assertSame(5, $cats[1]['count_images']);
        self::assertSame(1, $cats[1]['count_categories']);
        self::assertSame(2, $cats[2]['count_images']);
        self::assertNotNull($result['lastPhotoDate']);
    }

    public function test_get_computed_categories_prunes_categories_with_no_recent_activity(): void
    {
        $userdata = ['id' => 1, 'level' => 0, 'forbidden_categories' => ''];

        // filterDays=0 against fixture dates in the past means nothing
        // qualifies as "recent" -- every category should be pruned.
        $result = $this->service->getComputedCategories($userdata, 0);

        self::assertSame([], $result['categories']);
    }

    public function test_remove_computed_category_decrements_parent_counters(): void
    {
        $cats = [
            1 => ['cat_id' => 1, 'id_uppercat' => null, 'global_rank' => null, 'rank' => null, 'date_last' => null, 'nb_images' => 3, 'user_id' => 1, 'nb_categories' => 1, 'count_images' => 5, 'count_categories' => 1, 'max_date_last' => null],
            2 => ['cat_id' => 2, 'id_uppercat' => 1, 'global_rank' => null, 'rank' => null, 'date_last' => null, 'nb_images' => 2, 'user_id' => 1, 'nb_categories' => 0, 'count_images' => 2, 'count_categories' => 0, 'max_date_last' => null],
        ];

        CategoryService::removeComputedCategory($cats, $cats[2]);

        self::assertArrayNotHasKey(2, $cats);
        self::assertSame(0, $cats[1]['nb_categories']);
        self::assertSame(3, $cats[1]['count_images']);
        self::assertSame(0, $cats[1]['count_categories']);
    }

    public function test_get_image_ids_for_categories_returns_images(): void
    {
        $ids = $this->service->getImageIdsForCategories([1], 'AND', false);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_get_common_categories_counts_images_per_category(): void
    {
        $common = $this->service->getCommonCategories([1, 2, 3], null, [], false);

        self::assertSame(3, $common['1']['counter']);
    }

    public function test_get_related_categories_menu_sets_count_images_for_directly_linked_categories(): void
    {
        $cats = $this->service->getRelatedCategoriesMenu([1, 2, 3], []);

        $byId = [];
        foreach ($cats as $cat) {
            $catId = $cat['id'];
            if (is_int($catId) || is_string($catId)) {
                $byId[$catId] = $cat;
            }
        }

        self::assertSame(3, $byId['1']['count_images']);
        self::assertArrayHasKey('LEVEL', $byId['1']);
    }

    public function test_get_related_categories_menu_returns_empty_for_no_items(): void
    {
        self::assertSame([], $this->service->getRelatedCategoriesMenu([], []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function realisticUserGlobal(): array
    {
        // Matches getuserdata()'s own guaranteed shape (include/functions_user.inc.php):
        // 'forbidden_categories' always at least '0', 'image_access_type'
        // always the literal 'NOT IN', 'level' always a numeric string --
        // an incomplete fixture (e.g. missing 'level') lets
        // getSqlConditionFandF()'s forbidden_images fallthrough build a
        // malformed 'level <=' fragment with no right-hand value, a state
        // that can't happen in production.
        return [
            'id' => 1,
            'forbidden_categories' => '0',
            'level' => '0',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
        ];
    }

    public function test_get_image_ids_for_categories_with_permissions_builds_valid_sql(): void
    {
        // exercising usePermissions=true with a genuinely non-empty
        // condition is what catches the andWhere() double-AND-wrap bug
        // that an empty (guest-default) CurrentUser silently skips.
        CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));

        $ids = $this->service->getImageIdsForCategories([1], 'AND', true);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_get_common_categories_with_permissions_builds_valid_sql(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));

        $common = $this->service->getCommonCategories([1, 2, 3], null, [4, 5], true);

        self::assertSame(3, $common['1']['counter']);
    }

    public function test_get_related_categories_menu_with_permissions_builds_valid_sql(): void
    {
        // getRelatedCategoriesMenu() always calls getCommonCategories()
        // with usePermissions defaulted to true internally -- this is the
        // real menubar.inc.php code path the bug above was caught on.
        CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));

        $cats = $this->service->getRelatedCategoriesMenu([1, 2, 3], []);

        self::assertNotSame([], $cats);
    }

    public function test_get_preferred_image_orders_throws_when_the_event_handler_returns_something_other_than_a_get_category_preferred_image_orders_instance(): void
    {
        // addEventHandler(), not addTypedHandler() -- a real plugin
        // handler is untyped from PHPStan's perspective, and this test
        // exercises dispatchChange()'s own runtime enforcement, not a
        // static one.
        EventDispatcherTestFactory::get()->addEventHandler(GetCategoryPreferredImageOrders::class, static fn (): string => 'not-an-array');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('must return an instance of');

        try {
            $this->service->getPreferredImageOrders();
        } finally {
            EventDispatcherTestFactory::get()->reset();
        }
    }

    public function test_get_preferred_image_orders_skips_a_malformed_entry_from_the_event_handler(): void
    {
        EventDispatcherTestFactory::get()->addTypedHandler(
            GetCategoryPreferredImageOrders::class,
            // Missing the 3rd (visibility) element -- malformed, must be
            // skipped rather than crash on an undefined offset.
            static fn (): GetCategoryPreferredImageOrders => new GetCategoryPreferredImageOrders([
                ['Only Two Elements', 'name ASC'],
                ['Real Order', 'hit DESC', true],
            ])
        );

        try {
            $orders = $this->service->getPreferredImageOrders();
        } finally {
            EventDispatcherTestFactory::get()->reset();
        }

        self::assertSame([['Real Order', 'hit DESC', true]], $orders);
    }

    public function test_find_category_id_from_permalinks_skips_a_non_match_before_finding_an_older_match(): void
    {
        // Checked from the end first: 'no-such-permalink' misses
        // (continue), then 'old-sample-album' (the fixture's real
        // old_permalinks row, cat_id=1) matches -- also proves the is_old
        // branch touches touchOldPermalinkHit()'s hit counter.
        $idx = null;
        $catId = $this->service->findCategoryIdFromPermalinks(['old-sample-album', 'no-such-permalink'], $idx);

        self::assertSame(1, $catId);
        self::assertSame(0, $idx);

        $hit = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::oldPermalinks())
            ->where("permalink = 'old-sample-album'")
            ->executeQuery()
            ->fetchOne();
        self::assertSame(43, is_numeric($hit) ? (int) $hit : null);
    }

    public function test_find_category_id_from_permalinks_returns_null_when_the_sql_match_has_no_exact_string_key(): void
    {
        // MySQL's default VARCHAR comparison ignores trailing whitespace
        // (PAD SPACE semantics, confirmed live even under old_permalinks'
        // own utf8mb4_bin collation: 'old-sample-album' =
        // 'old-sample-album ' evaluates true) -- a permalink string with
        // trailing whitespace therefore SQL-matches findPermalinkMatches()'s
        // own WHERE...IN() clause (populating $permaHash with the
        // *stored*, unpadded key) but fails this method's own exact-string
        // isset() lookup against the very padded string it queried with.
        // The real, narrow gap this defensive tail return guards.
        $idx = null;
        $catId = $this->service->findCategoryIdFromPermalinks(['old-sample-album '], $idx);

        self::assertNull($catId);
        self::assertNull($idx);
    }

    public function test_get_computed_categories_walks_up_through_more_than_one_ancestor_level(): void
    {
        // `rank` is a genuine reserved word on both platforms (a bare
        // backtick is MySQL-only); visible/commentable are genuine
        // boolean columns (a bare `1` literal is rejected outright by
        // Postgres). Both confirmed live.
        $rank = $this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank');
        $trueLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::categories() . " (name, id_uppercat, {$rank}, status, visible, uppercats, commentable, global_rank) VALUES ('Grandchild Album', 2, 1, 'public', {$trueLiteral}, '1,2,0', {$trueLiteral}, '1.1.1')"
        );
        $grandchildId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '1,2,{$grandchildId}' WHERE id = {$grandchildId}");
        // Reuse image 1 (already directly in category 1) as a second,
        // additional link into the new grandchild -- proves the walk
        // propagates a real image count up two ancestor levels, not just
        // one.
        $this->conn->executeStatement("INSERT INTO " . Tables::imageCategory() . " (image_id, category_id) VALUES (1, {$grandchildId})");

        try {
            $userdata = ['id' => 1, 'level' => 0, 'forbidden_categories' => ''];
            $result = $this->service->getComputedCategories($userdata);
            $cats = $result['categories'];

            self::assertSame(1, $cats[$grandchildId]['count_images']);
            self::assertSame(3, $cats[2]['count_images']); // own 2 + grandchild's 1
            self::assertSame(1, $cats[2]['count_categories']);
            self::assertSame(6, $cats[1]['count_images']); // own 3 + cat2's 2 + grandchild's 1
            self::assertSame(2, $cats[1]['count_categories']); // cat2 + grandchild
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::categories() . " WHERE id = {$grandchildId}");
        }
    }

    public function test_get_related_categories_menu_skips_a_stale_ancestor_id_in_a_corrupted_uppercats_path(): void
    {
        // Simulates category 2's uppercats containing a stale ancestor id
        // (999) that findCategoriesByIds() can't resolve (no such
        // category) -- the real ancestor (1) must still get its
        // count_categories incremented despite the phantom one.
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '1,999,2' WHERE id = 2");

        try {
            $cats = $this->service->getRelatedCategoriesMenu([4], []);

            $byId = [];
            foreach ($cats as $cat) {
                $catId = $cat['id'];
                if (is_int($catId) || is_string($catId)) {
                    $byId[$catId] = $cat;
                }
            }

            self::assertSame(1, $byId['1']['count_categories']);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '1,2' WHERE id = 2");
        }
    }

    public function test_check_restrictions_denies_access_to_a_forbidden_category(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'forbidden_categories' => '2,5']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CATEGORY_SERVICE_ACCESS_DENIED_MARKER');

        $this->service->checkRestrictions(2, new CategoryServiceFakeHtmlRendererDeniesAccess(), new CategoryServiceFakeRedirectServiceNeverCalled(), CurrentUserTestFactory::get());
    }

    public function test_get_subcat_ids_warns_and_skips_a_non_numeric_id(): void
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = $errstr;
            return true;
        });
        try {
            $ids = $this->service->getSubcatIds([1, 'not-a-number']);
        } finally {
            restore_error_handler();
        }
        sort($ids);

        self::assertSame([1, 2], $ids);
        self::assertSame('getSubcatIds expecting numeric, not string', $captured);
    }

    public function test_get_related_categories_menu_with_urls_skips_a_row_with_no_count_images(): void
    {
        // image 4 is only directly linked to category 2 -- category 1 (a
        // pure ancestor, reached only via cat2's own uppercats path) never
        // gets a 'count_images' key set. The loop's own isset() guard
        // "skips" building a 'url' for it (not building one would
        // otherwise crash on an undefined offset further down), but the
        // category itself stays in the returned list -- confirmed live,
        // and by design: menubar_related_categories.tpl's own {foreach}
        // needs the ancestor present to correctly nest category 2 under
        // it (LEVEL-based <ul> nesting), it just renders a bare link with
        // no href via its own isset($cat.url) guard.
        $cats = $this->service->getRelatedCategoriesMenuWithUrls([4], UrlServiceTestFactory::build());

        $ids = array_map(static fn (array $c): mixed => $c['id'], $cats);
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);

        $catsById = [];
        foreach ($cats as $cat) {
            $catId = $cat['id'];
            if (is_int($catId) || is_string($catId)) {
                $catsById[$catId] = $cat;
            }
        }
        self::assertArrayNotHasKey('url', $catsById[1]);
        self::assertArrayHasKey('url', $catsById[2]);
    }

    public function test_get_related_categories_menu_with_urls_merges_combined_categories_into_the_url(): void
    {
        $urlService = UrlServiceTestFactory::build();
        $priorCat = ['id' => 99, 'name' => 'Prior Combined Category', 'permalink' => null];
        $pageCategory = ['id' => 1, 'name' => 'Sample Album', 'permalink' => null];

        $cats = $this->service->getRelatedCategoriesMenuWithUrls(
            [4],
            $urlService,
            [],
            $pageCategory,
            [$priorCat]
        );

        $byId = [];
        foreach ($cats as $cat) {
            $catId = $cat['id'];
            if (is_int($catId) || is_string($catId)) {
                $byId[$catId] = $cat;
            }
        }

        // Same real UrlService::makeIndexUrl() call the production code
        // itself makes -- proves $combinedCategories was actually merged
        // in (not just replaced) rather than asserting a loose substring.
        $expectedUrl = $urlService->makeIndexUrl([
            'category' => $pageCategory,
            'combined_categories' => [$priorCat, $byId[2]],
        ]);

        self::assertSame($expectedUrl, $byId[2]['url']);
    }

    public function test_delete_categories_delete_orphans_mode_preserves_an_image_still_linked_elsewhere(): void
    {
        $activityLogger = new CategoryServiceFakeActivityLogger();
        $urlService = UrlServiceTestFactory::build();

        $result = $this->service->createVirtualCategory('Orphan Diff Temp', $activityLogger, CurrentUserTestFactory::get());
        $tempIdRaw = $result['id'] ?? null;
        self::assertTrue(is_numeric($tempIdRaw));
        $tempId = (int) $tempIdRaw;

        // image 1 already belongs to category 1 -- linking it into the
        // temp category too makes it a real "non-orphan": deleting the
        // temp category must NOT delete it, unlike a genuinely orphaned
        // image.
        $this->conn->executeStatement("INSERT INTO " . Tables::imageCategory() . " (image_id, category_id) VALUES (1, {$tempId})");

        $this->service->deleteCategories([$tempId], $activityLogger, $urlService, new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), 'delete_orphans');

        self::assertNull($this->repo->findById($tempId));
        $stillLinked = $this->conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from(Tables::imageCategory())
            ->where('image_id = 1 AND category_id = 1')
            ->executeQuery()
            ->fetchOne();
        self::assertSame(1, is_numeric($stillLinked) ? (int) $stillLinked : null);
    }

    public function test_delete_site_deletes_the_sites_categories_and_dispatches_delete_site_for_the_row_itself(): void
    {
        // Item 16E: deleteSite() had zero existing coverage. Category
        // can't depend on Site directly (a real deptrac boundary), so
        // the site's own `sites` row is now deleted by a real listener
        // on DeleteSite instead of a direct CategoryRepository call --
        // this registers the SAME listener shape RequestBootstrap.php
        // itself registers in production, to prove the wiring (not just
        // the individual pieces) actually works end to end.
        $siteRepo = EntityManagerFactory::build($this->conn)->getRepository(SiteEntity::class);
        $siteUrl = 'p17-test-delete-site-' . bin2hex(random_bytes(4));
        $siteRepo->insert($siteUrl);
        // lastInsertId() isn't reliable straight after an ORM persist()+
        // flush() -- every other real caller in this codebase reads the
        // id back with a plain SELECT instead (see SiteRepositoryTest's
        // own identical pattern).
        $rawSiteId = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::sites())
            ->where('galleries_url = :url')
            ->setParameter('url', $siteUrl)
            ->executeQuery()
            ->fetchOne();
        self::assertTrue(is_numeric($rawSiteId));
        $siteId = (int) $rawSiteId;

        $categoryId = $this->service->createVirtualCategory('Site Delete Temp', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get())['id'] ?? null;
        self::assertTrue(is_numeric($categoryId));
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET site_id = ? WHERE id = ?', [$siteId, $categoryId]);

        $handler = static function (DeleteSite $e) use ($siteRepo): void {
            $siteRepo->delete($e->siteId);
        };
        EventDispatcherTestFactory::get()->addTypedHandler(DeleteSite::class, $handler);

        try {
            $this->service->deleteSite($siteId, new CategoryServiceFakeActivityLogger(), UrlServiceTestFactory::build(), new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get());

            self::assertNull($this->repo->findById((int) $categoryId));
            self::assertNull($siteRepo->findGalleriesUrlById($siteId));
        } finally {
            EventDispatcherTestFactory::get()->removeEventHandler(DeleteSite::class, $handler);
        }
    }

    public function test_check_categories_integrity_deletes_orphaned_image_category_user_access_and_group_access_rows(): void
    {
        // Item 16I: findOrphanedColumnValues()/deleteRowsWhereColumnIn()
        // converted 3 of CategoryOrphanTarget's 4 cases to real DQL --
        // this file's own sibling test only ever exercised the 4th
        // (OldPermalinks, which stays on raw DBAL, a real deptrac
        // boundary), leaving these 3 with zero real coverage of their
        // own orphan-cleanup behavior specifically. All 3 target tables
        // carry a real ON DELETE CASCADE FK on the category-id column,
        // so a genuine orphan can never arise through normal writes --
        // disabling FK checks just for these inserts reproduces the
        // only real way this state has ever existed in practice, same
        // pattern FilesystemIntegrityCheckerTest's own orphan tests use.
        $this->disableForeignKeyChecks($this->conn);
        $this->conn->executeStatement('INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (1, 60000)');
        $this->conn->executeStatement('INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (1, 60000)');
        $this->conn->executeStatement('INSERT INTO ' . Tables::groupAccess() . ' (group_id, cat_id) VALUES (1, 60000)');
        $this->enableForeignKeyChecks($this->conn);

        try {
            $this->service->checkCategoriesIntegrity();

            $orphanedImageCategoryCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from(Tables::imageCategory())
                ->where('image_id = 1 AND category_id = 60000')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($orphanedImageCategoryCount) ? (int) $orphanedImageCategoryCount : null);

            $orphanedUserAccessCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from(Tables::userAccess())
                ->where('user_id = 1 AND cat_id = 60000')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($orphanedUserAccessCount) ? (int) $orphanedUserAccessCount : null);

            $orphanedGroupAccessCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from(Tables::groupAccess())
                ->where('group_id = 1 AND cat_id = 60000')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($orphanedGroupAccessCount) ? (int) $orphanedGroupAccessCount : null);

            // A real, non-orphaned image_category row (fixture image 1 in
            // fixture category 1) survives untouched.
            $realImageCategoryCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from(Tables::imageCategory())
                ->where('image_id = 1 AND category_id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(1, is_numeric($realImageCategoryCount) ? (int) $realImageCategoryCount : null);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = 1 AND category_id = 60000');
            $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess() . ' WHERE user_id = 1 AND cat_id = 60000');
            $this->conn->executeStatement('DELETE FROM ' . Tables::groupAccess() . ' WHERE group_id = 1 AND cat_id = 60000');
        }
    }

    public function test_check_categories_integrity_deletes_an_orphaned_old_permalinks_row_and_keeps_a_real_one(): void
    {
        // cat_id is smallint unsigned (max 65535) -- 999999 (used
        // elsewhere in this file only as a query parameter, never a raw
        // INSERT value) would overflow it here.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::oldPermalinks() . " (cat_id, permalink, hit) VALUES (60000, 'orphaned-permalink-test', 0)"
        );

        $this->service->checkCategoriesIntegrity();

        $orphanCount = $this->conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from(Tables::oldPermalinks())
            ->where("permalink = 'orphaned-permalink-test'")
            ->executeQuery()
            ->fetchOne();
        self::assertSame(0, is_numeric($orphanCount) ? (int) $orphanCount : null);

        $realCount = $this->conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from(Tables::oldPermalinks())
            ->where("permalink = 'old-sample-album'")
            ->executeQuery()
            ->fetchOne();
        self::assertSame(1, is_numeric($realCount) ? (int) $realCount : null);
    }

    public function test_update_global_rank_replaces_a_stale_uppercats_segment_with_an_empty_string(): void
    {
        // Simulates a category whose uppercats column still references an
        // ancestor id that no longer exists (deleted without a full
        // uppercats resync) -- catMapCallback()'s own defensive ''
        // fallback for an unmatched captured id.
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '1,999,2' WHERE id = 2");

        try {
            $this->service->updateGlobalRank();

            $globalRank = $this->conn->createQueryBuilder()
                ->select('global_rank')
                ->from(Tables::categories())
                ->where('id = 2')
                ->executeQuery()
                ->fetchOne();
            // '1,999,2' -> '1.999.2' with each digit run replaced by that
            // category's own freshly-computed rank -- '1' and '2' resolve
            // normally (both rank 1 in this fixture), '999' resolves to ''
            // (no such category), leaving a visibly empty segment rather
            // than a crash.
            self::assertSame('1..1', $globalRank);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '1,2', global_rank = '1.1' WHERE id = 2");
        }
    }

    public function test_set_cat_visible_warns_and_returns_false_for_an_invalid_value(): void
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = $errstr;
            return true;
        });
        try {
            $result = $this->service->setCatVisible([1], 'not-a-boolean');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
        self::assertSame('setCatVisible invalid param not-a-boolean', $captured);
    }

    public function test_set_cat_status_warns_and_returns_false_for_an_invalid_value(): void
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = $errstr;
            return true;
        });
        try {
            $result = $this->service->setCatStatus([1], 'archived');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
        self::assertSame('setCatStatus invalid param archived', $captured);
    }

    public function test_set_cat_status_public_makes_parent_categories_public_too(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private'");

        $this->service->setCatStatus([2], 'public');

        self::assertSame('public', $this->repo->findCategoryStatus(1));
        self::assertSame('public', $this->repo->findCategoryStatus(2));
    }

    public function test_set_cat_status_private_uses_the_private_parent_as_the_permission_reference(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private' WHERE id = 1");
        $this->conn->executeStatement('INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (3, 2)');

        try {
            $this->service->setCatStatus([2], 'private');

            // category 1 (the reference, since it's already private)
            // grants no direct user access at all -- the
            // inconsistent-access sweep must fall back to the sentinel
            // (-1) keep-list and remove category 2's own now-inconsistent
            // user_access row, proving the *parent* was used as the
            // reference rather than category 2 itself (which would have
            // kept its own row as "consistent with itself").
            $remaining = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from(Tables::userAccess())
                ->where('cat_id = 2')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($remaining) ? (int) $remaining : null);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess() . ' WHERE cat_id IN (1, 2)');
        }
    }

    public function test_set_cat_status_private_removes_inconsistent_group_access_too(): void
    {
        // Item 16I: deleteInconsistentAccess() converted CategoryAccessTarget::
        // GroupAccess to real DQL too, alongside UserAccess above -- same
        // code path, but the sibling test only ever exercised UserAccess
        // (setCatStatus()'s own foreach(CategoryAccessTarget::cases())
        // loop runs both every time, just without a group_access-specific
        // assertion), so this closes that gap directly. A throwaway new
        // group, not a real fixture one -- the fixture's own groups 1-3
        // already have real access to category 1 (the "reference"),
        // which would make it the *consistent* case this test isn't
        // trying to cover.
        $this->conn->executeStatement("INSERT INTO " . Tables::groups() . " (name) VALUES ('zzz-16i-probe-group')");
        $groupId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private' WHERE id = 1");
        $this->conn->executeStatement('INSERT INTO ' . Tables::groupAccess() . ' (group_id, cat_id) VALUES (?, 2)', [$groupId]);

        try {
            $this->service->setCatStatus([2], 'private');

            $remaining = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from(Tables::groupAccess())
                ->where('cat_id = 2 AND group_id = :groupId')
                ->setParameter('groupId', $groupId)
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($remaining) ? (int) $remaining : null);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::groupAccess() . ' WHERE group_id = ?', [$groupId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::groups() . ' WHERE id = ?', [$groupId]);
        }
    }

    public function test_get_category_representant_properties_throws_for_a_missing_image(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('getCategoryRepresentantProperties(): image 999999 does not exist (stale representative_picture_id?)');

        $this->service->getCategoryRepresentantProperties(999999, UrlServiceTestFactory::build());
    }

    public function test_get_category_representant_properties_returns_a_thumb_url_when_size_is_null(): void
    {
        $urlService = UrlServiceTestFactory::build();

        $props = $this->service->getCategoryRepresentantProperties(1, $urlService);

        self::assertArrayHasKey('src', $props);
        self::assertSame($urlService->getRootUrl() . 'admin.php?page=photo-1', $props['url']);
    }

    public function test_update_path_rewrites_image_paths_for_storage_linked_categories(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET dir = 'sample-album', site_id = 1 WHERE id = 1");
        $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = 1 WHERE id = 1');

        try {
            $this->service->updatePath(EntityManagerFactory::build($this->conn)->getRepository(SiteEntity::class));

            $path = $this->conn->createQueryBuilder()
                ->select('path')
                ->from(Tables::images())
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();
            // Piwigo\Core\Paths::$root-derived, not a hardcoded literal --
            // same "machine-specific, not portable" reasoning as
            // SiteRepositoryTest's own galleries_url assertion; site id=1's
            // own galleries_url (this method's real data source) is
            // corrected to match at fixture-load time, see
            // IntegrationTestCase::loadFixture()'s own docblock.
            self::assertSame(CurrentPathsTestFactory::get()->root . 'galleries/sample-album/fixture-photo-1.jpg', $path);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET dir = NULL, site_id = NULL WHERE id = 1");
            $this->conn->executeStatement("UPDATE " . Tables::images() . " SET storage_category_id = NULL, path = 'upload/2026/08/01/20260801000000-2e7e64c7.jpg' WHERE id = 1");
        }
    }

    public function test_move_categories_rejects_moving_a_category_into_its_own_sub_album(): void
    {
        PageStateTestFactory::get()->reset();
        $activityLogger = new CategoryServiceFakeActivityLogger();

        $this->service->moveCategories([1], $activityLogger, PageStateTestFactory::get(), 2);

        self::assertContains('You cannot move an album in its own sub album', PageStateTestFactory::get()->errors);
        // the move must not have actually happened.
        $idUppercat = $this->conn->createQueryBuilder()
            ->select('id_uppercat')
            ->from(Tables::categories())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        self::assertNull($idUppercat);
    }

    public function test_create_virtual_category_returns_an_error_when_the_parent_does_not_exist(): void
    {
        $result = $this->service->createVirtualCategory('Orphan Parent Test', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), 999999);

        self::assertSame(['error' => 'The parent album does not exist'], $result);
    }

    public function test_create_virtual_category_inherits_invisibility_from_an_invisible_parent(): void
    {
        // visible is a genuine boolean column -- bare `0`/`1` literals in
        // the SQL text (unlike a bound parameter, which the driver
        // coerces implicitly) are rejected outright by Postgres.
        $falseLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
        $trueLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET visible = {$falseLiteral} WHERE id = 1");

        try {
            $result = $this->service->createVirtualCategory('Invisible Child Test', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), 1);
            $newIdRaw = $result['id'] ?? null;
            self::assertTrue(is_numeric($newIdRaw));
            $newId = (int) $newIdRaw;

            $visible = $this->conn->createQueryBuilder()
                ->select('visible')
                ->from(Tables::categories())
                ->where('id = ' . $newId)
                ->executeQuery()
                ->fetchOne();
            // A native PHP bool on Postgres, numeric 1/0 on MySQL.
            self::assertSame(0, (int) (bool) $visible);

            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $newId);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET visible = {$trueLiteral} WHERE id = 1");
        }
    }

    public function test_create_virtual_category_with_inherit_propagates_the_parents_groups_and_users(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET status = 'private' WHERE id = 1");
        $this->conn->executeStatement('INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (3, 1)');

        try {
            $result = $this->service->createVirtualCategory('Inherited Child Test', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), 1, ['inherit' => true]);
            $newIdRaw = $result['id'] ?? null;
            self::assertTrue(is_numeric($newIdRaw));
            $newId = (int) $newIdRaw;

            // category 1's own fixture groups (1, 2, 3) must all have been
            // copied onto the new child.
            $groupIds = $this->repo->findAccessGroupIds($newId);
            sort($groupIds);
            self::assertSame([1, 2, 3], $groupIds);

            // the user_access row just added to category 1 must also have
            // been copied.
            self::assertSame([3], $this->repo->findAccessUserIds($newId));

            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $newId);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess() . ' WHERE cat_id = 1');
        }
    }

    public function test_get_image_ids_for_categories_returns_empty_for_no_category_ids(): void
    {
        // the empty-input early return -- never reaches the repository or
        // the permission-condition SQL building below it.
        self::assertSame([], $this->service->getImageIdsForCategories([]));
    }

    public function test_update_category_with_a_scalar_id_clears_a_stale_representative_picture_id(): void
    {
        // updateCategory()'s own docblock: real WS callers
        // (ws_functions/pwg.images.php) pass a raw, never-int-cast scalar
        // category id, not an array -- calling with a bare int here
        // exercises that '%s=' . $ids scalar branch directly (as opposed
        // to the 'all' and array branches already covered elsewhere).
        //
        // representative_picture_id has a real FK to images.id (ON DELETE
        // SET NULL, see fk_categories_representative_picture_id) -- a
        // genuinely dangling value can only exist in practice from a bulk
        // import/migration that ran with checks off (same rationale as
        // UserServiceTest's own FK-disabled inserts), so that's
        // reproduced here rather than a plain UPDATE, which the live FK
        // would reject outright.
        $this->disableForeignKeyChecks($this->conn);
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 999999 WHERE id = 1');
        $this->enableForeignKeyChecks($this->conn);

        try {
            $result = $this->service->updateCategory(1);

            self::assertNull($result);

            $repId = $this->conn->createQueryBuilder()
                ->select('representative_picture_id')
                ->from(Tables::categories())
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();
            // the scalar '%s=1' substitution scoped the wrong-representative
            // sweep to just category 1 (proving the scalar branch built
            // valid, correctly-targeted SQL rather than e.g. a malformed
            // "WHERE 1=1"-style fragment, which would have matched every
            // category) -- clearRepresentativePictureIds() nulls the bogus
            // 999999, then the immediately-following
            // !allowRandomRepresentative() repair branch (default false,
            // not itself one of this test's target lines) repicks a real
            // image from category 1's own 3 fixture images, proving the
            // bogus id didn't survive the sweep.
            self::assertContains(is_numeric($repId) ? (int) $repId : null, [1, 2, 3]);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');
        }
    }

    public function test_set_cat_visible_with_unlock_child_unlocks_descendant_categories_too(): void
    {
        // visible is a genuine boolean column -- bare `0`/`1` literals in
        // the SQL text (unlike a bound parameter, which the driver
        // coerces implicitly) are rejected outright by Postgres.
        $falseLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
        $trueLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET visible = {$falseLiteral} WHERE id = 2");

        try {
            // unlockChild=true merges getSubcatIds([1]) (== [1, 2]) into
            // the ancestor-only list getUppercatIds([1]) would otherwise
            // produce (just [1], category 1 has no parent of its own) --
            // category 2 only ends up unlocked because of that merge.
            $this->service->setCatVisible([1], true, true);

            $visible = $this->conn->createQueryBuilder()
                ->select('visible')
                ->from(Tables::categories())
                ->where('id = 2')
                ->executeQuery()
                ->fetchOne();
            // A native PHP bool on Postgres, numeric 1/0 on MySQL.
            self::assertSame(1, (int) (bool) $visible);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET visible = {$trueLiteral} WHERE id = 2");
        }
    }

    public function test_move_categories_returns_early_for_no_category_ids(): void
    {
        PageStateTestFactory::get()->reset();
        $activityLogger = new CategoryServiceFakeActivityLogger();

        $this->service->moveCategories([], $activityLogger, PageStateTestFactory::get());

        // the count()===0 early return skips updateCategoryParent(),
        // updateUppercats()/updateGlobalRank(), the PageState::addInfo()
        // summary and the activity-log record below it entirely -- an
        // empty $categoryIds reaching any of that would still "succeed"
        // silently, so the only observable proof of the early return is
        // that none of it ran.
        self::assertSame([], $activityLogger->calls);
        self::assertSame([], PageStateTestFactory::get()->infos);
    }

    public function test_move_categories_to_root_sets_parent_status_public(): void
    {
        PageStateTestFactory::get()->reset();
        $activityLogger = new CategoryServiceFakeActivityLogger();

        try {
            // default $newParent = -1 -> $newParentSql = 'NULL' -> moving
            // to root, the branch that hardcodes $parentStatus = 'public'
            // rather than looking an actual parent category up.
            $this->service->moveCategories([2], $activityLogger, PageStateTestFactory::get());

            $idUppercat = $this->conn->createQueryBuilder()
                ->select('id_uppercat')
                ->from(Tables::categories())
                ->where('id = 2')
                ->executeQuery()
                ->fetchOne();
            self::assertNull($idUppercat);
            self::assertSame('public', $this->repo->findCategoryStatus(2));
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::categories() . " SET id_uppercat = 1, uppercats = '1,2', global_rank = '1.1' WHERE id = 2"
            );
        }
    }

    public function test_move_categories_into_a_private_parent_cascades_private_status(): void
    {
        PageStateTestFactory::get()->reset();
        $activityLogger = new CategoryServiceFakeActivityLogger();

        $privateParent = $this->service->createVirtualCategory(
            'ct_move_private_parent_' . uniqid(),
            $activityLogger,
            CurrentUserTestFactory::get(),
            null,
            ['status' => 'private']
        );
        $privateParentIdRaw = $privateParent['id'] ?? null;
        self::assertTrue(is_numeric($privateParentIdRaw));
        $privateParentId = (int) $privateParentIdRaw;

        try {
            // moving into a real, non-root parent (status looked up via
            // findCategoryStatus()) that happens to be private -- the
            // setCatStatus(..., 'private') cascade onto the moved
            // categories themselves only fires on this branch.
            $this->service->moveCategories([2], $activityLogger, PageStateTestFactory::get(), $privateParentId);

            self::assertSame('private', $this->repo->findCategoryStatus(2));
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::categories() . " SET id_uppercat = 1, uppercats = '1,2', global_rank = '1.1', status = 'public' WHERE id = 2"
            );
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $privateParentId);
        }
    }

    public function test_create_virtual_category_with_last_position_ranks_after_existing_siblings(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setNewcatDefaultPosition('last');

        try {
            $result = $this->service->createVirtualCategory('ct_last_position_' . uniqid(), new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get());
            $newIdRaw = $result['id'] ?? null;
            self::assertTrue(is_numeric($newIdRaw));
            $newId = (int) $newIdRaw;

            $rank = $this->conn->createQueryBuilder()
                ->select($this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank'))
                ->from(Tables::categories())
                ->where('id = ' . $newId)
                ->executeQuery()
                ->fetchOne();
            // category 1 is the only other root-level album, at rank 1 --
            // newcatDefaultPosition=last must place the new sibling right
            // after it rather than at the default rank 0.
            self::assertSame(2, is_numeric($rank) ? (int) $rank : null);

            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $newId);
        } finally {
            $currentConfig->setNewcatDefaultPosition('first');
        }
    }

    public function test_set_representative_image_for_categories_updates_both_categories(): void
    {
        try {
            $this->service->setRepresentativeImageForCategories([1, 2], 3);

            $repIds = $this->conn->fetchFirstColumn(
                'SELECT representative_picture_id FROM ' . Tables::categories() . ' WHERE id IN (1, 2) ORDER BY id'
            );
            self::assertSame([3, 3], array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $repIds));
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 4 WHERE id = 2');
        }
    }

    public function test_get_image_ids_outside_categories_excludes_the_given_category(): void
    {
        // category 1 owns images 1-3, category 2 owns images 4-5 (see this
        // file's own fixture docblock) -- excluding category 1 leaves only
        // category 2's images.
        $ids = $this->service->getImageIdsOutsideCategories([1]);
        sort($ids);

        self::assertSame([4, 5], $ids);
    }
}
}
