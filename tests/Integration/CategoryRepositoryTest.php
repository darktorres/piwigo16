<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\Projection\Category;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Permission\SqlCondition;

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
        $this->repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class);
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
        $imageId = $this->repo->findRandomImageId(1, '1', false, new SqlCondition(''));

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
            $imageId = $this->repo->findRandomImageId(1, '1', true, new SqlCondition(''));
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
        self::assertNull($this->repo->findRandomImageId(1, '1', false, new SqlCondition('1 = 0')));
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
        $ids = $this->repo->findImageIdsForCategories([1], 'AND', '', '', new SqlCondition(''));
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_image_ids_for_categories_returns_empty_for_no_categories(): void
    {
        self::assertSame([], $this->repo->findImageIdsForCategories([], 'AND', '', '', new SqlCondition('')));
    }

    public function test_find_image_ids_for_categories_and_mode_requires_all_categories(): void
    {
        // no image belongs to both category 1 and 2 in the fixture, so AND
        // mode across [1, 2] returns nothing.
        $ids = $this->repo->findImageIdsForCategories([1, 2], 'AND', '', '', new SqlCondition(''));

        self::assertSame([], $ids);
    }

    public function test_find_common_categories_counts_matching_images(): void
    {
        $common = $this->repo->findCommonCategories([1, 2, 3], null, [], new SqlCondition(''));

        self::assertSame(3, $common['1']['counter']);
    }

    public function test_find_common_categories_returns_empty_for_no_items(): void
    {
        self::assertSame([], $this->repo->findCommonCategories([], null, [], new SqlCondition('')));
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

    public function test_find_image_ids_for_categories_applies_a_non_empty_extra_where_fragment(): void
    {
        // Without the fragment, category 1's own 3 images (1, 2, 3) all
        // qualify -- excluding image 1 by id proves $extraImagesWhereSql
        // (aliased 'i', matching the query's own images-table alias)
        // actually reaches the query rather than being silently dropped.
        $ids = $this->repo->findImageIdsForCategories([1], 'AND', 'i.id != 1', '', new SqlCondition(''));
        sort($ids);

        self::assertSame([2, 3], $ids);
    }

    public function test_find_categories_by_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findCategoriesByIds([]));
    }

    public function test_find_storage_linked_image_ids_returns_empty_for_no_ids(): void
    {
        self::assertSame([], $this->repo->findStorageLinkedImageIds([]));
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

        $visible = $this->conn->createQueryBuilder()->select('visible')->from(Tables::categories())->where('id = 1')->executeQuery()->fetchOne();
        self::assertSame(1, is_numeric($visible) ? (int) $visible : null);
    }

    public function test_update_category_commentable_is_a_no_op_for_no_ids(): void
    {
        $this->repo->updateCategoryCommentable([], false);

        $commentable = $this->conn->createQueryBuilder()->select('commentable')->from(Tables::categories())->where('id = 1')->executeQuery()->fetchOne();
        self::assertSame(1, is_numeric($commentable) ? (int) $commentable : null);
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
        self::assertSame([], $this->repo->findRefDatesByCategoryIds([], 'date_creation', 'MIN'));
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
        self::assertSame('4', $this->repo->findRandomRepresentativeIdAmongSubcategories('1', new SqlCondition('')));
    }

    public function test_find_random_representative_id_among_subcategories_returns_null_when_no_subcategory_matches(): void
    {
        // category 2 has no sub-categories of its own.
        self::assertNull($this->repo->findRandomRepresentativeIdAmongSubcategories('2', new SqlCondition('')));
    }

    public function test_find_random_representative_id_among_subcategories_returns_null_when_the_permission_condition_excludes_everything(): void
    {
        self::assertNull($this->repo->findRandomRepresentativeIdAmongSubcategories('1', new SqlCondition('1 = 0')));
    }

    public function test_find_date_range_by_category_returns_empty_for_no_category_ids(): void
    {
        self::assertSame([], $this->repo->findDateRangeByCategory([], new SqlCondition('')));
    }

    public function test_find_date_range_by_category_returns_the_min_and_max_creation_date(): void
    {
        // Only image 2's date_creation is set (the other 2 direct images
        // of category 1 stay NULL) -- MIN/MAX both resolve to that single
        // real value, proving the aggregate reads real data rather than
        // just echoing back a NULL.
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2019-06-15 10:00:00' WHERE id = 2");

        try {
            $range = $this->repo->findDateRangeByCategory([1], new SqlCondition(''));
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

    public function test_find_galleries_url_for_category_returns_null_when_the_category_has_no_linked_site(): void
    {
        // Both fixture categories have site_id NULL -- the s.id = c.site_id
        // join predicate is never satisfied against a NULL, so the query
        // returns no row and this exercises the false/null branch. The
        // joined real-value branch isn't among the flagged gap lines, so it
        // isn't exercised here.
        self::assertNull($this->repo->findGalleriesUrlForCategory(1));
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

}
}
