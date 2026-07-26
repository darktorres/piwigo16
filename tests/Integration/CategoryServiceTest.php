<?php

declare(strict_types=1);

// CategoryService calls several real, stable, already-migrated free
// functions that need more bootstrap (Translator's locale-driven plural
// rules, $user/$conf-driven access checks) than this isolated integration
// test wants to depend on. Same "minimal stub to load standalone" pattern
// as CommentServiceTest/PermissionServiceTest -- is_admin()/l10n() bodies
// copied verbatim from those files (function_exists() guards mean
// whichever Integration test file's stub loads first wins for the whole
// run, so every file declaring these must keep the bodies identical).
// trigger_change() calls go directly through the real
// Piwigo\PluginConfig\EventDispatcher::get() singleton now, a pure
// passthrough with no handlers registered, so no local stub is needed for
// it anymore.
namespace {
    if (! function_exists('l10n')) {
        function l10n(string $key, mixed ...$args): string
        {
            return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
        }
    }

    if (! function_exists('l10n_dec')) {
        // Real l10n_dec() delegates to Translator::get()->plural(), a
        // locale-driven ngettext() rule -- too much bootstrap for this
        // isolated test. English-only singular/plural stand-in, same
        // simplification precedent as TagServiceTest's tag_alpha_compare().
        function l10n_dec(string $singularKey, string $pluralKey, int|string $decimal): string
        {
            $n = is_numeric($decimal) ? (int) $decimal : 0;
            $key = $n === 1 ? $singularKey : $pluralKey;

            return str_replace('%d', (string) $n, $key);
        }
    }

    // trigger_change() calls go directly through the real
    // Piwigo\PluginConfig\EventDispatcher::get() singleton now, a pure
    // passthrough with no handlers registered, so no local stub is needed.

    // is_admin() -- CategoryService now calls Piwigo\Auth\AccessControl::
    // isAdmin() directly (P23 batch 8d), which reads Piwigo\Users\CurrentUser
    // (Legacy Coupling Retirement Track A batch A3); tests below seed
    // CurrentUser instead.

    if (! function_exists('get_boolean')) {
        function get_boolean(mixed $input): bool
        {
            if (is_string($input) && strtolower($input) === 'false') {
                return false;
            }

            return (bool) $input;
        }
    }

    if (! function_exists('pwg_db_get_recent_period_expression')) {
        function pwg_db_get_recent_period_expression(int|string $period, string $date = 'CURRENT_DATE'): string
        {
            if ($date === 'CURRENT_DATE' && \Piwigo\Core\Env::testModeIsActive()) {
                $date = \Piwigo\Core\Env::now()->format('Y-m-d');
            }

            if ($date !== 'CURRENT_DATE') {
                $date = '\'' . $date . '\'';
            }

            return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

/**
 * Same fixture shape as CategoryRepositoryTest: category 1 "Sample Album"
 * (root, 3 direct images), category 2 "Nested Sub Album" (child of 1, 2
 * direct images).
 */
final class CategoryServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryService $service;

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
        $this->service = new CategoryService(
            \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
            new PermissionService(new PermissionRepository($this->conn), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class))
        );

        CurrentUser::set(User::fromUserArray([]));
        CurrentConfig::setRateEnabled(true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public'");
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
        self::assertIsArray($upperNames);
        self::assertCount(2, $upperNames);
        $first = $upperNames[0];
        $second = $upperNames[1];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('Sample Album', $first['name']);
        self::assertSame('Nested Sub Album', $second['name']);
    }

    public function test_get_category_info_coerces_true_false_string_columns_to_bool(): void
    {
        $info = $this->service->getCategoryInfo(1);

        self::assertNotNull($info);
        self::assertIsBool($info['visible']);
        self::assertTrue($info['visible']);
    }

    public function test_get_preferred_image_orders_returns_the_fixed_option_list(): void
    {
        CurrentUser::set(User::fromUserArray(['status' => 'normal']));

        $orders = $this->service->getPreferredImageOrders();

        self::assertNotEmpty($orders);
        self::assertSame('', $orders[0][1]);
        // 'Permissions' (level DESC) is only visible to admins.
        $permissionsOrder = array_values(array_filter($orders, static fn (array $o): bool => $o[1] === 'level DESC'));
        self::assertFalse($permissionsOrder[0][2]);
    }

    public function test_get_preferred_image_orders_permissions_option_visible_to_admin(): void
    {
        CurrentUser::set(User::fromUserArray(['status' => 'admin']));

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
        $text = CategoryService::getDisplayImagesCount(0, 5, 1, false, ' / ');

        self::assertStringContainsString('5', $text);
        self::assertStringContainsString('1', $text);
    }

    public function test_get_display_images_count_returns_empty_string_for_zero_images(): void
    {
        self::assertSame('', CategoryService::getDisplayImagesCount(0, 0, 0));
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
            1 => ['cat_id' => 1, 'id_uppercat' => null, 'nb_categories' => 1, 'count_images' => 5, 'count_categories' => 1],
            2 => ['cat_id' => 2, 'id_uppercat' => 1, 'nb_images' => 2, 'count_categories' => 0],
        ];

        CategoryService::removeComputedCategory($cats, $cats[2]);

        self::assertArrayNotHasKey(2, $cats);
        self::assertSame(0, $cats[1]['nb_categories']);
        self::assertSame(3, $cats[1]['count_images']);
        self::assertSame(0, $cats[1]['count_categories']);
    }

    public function test_get_image_ids_for_categories_returns_images(): void
    {
        $ids = $this->service->getImageIdsForCategories([1], 'AND', '', '', false);
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
        // malformed 'level<=' fragment with no right-hand value, a state
        // that can't happen in production.
        return [
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
        CurrentUser::set(User::fromUserArray(self::realisticUserGlobal()));

        $ids = $this->service->getImageIdsForCategories([1], 'AND', '', '', true);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_get_common_categories_with_permissions_builds_valid_sql(): void
    {
        CurrentUser::set(User::fromUserArray(self::realisticUserGlobal()));

        $common = $this->service->getCommonCategories([1, 2, 3], null, [4, 5], true);

        self::assertSame(3, $common['1']['counter']);
    }

    public function test_get_related_categories_menu_with_permissions_builds_valid_sql(): void
    {
        // getRelatedCategoriesMenu() always calls getCommonCategories()
        // with usePermissions defaulted to true internally -- this is the
        // real menubar.inc.php code path the bug above was caught on.
        CurrentUser::set(User::fromUserArray(self::realisticUserGlobal()));

        $cats = $this->service->getRelatedCategoriesMenu([1, 2, 3], []);

        self::assertNotSame([], $cats);
    }
}
}
