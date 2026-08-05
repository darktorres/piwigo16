<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Category\CategoryAdminListCriteria;
    use Piwigo\Category\CategoryListCriteria;
    use Piwigo\Category\CategoryRefDateAggregate;
    use Piwigo\Category\CategoryRefDateField;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\Projection\Category;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Permission\PermissionCriteria;

/**
 * Fixture shape: category 1 "Sample Album" (root, uppercats="1",
 * global_rank="1", 3 direct images via image_category), category 2
 * "Nested Sub Album" (child of 1, uppercats="1,2", global_rank="1.1",
 * 2 direct images). old_permalinks has one row: cat_id=1,
 * permalink='old-sample-album'.
 */
final class CategoryRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryRepository $repo;

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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new CategoryRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn), $currentConfig);
    }

    private function countRows(string $table): int
    {
        $count = $this->conn->createQueryBuilder()->select('COUNT(*) AS c')->from($table)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'public'");
        $this->conn->executeStatement('UPDATE ' . Tables::oldPermalinks() . " SET hit = 42, last_hit = '2026-07-07 05:02:38'");
        $rank = $this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank');
        $this->conn->executeStatement(
            'UPDATE ' . Tables::imageCategory() . " SET {$rank} = CASE image_id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 3 END WHERE category_id = 1"
        );
        parent::tearDown();
    }

    public function test_find_by_id_returns_a_typed_category_projection(): void
    {
        $cat = $this->repo->findById(1);

        self::assertInstanceOf(Category::class, $cat);
        self::assertSame(1, $cat->id);
        self::assertSame('Sample Album', $cat->name);
    }

    public function test_find_by_id_returns_null_for_a_missing_category(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function test_find_names_by_ids_keys_by_id(): void
    {
        $names = $this->repo->findNamesByIds([1, 2]);

        self::assertSame('Sample Album', $names['1']['name']);
        self::assertSame('Nested Sub Album', $names['2']['name']);
    }

    public function test_find_names_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findNamesByIds([]));
    }

    public function test_find_subcategory_ids_matches_via_uppercats_path(): void
    {
        // category 2's uppercats is "1,2" -- it's a subcategory of 1.
        $ids = $this->repo->findSubcategoryIds([1]);
        sort($ids);

        self::assertSame([1, 2], $ids);
    }

    public function test_find_subcategory_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findSubcategoryIds([]));
    }

    public function test_find_permalink_matches_finds_old_and_current_permalinks(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET permalink = 'sample-album' WHERE id = 1");

        $matches = $this->repo->findPermalinkMatches(['old-sample-album', 'sample-album']);

        // id/is_old come back as native int under this project's mysqli
        // driver config (unlike varchar columns like 'permalink').
        self::assertSame(1, $matches['old-sample-album']['id']);
        self::assertSame(1, $matches['old-sample-album']['is_old']);
        self::assertSame(1, $matches['sample-album']['id']);
        self::assertSame(0, $matches['sample-album']['is_old']);

        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET permalink = NULL WHERE id = 1');
    }

    public function test_touch_old_permalink_hit_increments_the_counter(): void
    {
        $this->repo->touchOldPermalinkHit('old-sample-album', 1);

        $hit = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::oldPermalinks())
            ->where('permalink = :permalink')
            ->setParameter('permalink', 'old-sample-album')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(43, is_numeric($hit) ? (int) $hit : null);
    }

    public function test_find_random_image_id_returns_an_image_from_the_category(): void
    {
        $imageId = $this->repo->findRandomImageId(1, '1', false, self::noPermissionRestriction());

        self::assertContains($imageId, [1, 2, 3]);
    }

    public function test_find_random_image_id_recursive_includes_subcategory_images(): void
    {
        // recursive=true against the root also pulls in category 2's images
        // (4, 5) -- run enough draws that at least one subcategory image is
        // virtually certain to come up, proving recursion actually widens
        // the pool beyond category 1's own (1, 2, 3).
        $found = [];
        for ($i = 0; $i < 30; $i++) {
            $imageId = $this->repo->findRandomImageId(1, '1', true, self::noPermissionRestriction());
            if ($imageId !== null) {
                $found[$imageId] = true;
            }
        }

        self::assertTrue(isset($found[4]) || isset($found[5]));
        // sanity: never returns an image outside the {1,2,3,4,5} fixture set
        foreach (array_keys($found) as $id) {
            self::assertContains($id, [1, 2, 3, 4, 5]);
        }
    }

    public function test_find_random_image_id_returns_null_when_permission_condition_excludes_everything(): void
    {
        self::assertNull($this->repo->findRandomImageId(1, '1', false, new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_find_random_image_id_in_category_returns_an_image_from_the_category(): void
    {
        // Real regression coverage for the custom RandFunction DQL
        // function's own `ORDER BY RAND()` -- runs enough draws to make it
        // very unlikely a single lucky pick masks a query that's actually
        // broken (e.g. always returning the same row, or the wrong pool).
        for ($i = 0; $i < 10; $i++) {
            self::assertContains($this->repo->findRandomImageIdInCategory(1), [1, 2, 3]);
        }
    }

    public function test_find_random_image_id_in_category_returns_null_for_a_category_without_images(): void
    {
        self::assertNull($this->repo->findRandomImageIdInCategory(999));
    }

    public function test_find_computed_categories_rollup_returns_one_row_per_category(): void
    {
        $rows = $this->repo->findComputedCategoriesRollup(0, null, '');

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['cat_id']] = $row;
        }

        // nb_images is a COUNT() aggregate -- comes back as native int under
        // this project's mysqli driver config.
        self::assertSame(3, $byId['1']['nb_images']);
        self::assertSame(2, $byId['2']['nb_images']);
    }

    public function test_find_computed_categories_rollup_excludes_forbidden_categories(): void
    {
        $rows = $this->repo->findComputedCategoriesRollup(0, null, '2');

        $ids = array_column($rows, 'cat_id');
        self::assertNotContains(2, $ids);
        self::assertContains(1, $ids);
    }

    public function test_find_computed_categories_rollup_keeps_category_row_when_no_images_in_recent_period(): void
    {
        // filterDays=0 with a fixture whose images predate "now" means no
        // image matches the join condition -- the category row must still
        // appear (nb_images=0), proving the date filter lives in the JOIN's
        // ON clause and not a WHERE (see the repository's own docblock).
        $rows = $this->repo->findComputedCategoriesRollup(0, 0, '');

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['cat_id']] = $row;
        }

        self::assertArrayHasKey('1', $byId);
        self::assertSame(0, $byId['1']['nb_images']);
    }

    public function test_find_image_ids_for_categories_returns_images_in_category(): void
    {
        $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_image_ids_for_categories_returns_empty_for_no_categories(): void
    {
        self::assertSame([], $this->repo->findImageIdsForCategories([], 'AND', self::noPermissionRestriction()));
    }

    public function test_find_image_ids_for_categories_and_mode_requires_all_categories(): void
    {
        // no image belongs to both category 1 and 2 in the fixture, so AND
        // mode across [1, 2] returns nothing.
        $ids = $this->repo->findImageIdsForCategories([1, 2], 'AND', self::noPermissionRestriction());

        self::assertSame([], $ids);
    }

    public function test_find_image_ids_for_categories_orders_by_current_config_order_by(): void
    {
        // Item 16J: the real DQL path -- a single bounded-vocabulary field,
        // not sorted before comparing, to prove the parsed order is what
        // actually reaches the query rather than incidental id order.
        CurrentConfig::current()->setOrderBy('ORDER BY id DESC');

        $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());

        self::assertSame([3, 2, 1], $ids);
    }

    public function test_find_image_ids_for_categories_orders_by_rank(): void
    {
        // `rank` lives on the image_category join row, not on images
        // itself -- deliberately set to the reverse of id order so a stale
        // fallback to id-order would fail this assertion.
        $rank = $this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank');
        $this->conn->executeStatement(
            'UPDATE ' . Tables::imageCategory() . " SET {$rank} = CASE image_id WHEN 1 THEN 3 WHEN 2 THEN 2 WHEN 3 THEN 1 END WHERE category_id = 1"
        );
        // No quoting needed here -- PhotoSortField::parseOrderByFragment()'s
        // own regex already treats a surrounding backtick as optional, and
        // this raw config string never round-trips through column()'s own
        // (now driver-aware) quoted output, so a bare, unquoted field name
        // parses identically on both platforms.
        CurrentConfig::current()->setOrderBy('ORDER BY rank ASC');

        $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());

        self::assertSame([3, 2, 1], $ids);
    }

    public function test_find_image_ids_for_categories_falls_back_to_raw_dbal_when_order_by_custom_is_set(): void
    {
        // A sysadmin-local-config override -- RequestBootstrap.php's own
        // real bootstrap-time behavior copies its value into orderBy() too;
        // mirrored here since this test bypasses that bootstrap step.
        CurrentConfig::current()->setOrderByCustom('ORDER BY RAND()');
        CurrentConfig::current()->setOrderBy('ORDER BY RAND()');

        $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_image_ids_for_categories_falls_back_to_raw_dbal_for_unparseable_order_by(): void
    {
        // Not one of $sort_fields's own bounded tokens -- the parser must
        // reject it and this must still return the right members via the
        // original raw-DBAL path, not throw.
        CurrentConfig::current()->setOrderBy('ORDER BY comment ASC');

        $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_common_categories_counts_matching_images(): void
    {
        $common = $this->repo->findCommonCategories([1, 2, 3], null, [], self::noPermissionRestriction());

        self::assertSame(3, $common['1']['counter']);
    }

    public function test_find_common_categories_returns_empty_for_no_items(): void
    {
        self::assertSame([], $this->repo->findCommonCategories([], null, [], self::noPermissionRestriction()));
    }

    public function test_find_categories_by_ids_returns_matching_rows(): void
    {
        $cats = $this->repo->findCategoriesByIds([1, 2]);

        $names = array_column($cats, 'name');
        sort($names);
        self::assertSame(['Nested Sub Album', 'Sample Album'], $names);
    }

    public function test_find_computed_categories_rollup_includes_rank(): void
    {
        // P23 batch 4b: CategoryCatsRenderer needs `rank` (sibling order)
        // for CategoryService::compareByRank(), distinct from `global_rank`
        // -- added to this rollup purely additively.
        $rows = $this->repo->findComputedCategoriesRollup(0, null, '');

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['cat_id']] = $row;
        }

        self::assertArrayHasKey('rank', $byId['1']);
        self::assertArrayHasKey('rank', $byId['2']);
    }

    public function test_find_full_categories_by_ids_returns_every_column(): void
    {
        $cats = $this->repo->findFullCategoriesByIds([1]);

        self::assertCount(1, $cats);
        $cat = $cats[0];
        self::assertInstanceOf(Category::class, $cat);
        self::assertSame('Sample Album', $cat->name);
        // uppercats/rank/representativePictureId are real `categories`
        // columns findCategoriesByIds()'s own narrower 6-column contract
        // doesn't expose -- confirms this is genuinely `SELECT *`, not a
        // duplicate of that method.
        self::assertSame('1', $cat->uppercats);
        self::assertSame(1, $cat->rank);
        self::assertSame(1, $cat->representativePictureId);
        self::assertNull($cat->comment);
    }

    public function test_find_full_categories_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findFullCategoriesByIds([]));
    }

    public function test_find_permalink_matches_returns_empty_for_no_permalinks(): void
    {
        self::assertSame([], $this->repo->findPermalinkMatches([]));
    }

    public function test_find_categories_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findCategoriesByIds([]));
    }

    public function test_find_storage_linked_image_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findStorageLinkedImageIds([]));
    }

    public function test_find_storage_linked_image_ids_returns_matching_images(): void
    {
        // No fixture image has a real storage_category_id (filesystem-sync
        // linkage, distinct from image_category membership) -- give image 1
        // one temporarily.
        try {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = 1 WHERE id = 1');

            self::assertSame([1], $this->repo->findStorageLinkedImageIds([1]));
            self::assertSame([], $this->repo->findStorageLinkedImageIds([2]));
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = NULL WHERE id = 1');
        }
    }

    public function test_find_distinct_storage_category_ids_returns_the_real_distinct_set(): void
    {
        try {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = 1 WHERE id IN (1, 2)');

            self::assertSame([1], $this->repo->findDistinctStorageCategoryIds());
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = NULL WHERE id IN (1, 2)');
        }
    }

    public function test_update_image_paths_for_category_rewrites_the_path_from_the_new_fulldir(): void
    {
        try {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = 1 WHERE id = 1');

            $this->repo->updateImagePathsForCategory(1, 'galleries/renamed-album');

            $path = $this->conn->fetchOne('SELECT path FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertSame('galleries/renamed-album/fixture-photo-1.jpg', $path);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::images() . " SET storage_category_id = NULL, path = 'upload/2026/08/01/20260801000000-2e7ed018.jpg' WHERE id = 1");
        }
    }

    public function test_find_distinct_linked_image_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findDistinctLinkedImageIds([]));
    }

    public function test_find_non_orphan_image_ids_returns_empty_for_no_image_ids(): void
    {
        self::assertSame([], $this->repo->findNonOrphanImageIds([], [2]));
    }

    public function test_find_non_orphan_image_ids_keeps_only_images_still_linked_outside_the_excluded_categories(): void
    {
        // Images 1-3 are (also) directly linked to category 1, which is
        // NOT excluded here -- they survive. Images 4-5 are linked ONLY to
        // category 2, the excluded one -- they don't survive, proving this
        // is a real "still has another home" diff, not just "was in the
        // requested list".
        $ids = $this->repo->findNonOrphanImageIds([1, 2, 3, 4, 5], [2]);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_delete_user_access_for_categories_is_a_no_op_for_no_ids(): void
    {
        $before = $this->countRows(Tables::userAccess());

        $this->repo->deleteUserAccessForCategories([]);

        $after = $this->countRows(Tables::userAccess());
        self::assertSame($before, $after);
    }

    public function test_delete_group_access_for_categories_is_a_no_op_for_no_ids(): void
    {
        $before = $this->countRows(Tables::groupAccess());

        $this->repo->deleteGroupAccessForCategories([]);

        $after = $this->countRows(Tables::groupAccess());
        self::assertSame($before, $after);
    }

    public function test_delete_user_access_for_users_and_categories_is_a_no_op_for_no_user_ids(): void
    {
        $before = $this->countRows(Tables::userAccess());

        $this->repo->deleteUserAccessForUsersAndCategories([], [1]);

        $after = $this->countRows(Tables::userAccess());
        self::assertSame($before, $after);
    }

    public function test_delete_group_access_for_groups_and_categories_is_a_no_op_for_no_group_ids(): void
    {
        $before = $this->countRows(Tables::groupAccess());

        $this->repo->deleteGroupAccessForGroupsAndCategories([], [1]);

        $after = $this->countRows(Tables::groupAccess());
        self::assertSame($before, $after);
    }

    public function test_delete_categories_by_ids_is_a_no_op_for_no_ids(): void
    {
        $this->repo->deleteCategoriesByIds([]);

        self::assertNotNull($this->repo->findById(1));
    }

    public function test_delete_old_permalinks_for_categories_is_a_no_op_for_no_ids(): void
    {
        $this->repo->deleteOldPermalinksForCategories([]);

        $count = $this->countRows(Tables::oldPermalinks());
        self::assertSame(1, $count);
    }

    public function test_clear_representative_picture_ids_is_a_no_op_for_no_ids(): void
    {
        $this->repo->clearRepresentativePictureIds([]);

        $cat = $this->repo->findById(1);
        self::assertNotNull($cat);
    }

    public function test_update_category_visibility_is_a_no_op_for_no_ids(): void
    {
        $this->repo->updateCategoryVisibility([], false);

        // visible is a genuine boolean column -- DBAL's raw (unmapped)
        // fetchOne() returns a native PHP bool for it on Postgres, but a
        // numeric 1/0 on MySQL (confirmed live). (int) (bool) normalizes
        // either representation to the same value, unlike the old
        // is_numeric()-gated cast, which silently produced null for a
        // real Postgres bool.
        $visible = $this->conn->createQueryBuilder()->select('visible')->from(Tables::categories())->where('id = 1')->executeQuery()->fetchOne();
        self::assertSame(1, (int) (bool) $visible);
    }

    public function test_update_category_commentable_is_a_no_op_for_no_ids(): void
    {
        $this->repo->updateCategoryCommentable([], false);

        $commentable = $this->conn->createQueryBuilder()->select('commentable')->from(Tables::categories())->where('id = 1')->executeQuery()->fetchOne();
        self::assertSame(1, (int) (bool) $commentable);
    }

    public function test_update_category_status_is_a_no_op_for_no_ids(): void
    {
        $this->repo->updateCategoryStatus([], 'private');

        self::assertSame('public', $this->repo->findCategoryStatus(1));
    }

    public function test_update_image_order_is_a_no_op_for_a_missing_category(): void
    {
        // 999999 doesn't exist -- find() returns null and the method
        // returns early instead of dereferencing a null entity.
        $this->repo->updateImageOrder(999999, 'name ASC');

        self::assertNull($this->repo->findById(999999));
    }

    public function test_find_status_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findStatusByIds([]));
    }

    public function test_find_uppercats_columns_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findUppercatsColumns([]));
    }

    public function test_find_uppercats_by_id_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findUppercatsById([]));
    }

    public function test_find_ref_dates_by_category_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findRefDatesByCategoryIds([], CategoryRefDateField::DateCreation, CategoryRefDateAggregate::Min));
    }

    public function test_find_uppercat_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findUppercatIds([]));
    }

    public function test_find_categories_for_fulldirs_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findCategoriesForFulldirs([]));
    }

    public function test_update_category_parent_is_a_no_op_for_no_ids(): void
    {
        $this->repo->updateCategoryParent([], 'NULL');

        $cat = $this->repo->findById(2);
        self::assertNotNull($cat);
        $idUppercat = $this->conn->createQueryBuilder()->select('id_uppercat')->from(Tables::categories())->where('id = 2')->executeQuery()->fetchOne();
        self::assertSame(1, is_numeric($idUppercat) ? (int) $idUppercat : null);
    }

    public function test_find_max_rank_for_parent_returns_the_highest_rank_among_root_categories(): void
    {
        self::assertSame(1, $this->repo->findMaxRankForParent(null));
    }

    public function test_find_max_rank_for_parent_returns_the_highest_rank_among_a_specific_parents_children(): void
    {
        self::assertSame(1, $this->repo->findMaxRankForParent(1));
    }

    public function test_find_max_rank_for_parent_returns_null_when_the_parent_has_no_children(): void
    {
        self::assertNull($this->repo->findMaxRankForParent(2));
    }

    public function test_find_random_representative_id_among_subcategories_finds_a_real_match(): void
    {
        // category 2 ("1,2") is the only row LIKE '1,%', and its fixture
        // representative_picture_id is 4.
        self::assertSame('4', $this->repo->findRandomRepresentativeIdAmongSubcategories('1', self::noPermissionRestriction()));
    }

    public function test_find_random_representative_id_among_subcategories_returns_null_when_no_subcategory_matches(): void
    {
        // category 2 has no sub-categories of its own.
        self::assertNull($this->repo->findRandomRepresentativeIdAmongSubcategories('2', self::noPermissionRestriction()));
    }

    public function test_find_random_representative_id_among_subcategories_returns_null_when_the_permission_condition_excludes_everything(): void
    {
        self::assertNull($this->repo->findRandomRepresentativeIdAmongSubcategories('1', new PermissionCriteria(null, [999_999], null, null, null, null)));
    }

    public function test_find_date_range_by_category_returns_empty_for_no_category_ids(): void
    {
        self::assertSame([], $this->repo->findDateRangeByCategory([], self::noPermissionRestriction()));
    }

    public function test_find_date_range_by_category_returns_the_min_and_max_creation_date(): void
    {
        // Only image 2's date_creation is set (the other 2 direct images
        // of category 1 stay NULL) -- MIN/MAX both resolve to that single
        // real value, proving the aggregate reads real data rather than
        // just echoing back a NULL.
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2019-06-15 10:00:00' WHERE id = 2");

        try {
            $range = $this->repo->findDateRangeByCategory([1], self::noPermissionRestriction());
            self::assertCount(1, $range);
            $entry = array_values($range)[0];

            self::assertSame('2019-06-15 10:00:00', $entry['from']);
            self::assertSame('2019-06-15 10:00:00', $entry['to']);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id = 2');
        }
    }

    public function test_find_id_name_permalink_by_id_returns_null_for_a_missing_category(): void
    {
        self::assertNull($this->repo->findIdNamePermalinkById(999999));
    }

    public function test_find_image_ids_outside_categories_returns_ids_linked_to_other_categories(): void
    {
        // Excluding category 2 leaves only category 1's own image_category
        // rows (images 1, 2, 3) -- category 2's images (4, 5) are dropped,
        // proving the NOT IN condition actually reaches the query.
        $ids = $this->repo->findImageIdsOutsideCategories([2]);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_mass_insert_categories_is_a_no_op_for_no_inserts(): void
    {
        $before = $this->countRows(Tables::categories());

        $this->repo->massInsertCategories(['name'], []);

        $after = $this->countRows(Tables::categories());
        self::assertSame($before, $after);
    }

    public function test_update_fields_is_a_no_op_for_no_data(): void
    {
        $this->repo->updateFields(1, []);

        $cat = $this->repo->findById(1);
        self::assertNotNull($cat);
        self::assertSame('Sample Album', $cat->name);
    }

    public function test_set_representative_image_for_categories_is_a_no_op_for_no_ids(): void
    {
        $this->repo->setRepresentativeImageForCategories([], 5);

        $repId = $this->conn->createQueryBuilder()
            ->select('representative_picture_id')
            ->from(Tables::categories())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, is_numeric($repId) ? (int) $repId : null);
    }

    public function test_set_representative_image_for_categories_updates_every_listed_category(): void
    {
        try {
            $this->repo->setRepresentativeImageForCategories([1, 2], 3);

            $repId1 = $this->conn->createQueryBuilder()->select('representative_picture_id')->from(Tables::categories())->where('id = 1')->executeQuery()->fetchOne();
            $repId2 = $this->conn->createQueryBuilder()->select('representative_picture_id')->from(Tables::categories())->where('id = 2')->executeQuery()->fetchOne();

            self::assertSame(3, is_numeric($repId1) ? (int) $repId1 : null);
            self::assertSame(3, is_numeric($repId2) ? (int) $repId2 : null);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 4 WHERE id = 2');
        }
    }

    public function test_find_dirs_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findDirsByIds([]));
    }

    public function test_find_existing_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findExistingIds([]));
    }

    public function test_find_subcategory_counts_by_parent_returns_empty_for_no_parent_ids(): void
    {
        self::assertSame([], $this->repo->findSubcategoryCountsByParent([]));
    }

    public function test_find_rank_info_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findRankInfoByIds([]));
    }

    public function test_find_move_details_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findMoveDetailsByIds([]));
    }

    public function test_count_by_visible_counts_only_matching_rows(): void
    {
        // Both fixture categories have visible=1 -- proves the bound
        // :visible parameter actually discriminates (a broken bind that
        // always matched, or never matched, would pass a same-count-for-
        // both regression test but not this one).
        self::assertSame(2, $this->repo->countByVisible(true));
        self::assertSame(0, $this->repo->countByVisible(false));
    }

    public function test_find_by_commentable_filters_on_the_commentable_flag(): void
    {
        // Both fixture categories have commentable=1.
        $rows = $this->repo->findByCommentable(true);
        self::assertSame([1, 2], array_column($rows, 'id'));

        self::assertSame([], $this->repo->findByCommentable(false));
    }

    public function test_find_by_visible_filters_on_the_visible_flag(): void
    {
        // Both fixture categories have visible=1.
        $rows = $this->repo->findByVisible(true);
        self::assertSame([1, 2], array_column($rows, 'id'));

        self::assertSame([], $this->repo->findByVisible(false));
    }

    public function test_find_by_status_filters_on_the_status_column(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 2");

        $publicRows = $this->repo->findByStatus('public');
        $privateRows = $this->repo->findByStatus('private');

        self::assertSame([1], array_column($publicRows, 'id'));
        self::assertSame([2], array_column($privateRows, 'id'));
    }

    public function test_find_by_representative_presence_true_returns_categories_with_a_representative(): void
    {
        // Both fixture categories already have representative_picture_id set.
        $rows = $this->repo->findByRepresentativePresence(true);
        self::assertSame([1, 2], array_column($rows, 'id'));

        try {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = NULL WHERE id IN (1, 2)');
            self::assertSame([], $this->repo->findByRepresentativePresence(true));
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 4 WHERE id = 2');
        }
    }

    public function test_find_by_representative_presence_false_joins_image_category_and_deduplicates(): void
    {
        try {
            // Category 1 has no representative but 3 direct images (image_category
            // rows 1,2,3) -- the DISTINCT is what's actually under test here: a
            // missing distinct() would return category 1 three times (once per
            // matching image_category row).
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = NULL WHERE id = 1');

            $rows = $this->repo->findByRepresentativePresence(false);

            self::assertSame([1], array_column($rows, 'id'));
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET representative_picture_id = 1 WHERE id = 1');
        }
    }

    public function test_find_private_categories_granted_to_user_excludes_group_authorized_ids(): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (?, ?)',
                [3, 1]
            );
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 1");

            $rows = $this->repo->findPrivateCategoriesGrantedToUser(3);
            self::assertSame([1], array_column($rows, 'id'));

            // Same user_access grant, but category 1 is already covered via a
            // group membership -- must be excluded from this result.
            self::assertSame([], $this->repo->findPrivateCategoriesGrantedToUser(3, ['1']));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::userAccess() . ' WHERE user_id = 3 AND cat_id = 1');
        }
    }

    public function test_find_private_categories_granted_to_group_matches_fixture_grants(): void
    {
        // Fixture group_access: (group_id=1, cat_id=1), (group_id=2, cat_id=1),
        // (group_id=3, cat_id=1), (group_id=1, cat_id=2).
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private'");

        self::assertSame([1, 2], array_column($this->repo->findPrivateCategoriesGrantedToGroup(1), 'id'));
        self::assertSame([1], array_column($this->repo->findPrivateCategoriesGrantedToGroup(2), 'id'));
        self::assertSame([], array_column($this->repo->findPrivateCategoriesGrantedToGroup(999999), 'id'));
    }

    public function test_find_private_category_ids_granted_to_group_matches_fixture_grants(): void
    {
        // Item 14 DQL audit regression test: this method's JOIN...WITH
        // condition (ga.catId = c.id) compares GroupAccessEntity's own
        // custom-CategoryId-typed field against CategoryEntity's plain int
        // id -- confirming here that it still resolves correctly against a
        // real DB (DQL JOIN...WITH conditions compile to a plain SQL column
        // comparison regardless of PHP-side Type differences).
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private'");

        self::assertSame([1, 2], $this->repo->findPrivateCategoryIdsGrantedToGroup(1));
        self::assertSame([1], $this->repo->findPrivateCategoryIdsGrantedToGroup(2));
        self::assertSame([], $this->repo->findPrivateCategoryIdsGrantedToGroup(999999));
    }

    public function test_find_categories_authorized_via_groups_for_user_deduplicates_across_memberships(): void
    {
        // Fixture user_group: user 3 belongs to groups 1 and 2. group_access:
        // group 1 grants cat 1 and cat 2, group 2 grants cat 1 -- cat 1 is
        // reachable via both memberships, so this also confirms the
        // ->distinct() actually dedupes.
        $ids = array_column($this->repo->findCategoriesAuthorizedViaGroupsForUser(3), 'cat_id');
        sort($ids);

        self::assertSame([1, 2], $ids);
    }

    public function test_find_categories_authorized_via_groups_for_user_returns_empty_for_a_groupless_user(): void
    {
        self::assertSame([], $this->repo->findCategoriesAuthorizedViaGroupsForUser(2));
    }

    public function test_find_private_categories_excluding_filters_out_the_given_ids(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private'");

        self::assertSame([1, 2], array_column($this->repo->findPrivateCategoriesExcluding([]), 'id'));
        self::assertSame([2], array_column($this->repo->findPrivateCategoriesExcluding(['1']), 'id'));
        self::assertSame([], array_column($this->repo->findPrivateCategoriesExcluding(['1', '2']), 'id'));
    }

    public function test_find_id_name_uppercats_rank_applies_the_given_condition(): void
    {
        $matchAll = $this->repo->findIdNameUppercatsRank(self::noPermissionRestriction());
        self::assertSame([1, 2], array_column($matchAll, 'id'));

        self::assertSame([], $this->repo->findIdNameUppercatsRank(new PermissionCriteria(null, [999_999], null, null, null, null)));
    }

    public function test_find_all_for_permalinks_display_computes_a_permalink_aware_name(): void
    {
        $rows = $this->repo->findAllForPermalinksDisplay();

        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            self::assertTrue(is_int($id) || is_string($id));
            $byId[$id] = $row;
        }

        // Both fixture categories have permalink NULL, so the CONCAT's IF()
        // branch appends nothing (no trailing checkmark).
        self::assertSame('1 - Sample Album', $byId[1]['name']);
        self::assertSame('2 - Nested Sub Album', $byId[2]['name']);
        self::assertNull($byId[1]['permalink']);
    }

    public function test_find_all_for_permalinks_display_appends_a_checkmark_when_permalink_is_set(): void
    {
        try {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET permalink = 'sample-album' WHERE id = 1");

            $rows = $this->repo->findAllForPermalinksDisplay();

            $byId = array_column($rows, null, 'id');
            self::assertSame('1 - Sample Album &radic;', $byId[1]['name']);
            self::assertSame('sample-album', $byId[1]['permalink']);
            self::assertSame('2 - Nested Sub Album', $byId[2]['name']);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET permalink = NULL WHERE id = 1');
        }
    }

    public function test_find_id_name_uppercats_rank_by_site_filters_on_site_id(): void
    {
        // Both fixture categories have site_id NULL -- never matches a real id.
        self::assertSame([], $this->repo->findIdNameUppercatsRankBySite(1));

        try {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET site_id = 1 WHERE id = 1');

            self::assertSame([1], array_column($this->repo->findIdNameUppercatsRankBySite(1), 'id'));
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET site_id = NULL WHERE id = 1');
        }
    }

    public function test_find_list_for_ws_scopes_to_root_categories_when_recursive_is_false_and_cat_id_is_null(): void
    {
        $criteria = new CategoryListCriteria(catId: null, recursive: false, forbiddenCategoryIds: [], publicOnly: false);

        $result = $this->repo->findListForWs($criteria, null, 10, null, false);

        self::assertSame([1], array_column($result->rows, 'id'));
    }

    public function test_find_list_for_ws_matches_a_category_and_its_direct_children_when_recursive_is_false_and_cat_id_is_set(): void
    {
        $criteria = new CategoryListCriteria(catId: 1, recursive: false, forbiddenCategoryIds: [], publicOnly: false);

        $result = $this->repo->findListForWs($criteria, null, 10, null, false);

        $ids = array_column($result->rows, 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function test_find_list_for_ws_matches_the_full_subtree_when_recursive(): void
    {
        $criteria = new CategoryListCriteria(catId: 1, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

        $result = $this->repo->findListForWs($criteria, null, 10, null, false);

        $ids = array_column($result->rows, 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function test_find_list_for_ws_excludes_forbidden_category_ids(): void
    {
        $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [2], publicOnly: false);

        $result = $this->repo->findListForWs($criteria, null, 10, null, false);

        self::assertSame([1], array_column($result->rows, 'id'));
    }

    public function test_find_list_for_ws_public_only_excludes_non_public_categories(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . " SET status = 'private' WHERE id = 2");

        $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: true);

        $result = $this->repo->findListForWs($criteria, null, 10, null, false);

        self::assertSame([1], array_column($result->rows, 'id'));
    }

    public function test_find_list_for_ws_applies_the_search_term(): void
    {
        $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

        $result = $this->repo->findListForWs($criteria, 'Nested', 10, null, false);

        self::assertSame([2], array_column($result->rows, 'id'));
    }

    public function test_find_list_for_ws_applies_the_limit_and_reports_the_total(): void
    {
        $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

        $result = $this->repo->findListForWs($criteria, null, 10, 1, false);

        self::assertCount(1, $result->rows);
        self::assertSame(2, $result->total);
    }

    public function test_find_admin_list_for_ws_scopes_to_root_categories_when_recursive_is_false_and_cat_id_is_null(): void
    {
        $criteria = new CategoryAdminListCriteria(catId: null, recursive: false);

        $result = $this->repo->findAdminListForWs($criteria, null, 10);

        self::assertSame([1], array_column($result->rows, 'id'));
        self::assertSame(1, $result->total);
    }

    public function test_find_admin_list_for_ws_matches_the_full_subtree_when_recursive(): void
    {
        $criteria = new CategoryAdminListCriteria(catId: 1, recursive: true);

        $result = $this->repo->findAdminListForWs($criteria, null, 10);

        $ids = array_column($result->rows, 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function test_find_admin_list_for_ws_applies_the_search_term(): void
    {
        $criteria = new CategoryAdminListCriteria(catId: null, recursive: true);

        $result = $this->repo->findAdminListForWs($criteria, 'Nested', 10);

        self::assertSame([2], array_column($result->rows, 'id'));
    }

    public function test_find_next_id_returns_one_more_than_the_current_max(): void
    {
        // Item 14 DQL audit: findNextId() converted its original
        // IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) to COALESCE(MAX(id)+1, 1) --
        // mathematically identical for this 2-argument case, verified here
        // against the real, non-empty fixture table (the empty-table branch
        // isn't practically testable against this shared fixture DB).
        $maxId = $this->conn->fetchOne('SELECT MAX(id) FROM ' . Tables::categories());

        self::assertSame((is_numeric($maxId) ? (int) $maxId : 0) + 1, $this->repo->findNextId());
    }

    public function test_find_photo_counts_by_category_counts_direct_images(): void
    {
        // Item 14 Sub-phase B2 re-audit: had zero existing coverage --
        // combines a custom-Typed categoryId GROUP BY with a blind
        // instanceof-then-continue fallback (silently drops the row
        // instead of throwing if the VO-hydration assumption is wrong).
        self::assertSame(
            [1 => 3, 2 => 2],
            $this->repo->findPhotoCountsByCategory()
        );
    }

    public function test_has_images_is_true_for_a_category_with_images(): void
    {
        self::assertTrue($this->repo->hasImages(1));
    }

    public function test_has_images_is_false_for_a_category_without_images(): void
    {
        self::assertFalse($this->repo->hasImages(999));
    }

    public function test_find_photo_count_and_date_range_matches_the_fixture(): void
    {
        // Category 1 has 3 direct images, all sharing the same
        // date_available (2026-08-01 00:00:00 -- see the fixture-shape
        // docblock at the top of this file / test_find_add_method_
        // breakdown_groups_by_storage_category_presence's own comment
        // elsewhere), so min and max both resolve to that same date.
        $row = $this->repo->findPhotoCountAndDateRange(1);

        self::assertNotFalse($row);
        self::assertSame(3, $row[0]);
        self::assertSame('2026-08-01', $row[1]);
        self::assertSame('2026-08-01', $row[2]);
    }

    public function test_find_photo_count_and_date_range_is_zero_for_a_category_without_images(): void
    {
        $row = $this->repo->findPhotoCountAndDateRange(999);

        self::assertNotFalse($row);
        self::assertSame(0, $row[0]);
        self::assertNull($row[1]);
        self::assertNull($row[2]);
    }

    public function test_find_distinct_image_ids_in_categories_returns_the_linked_images(): void
    {
        $ids = $this->repo->findDistinctImageIdsInCategories([1]);
        sort($ids);
        self::assertSame([1, 2, 3], $ids);
    }

    public function test_delete_image_category_links_for_categories_removes_only_the_given_categories(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->repo->deleteImageCategoryLinksForCategories([1]);

            $remaining = $this->conn->createQueryBuilder()
                ->select('DISTINCT category_id')
                ->from(Tables::imageCategory())
                ->executeQuery()
                ->fetchFirstColumn();

            self::assertSame([2], array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $remaining));
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * A {@see PermissionCriteria} with every dimension null -- "no
     * restriction on anything," the direct replacement for the old
     * `new SqlCondition('')` sentinel.
     */
    private static function noPermissionRestriction(): PermissionCriteria
    {
        return new PermissionCriteria(null, null, null, null, null, null);
    }
}
}
