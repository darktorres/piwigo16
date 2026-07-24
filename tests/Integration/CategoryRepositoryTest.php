<?php

declare(strict_types=1);

// findComputedCategoriesRollup() calls the real, stable, dependency-free
// \Piwigo\Db\MysqliDb::getRecentPeriodExpression() (include/dblayer/functions_mysqli.inc.php),
// which this isolated integration test doesn't load. Copied verbatim --
// pure string building, no bootstrap needed. function_exists() guard: same
// "whichever Integration test file's stub loads first wins" reasoning as
// every other free-function stub in this suite.
namespace {
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
    use Piwigo\Category\Projection\Category;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new CategoryRepository($this->conn);
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
        $imageId = $this->repo->findRandomImageId(1, '1', false, '');

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
            $imageId = $this->repo->findRandomImageId(1, '1', true, '');
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
        self::assertNull($this->repo->findRandomImageId(1, '1', false, 'AND 1 = 0'));
    }

    public function test_find_computed_categories_rollup_returns_one_row_per_category(): void
    {
        $rows = $this->repo->findComputedCategoriesRollup(0, null, '');

        $byId = [];
        foreach ($rows as $row) {
            $catId = $row['cat_id'];
            if (is_int($catId) || is_string($catId)) {
                $byId[$catId] = $row;
            }
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
            $catId = $row['cat_id'];
            if (is_int($catId) || is_string($catId)) {
                $byId[$catId] = $row;
            }
        }

        self::assertArrayHasKey('1', $byId);
        self::assertSame(0, $byId['1']['nb_images']);
    }

    public function test_find_image_ids_for_categories_returns_images_in_category(): void
    {
        $ids = $this->repo->findImageIdsForCategories([1], 'AND', '', '', '');
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_image_ids_for_categories_returns_empty_for_no_categories(): void
    {
        self::assertSame([], $this->repo->findImageIdsForCategories([], 'AND', '', '', ''));
    }

    public function test_find_image_ids_for_categories_and_mode_requires_all_categories(): void
    {
        // no image belongs to both category 1 and 2 in the fixture, so AND
        // mode across [1, 2] returns nothing.
        $ids = $this->repo->findImageIdsForCategories([1, 2], 'AND', '', '', '');

        self::assertSame([], $ids);
    }

    public function test_find_common_categories_counts_matching_images(): void
    {
        $common = $this->repo->findCommonCategories([1, 2, 3], null, [], '');

        self::assertSame(3, $common['1']['counter']);
    }

    public function test_find_common_categories_returns_empty_for_no_items(): void
    {
        self::assertSame([], $this->repo->findCommonCategories([], null, [], ''));
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
            $catId = $row['cat_id'];
            if (is_int($catId) || is_string($catId)) {
                $byId[$catId] = $row;
            }
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

}
}
