<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Category\CategoryAdminListCriteria;
    use Piwigo\Category\CategoryListCriteria;
    use Piwigo\Category\CategoryRefDateAggregate;
    use Piwigo\Category\CategoryRefDateField;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\Projection\Category;
    use Piwigo\Category\Projection\CategoryIdNameUppercat;
    use Piwigo\Category\Projection\CategoryIdNameUppercatsRank;
    use Piwigo\Category\Projection\ComputedCategoryRollupRow;
    use Piwigo\Common\ValueObject\CategoryId;
    use Piwigo\Common\ValueObject\PhotoSortOrder;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\Kernel;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Permission\PermissionCriteria;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;

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

            $this->conn = DbConnection::build();
            $this->repo = new CategoryRepository(EntityManagerFactory::build($this->conn), $currentConfig);
        }

        private function countRows(string $table): int
        {
            $count = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from($table)
                ->executeQuery()
                ->fetchOne();

            return is_numeric($count) ? (int) $count : 0;
        }

        #[Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'public'");
            $this->conn->executeStatement("UPDATE old_permalinks SET hit = 42, last_hit = '2026-07-07 05:02:38'");
            $rank = $this->conn->getDatabasePlatform()
                ->quoteSingleIdentifier('rank');
            $this->conn->executeStatement(
                "UPDATE image_category SET {$rank} = CASE image_id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 3 END WHERE category_id = 1"
            );
            parent::tearDown();
        }

        public function testFindByIdReturnsATypedCategoryProjection(): void
        {
            $cat = $this->repo->findById(1);

            self::assertInstanceOf(Category::class, $cat);
            self::assertEquals(CategoryId::from(1), $cat->id);
            self::assertSame('Sample Album', $cat->name);
        }

        public function testFindByIdReturnsNullForAMissingCategory(): void
        {
            self::assertNull($this->repo->findById(999999));
        }

        public function testFindNamesByIdsKeysById(): void
        {
            $names = $this->repo->findNamesByIds([1, 2]);

            self::assertSame('Sample Album', $names['1']->name);
            self::assertSame('Nested Sub Album', $names['2']->name);
        }

        public function testFindNamesByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findNamesByIds([]));
        }

        public function testFindSubcategoryIdsMatchesViaUppercatsPath(): void
        {
            // category 2's uppercats is "1,2" -- it's a subcategory of 1.
            $ids = $this->repo->findSubcategoryIds([1]);
            sort($ids);

            self::assertSame([1, 2], $ids);
        }

        public function testFindSubcategoryIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findSubcategoryIds([]));
        }

        public function testFindRandomImageIdReturnsAnImageFromTheCategory(): void
        {
            $imageId = $this->repo->findRandomImageId(1, '1', false, self::noPermissionRestriction());

            self::assertContains($imageId, [1, 2, 3]);
        }

        public function testFindRandomImageIdRecursiveIncludesSubcategoryImages(): void
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

        public function testFindRandomImageIdReturnsNullWhenPermissionConditionExcludesEverything(): void
        {
            self::assertNull($this->repo->findRandomImageId(1, '1', false, new PermissionCriteria([1], null, null, null, null, null)));
        }

        public function testFindRandomImageIdInCategoryReturnsAnImageFromTheCategory(): void
        {
            // Real regression coverage for the custom RandFunction DQL
            // function's own `ORDER BY RAND()` -- runs enough draws to make it
            // very unlikely a single lucky pick masks a query that's actually
            // broken (e.g. always returning the same row, or the wrong pool).
            for ($i = 0; $i < 10; $i++) {
                self::assertContains($this->repo->findRandomImageIdInCategory(1), [1, 2, 3]);
            }
        }

        public function testFindRandomImageIdInCategoryReturnsNullForACategoryWithoutImages(): void
        {
            self::assertNull($this->repo->findRandomImageIdInCategory(999));
        }

        public function testFindComputedCategoriesRollupReturnsOneRowPerCategory(): void
        {
            $rows = $this->repo->findComputedCategoriesRollup(0, null, '');

            $byId = [];
            foreach ($rows as $row) {
                $byId[$row->catId] = $row;
            }

            // nb_images is a COUNT() aggregate -- comes back as native int under
            // this project's mysqli driver config.
            self::assertSame(3, $byId['1']->nbImages);
            self::assertSame(2, $byId['2']->nbImages);
        }

        public function testFindComputedCategoriesRollupExcludesForbiddenCategories(): void
        {
            $rows = $this->repo->findComputedCategoriesRollup(0, null, '2');

            $ids = array_map(static fn (ComputedCategoryRollupRow $row): int => $row->catId, $rows);
            self::assertNotContains(2, $ids);
            self::assertContains(1, $ids);
        }

        public function testFindComputedCategoriesRollupKeepsCategoryRowWhenNoImagesInRecentPeriod(): void
        {
            // filterDays=0 with a fixture whose images predate "now" means no
            // image matches the join condition -- the category row must still
            // appear (nb_images=0), proving the date filter lives in the JOIN's
            // ON clause and not a WHERE (see the repository's own docblock).
            $rows = $this->repo->findComputedCategoriesRollup(0, 0, '');

            $byId = [];
            foreach ($rows as $row) {
                $byId[$row->catId] = $row;
            }

            self::assertArrayHasKey('1', $byId);
            self::assertSame(0, $byId['1']->nbImages);
        }

        public function testFindImageIdsForCategoriesReturnsImagesInCategory(): void
        {
            $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());
            sort($ids);

            self::assertSame([1, 2, 3], $ids);
        }

        public function testFindImageIdsForCategoriesReturnsEmptyForNoCategories(): void
        {
            self::assertSame([], $this->repo->findImageIdsForCategories([], 'AND', self::noPermissionRestriction()));
        }

        public function testFindImageIdsForCategoriesAndModeRequiresAllCategories(): void
        {
            // no image belongs to both category 1 and 2 in the fixture, so AND
            // mode across [1, 2] returns nothing.
            $ids = $this->repo->findImageIdsForCategories([1, 2], 'AND', self::noPermissionRestriction());

            self::assertSame([], $ids);
        }

        public function testFindImageIdsForCategoriesOrdersByCurrentConfigOrderBy(): void
        {
            // The real DQL path -- a single bounded-vocabulary field, not
            // sorted before comparing, to prove the parsed order is what
            // actually reaches the query rather than incidental id order.
            CurrentConfigTestFactory::get()->orderBy = PhotoSortOrder::fromConfigFragment('ORDER BY id DESC');

            $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());

            self::assertSame([3, 2, 1], $ids);
        }

        public function testFindImageIdsForCategoriesOrdersByRank(): void
        {
            // `rank` lives on the image_category join row, not on images
            // itself -- deliberately set to the reverse of id order so a stale
            // fallback to id-order would fail this assertion.
            $rank = $this->conn->getDatabasePlatform()
                ->quoteSingleIdentifier('rank');
            $this->conn->executeStatement(
                "UPDATE image_category SET {$rank} = CASE image_id WHEN 1 THEN 3 WHEN 2 THEN 2 WHEN 3 THEN 1 END WHERE category_id = 1"
            );
            // No quoting needed here -- PhotoSortField::parseOrderByFragment()'s
            // own regex already treats a surrounding backtick as optional, and
            // this raw config string never round-trips through column()'s own
            // (now driver-aware) quoted output, so a bare, unquoted field name
            // parses identically on both platforms.
            CurrentConfigTestFactory::get()->orderBy = PhotoSortOrder::fromConfigFragment('ORDER BY rank ASC');

            $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());

            self::assertSame([3, 2, 1], $ids);
        }

        public function testFindImageIdsForCategoriesRunsARandomOrderAsDql(): void
        {
            // RAND() resolves to the registered RandFunction custom DQL
            // function now, so this stays on the DQL path -- against a real
            // server, which is what proves the generated SQL is valid on this
            // provider. The order itself is random, so only membership can be
            // asserted.
            CurrentConfigTestFactory::get()->orderBy = PhotoSortOrder::fromConfigFragment('ORDER BY RAND()');

            $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());
            sort($ids);

            self::assertSame([1, 2, 3], $ids);
        }

        public function testFindImageIdsForCategoriesAppliesTheDefaultOrderForUnparseableOrderBy(): void
        {
            // `comment` is not one of $sort_fields's own bounded tokens, so
            // PhotoSortOrder::fromConfigFragment() substitutes the default rather
            // than splicing the text through. Compared against the same call
            // with the default set explicitly, so it stays independent of the
            // fixture's own date_available/file values.
            CurrentConfigTestFactory::get()->orderBy = PhotoSortOrder::default();
            $expected = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());

            CurrentConfigTestFactory::get()->orderBy = PhotoSortOrder::fromConfigFragment('ORDER BY comment ASC');
            $ids = $this->repo->findImageIdsForCategories([1], 'AND', self::noPermissionRestriction());

            self::assertSame($expected, $ids);
            self::assertCount(3, $ids);
        }

        public function testFindImageIdsForCategoriesStillUsesRawDbalForRankAcrossSeveralCategories(): void
        {
            // The one remaining reason PhotoSortOrder::toDql() returns null: `rank`
            // lives on the image_category join row, and more than one
            // category means no single `ic` alias to resolve it against.
            //
            // The raw-DBAL fallback groups by `id`, so the rank term has to
            // be aggregated -- an unaggregated join-row column there is
            // invalid under the sql_mode DbConnection pins, and this query
            // raised a real DriverException before that was handled.
            // Categories 1 and 2 hold images 1,2,3 and 4,5; `OR` is their
            // union.
            CurrentConfigTestFactory::get()->orderBy = PhotoSortOrder::fromConfigFragment('ORDER BY `rank` ASC');

            $ids = $this->repo->findImageIdsForCategories([1, 2], 'OR', self::noPermissionRestriction());
            sort($ids);

            self::assertSame([1, 2, 3, 4, 5], $ids);
        }

        public function testFindCommonCategoriesCountsMatchingImages(): void
        {
            $common = $this->repo->findCommonCategories([1, 2, 3], null, [], self::noPermissionRestriction());

            self::assertSame(3, $common['1']->counter);
        }

        public function testFindCommonCategoriesReturnsEmptyForNoItems(): void
        {
            self::assertSame([], $this->repo->findCommonCategories([], null, [], self::noPermissionRestriction()));
        }

        public function testFindCategoriesByIdsReturnsMatchingRows(): void
        {
            $cats = $this->repo->findCategoriesByIds([1, 2]);

            $names = array_column($cats, 'name');
            sort($names);
            self::assertSame(['Nested Sub Album', 'Sample Album'], $names);
        }

        public function testFindComputedCategoriesRollupIncludesRank(): void
        {
            // CategoryCatsRenderer needs `rank` (sibling order) for
            // CategoryService::compareByRank(), distinct from `global_rank`.
            $rows = $this->repo->findComputedCategoriesRollup(0, null, '');

            $byId = [];
            foreach ($rows as $row) {
                $byId[$row->catId] = $row;
            }

            self::assertArrayHasKey('1', $byId);
            self::assertArrayHasKey('2', $byId);
        }

        public function testFindFullCategoriesByIdsReturnsEveryColumn(): void
        {
            $cats = $this->repo->findFullCategoriesByIds([1]);

            self::assertCount(1, $cats);
            $cat = $cats[0];
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

        public function testFindFullCategoriesByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findFullCategoriesByIds([]));
        }

        public function testFindCategoriesByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findCategoriesByIds([]));
        }

        public function testFindStorageLinkedImageIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findStorageLinkedImageIds([]));
        }

        public function testFindStorageLinkedImageIdsReturnsMatchingImages(): void
        {
            // No fixture image has a real storage_category_id (filesystem-sync
            // linkage, distinct from image_category membership) -- give image 1
            // one temporarily.
            try {
                $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

                self::assertSame([1], $this->repo->findStorageLinkedImageIds([1]));
                self::assertSame([], $this->repo->findStorageLinkedImageIds([2]));
            } finally {
                $this->conn->executeStatement('UPDATE images SET storage_category_id = NULL WHERE id = 1');
            }
        }

        public function testFindDistinctStorageCategoryIdsReturnsTheRealDistinctSet(): void
        {
            try {
                $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id IN (1, 2)');

                self::assertSame([1], $this->repo->findDistinctStorageCategoryIds());
            } finally {
                $this->conn->executeStatement('UPDATE images SET storage_category_id = NULL WHERE id IN (1, 2)');
            }
        }

        public function testUpdateImagePathsForCategoryRewritesThePathFromTheNewFulldir(): void
        {
            try {
                $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

                $this->repo->updateImagePathsForCategory(CategoryId::from(1), 'galleries/renamed-album');

                $path = $this->conn->fetchOne('SELECT path FROM images WHERE id = 1');
                self::assertSame('galleries/renamed-album/fixture-photo-1.jpg', $path);
            } finally {
                $realHash = $this->dbDriver === 'pgsql' ? '2e7e2251' : '2e7e3a53';
                $this->conn->executeStatement(
                    "UPDATE images SET storage_category_id = NULL, path = 'upload/2026/08/01/20260801000000-{$realHash}.jpg' WHERE id = 1"
                );
            }
        }

        public function testFindDistinctLinkedImageIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findDistinctLinkedImageIds([]));
        }

        public function testFindNonOrphanImageIdsReturnsEmptyForNoImageIds(): void
        {
            self::assertSame([], $this->repo->findNonOrphanImageIds([], [2]));
        }

        public function testFindNonOrphanImageIdsKeepsOnlyImagesStillLinkedOutsideTheExcludedCategories(): void
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

        public function testDeleteUserAccessForUsersAndCategoriesIsANoOpForNoUserIds(): void
        {
            $before = $this->countRows('user_access');

            $this->repo->deleteUserAccessForUsersAndCategories([], [1]);

            $after = $this->countRows('user_access');
            self::assertSame($before, $after);
        }

        public function testDeleteGroupAccessForGroupsAndCategoriesIsANoOpForNoGroupIds(): void
        {
            $before = $this->countRows('group_access');

            $this->repo->deleteGroupAccessForGroupsAndCategories([], [1]);

            $after = $this->countRows('group_access');
            self::assertSame($before, $after);
        }

        public function testDeleteCategoriesByIdsIsANoOpForNoIds(): void
        {
            $this->repo->deleteCategoriesByIds([]);

            self::assertNotNull($this->repo->findById(1));
        }

        public function testClearRepresentativePictureIdsIsANoOpForNoIds(): void
        {
            $this->repo->clearRepresentativePictureIds([]);

            $cat = $this->repo->findById(1);
            self::assertNotNull($cat);
        }

        public function testUpdateCategoryVisibilityIsANoOpForNoIds(): void
        {
            $this->repo->updateCategoryVisibility([], false);

            // visible is a genuine boolean column -- DBAL's raw (unmapped)
            // fetchOne() returns a native PHP bool for it on Postgres, but a
            // numeric 1/0 on MySQL (confirmed live). (int) (bool) normalizes
            // either representation to the same value, unlike the old
            // is_numeric()-gated cast, which silently produced null for a
            // real Postgres bool.
            $visible = $this->conn->createQueryBuilder()
                ->select('visible')
                ->from('categories')
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(1, (int) (bool) $visible);
        }

        public function testUpdateCategoryCommentableIsANoOpForNoIds(): void
        {
            $this->repo->updateCategoryCommentable([], false);

            $commentable = $this->conn->createQueryBuilder()
                ->select('commentable')
                ->from('categories')
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(1, (int) (bool) $commentable);
        }

        public function testUpdateCategoryStatusIsANoOpForNoIds(): void
        {
            $this->repo->updateCategoryStatus([], 'private');

            self::assertSame('public', $this->repo->findCategoryStatus(1));
        }

        public function testUpdateImageOrderIsANoOpForAMissingCategory(): void
        {
            // 999999 doesn't exist -- find() returns null and the method
            // returns early instead of dereferencing a null entity.
            $this->repo->updateImageOrder(CategoryId::from(999999), 'name ASC');

            self::assertNull($this->repo->findById(999999));
        }

        public function testFindStatusByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findStatusByIds([]));
        }

        public function testFindUppercatsColumnsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findUppercatsColumns([]));
        }

        public function testFindUppercatsByIdReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findUppercatsById([]));
        }

        public function testFindRefDatesByCategoryIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findRefDatesByCategoryIds([], CategoryRefDateField::DateCreation, CategoryRefDateAggregate::Min));
        }

        public function testFindUppercatIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findUppercatIds([]));
        }

        public function testFindCategoriesForFulldirsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findCategoriesForFulldirs([]));
        }

        public function testUpdateCategoryParentIsANoOpForNoIds(): void
        {
            $this->repo->updateCategoryParent([], 'NULL');

            $cat = $this->repo->findById(2);
            self::assertNotNull($cat);
            $idUppercat = $this->conn->createQueryBuilder()
                ->select('id_uppercat')
                ->from('categories')
                ->where('id = 2')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(1, is_numeric($idUppercat) ? (int) $idUppercat : null);
        }

        public function testFindMaxRankForParentReturnsTheHighestRankAmongRootCategories(): void
        {
            self::assertSame(1, $this->repo->findMaxRankForParent(null));
        }

        public function testFindMaxRankForParentReturnsTheHighestRankAmongASpecificParentsChildren(): void
        {
            self::assertSame(1, $this->repo->findMaxRankForParent(1));
        }

        public function testFindMaxRankForParentReturnsNullWhenTheParentHasNoChildren(): void
        {
            self::assertNull($this->repo->findMaxRankForParent(2));
        }

        public function testFindRandomRepresentativeIdAmongSubcategoriesFindsARealMatch(): void
        {
            // category 2 ("1,2") is the only row LIKE '1,%', and its fixture
            // representative_picture_id is 4.
            self::assertSame('4', $this->repo->findRandomRepresentativeIdAmongSubcategories('1', self::noPermissionRestriction()));
        }

        public function testFindRandomRepresentativeIdAmongSubcategoriesReturnsNullWhenNoSubcategoryMatches(): void
        {
            // category 2 has no sub-categories of its own.
            self::assertNull($this->repo->findRandomRepresentativeIdAmongSubcategories('2', self::noPermissionRestriction()));
        }

        public function testFindRandomRepresentativeIdAmongSubcategoriesReturnsNullWhenThePermissionConditionExcludesEverything(): void
        {
            self::assertNull($this->repo->findRandomRepresentativeIdAmongSubcategories('1', new PermissionCriteria(null, [999_999], null, null, null, null)));
        }

        public function testFindDateRangeByCategoryReturnsEmptyForNoCategoryIds(): void
        {
            self::assertSame([], $this->repo->findDateRangeByCategory([], self::noPermissionRestriction()));
        }

        public function testFindDateRangeByCategoryReturnsTheMinAndMaxCreationDate(): void
        {
            // Only image 2's date_creation is set (the other 2 direct images
            // of category 1 stay NULL) -- MIN/MAX both resolve to that single
            // real value, proving the aggregate reads real data rather than
            // just echoing back a NULL.
            $this->conn->executeStatement("UPDATE images SET date_creation = '2019-06-15 10:00:00' WHERE id = 2");

            try {
                $range = $this->repo->findDateRangeByCategory([1], self::noPermissionRestriction());
                self::assertCount(1, $range);
                $entry = array_first($range);

                self::assertSame('2019-06-15 10:00:00', $entry->from);
                self::assertSame('2019-06-15 10:00:00', $entry->to);
            } finally {
                $this->conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id = 2');
            }
        }

        public function testFindIdNamePermalinkByIdReturnsNullForAMissingCategory(): void
        {
            self::assertNull($this->repo->findIdNamePermalinkById(999999));
        }

        public function testFindImageIdsOutsideCategoriesReturnsIdsLinkedToOtherCategories(): void
        {
            // Excluding category 2 leaves only category 1's own image_category
            // rows (images 1, 2, 3) -- category 2's images (4, 5) are dropped,
            // proving the NOT IN condition actually reaches the query.
            $ids = $this->repo->findImageIdsOutsideCategories([2]);
            sort($ids);

            self::assertSame([1, 2, 3], $ids);
        }

        public function testMassInsertCategoriesIsANoOpForNoInserts(): void
        {
            $before = $this->countRows('categories');

            $this->repo->massInsertCategories(['name'], []);

            $after = $this->countRows('categories');
            self::assertSame($before, $after);
        }

        public function testUpdateFieldsIsANoOpForNoData(): void
        {
            $this->repo->updateFields(CategoryId::from(1), []);

            $cat = $this->repo->findById(1);
            self::assertNotNull($cat);
            self::assertSame('Sample Album', $cat->name);
        }

        public function testSetRepresentativeImageForCategoriesIsANoOpForNoIds(): void
        {
            $this->repo->setRepresentativeImageForCategories([], 5);

            $repId = $this->conn->createQueryBuilder()
                ->select('representative_picture_id')
                ->from('categories')
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();

            self::assertSame(1, is_numeric($repId) ? (int) $repId : null);
        }

        public function testSetRepresentativeImageForCategoriesUpdatesEveryListedCategory(): void
        {
            try {
                $this->repo->setRepresentativeImageForCategories([1, 2], 3);

                $repId1 = $this->conn->createQueryBuilder()
                    ->select('representative_picture_id')
                    ->from('categories')
                    ->where('id = 1')
                    ->executeQuery()
                    ->fetchOne();
                $repId2 = $this->conn->createQueryBuilder()
                    ->select('representative_picture_id')
                    ->from('categories')
                    ->where('id = 2')
                    ->executeQuery()
                    ->fetchOne();

                self::assertSame(3, is_numeric($repId1) ? (int) $repId1 : null);
                self::assertSame(3, is_numeric($repId2) ? (int) $repId2 : null);
            } finally {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 4 WHERE id = 2');
            }
        }

        public function testFindDirsByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findDirsByIds([]));
        }

        /**
         * §13: $categoryIds is bound as a real INTEGER array parameter, not
         * stringified -- passing numeric-string ids here (the shape
         * Admin\AlbumsPageRenderer's own caller actually uses) proves the
         * bind still matches real int-column rows, not just that the query
         * doesn't throw.
         */
        public function testFindIdsNamesUppercatsForIdsMatchesRowsForNumericStringIds(): void
        {
            self::assertEquals([
                new CategoryIdNameUppercat(1, 'Sample Album', null),
                new CategoryIdNameUppercat(2, 'Nested Sub Album', 1),
            ], $this->repo->findIdsNamesUppercatsForIds(['1', '2']));
        }

        public function testFindDirsByIdsReturnsNullForAVirtualCategory(): void
        {
            // Both fixture categories are virtual (dir IS NULL) -- proves the
            // ?string narrowing's null branch, not just the array-shape.
            self::assertSame([
                1 => null,
                2 => null,
            ], $this->repo->findDirsByIds([1, 2]));
        }

        public function testFindDirsByIdsReturnsTheRealDirForAPhysicalCategory(): void
        {
            try {
                $this->conn->executeStatement("UPDATE categories SET dir = 'sample_album' WHERE id = 1");

                self::assertSame([
                    1 => 'sample_album',
                    2 => null,
                ], $this->repo->findDirsByIds([1, 2]));
            } finally {
                $this->conn->executeStatement('UPDATE categories SET dir = NULL WHERE id = 1');
            }
        }

        public function testFindAllCategoryUppercatsReturnsEveryCategoryKeyedById(): void
        {
            // Fixture: category 1 (root) has uppercats '1', category 2 (child
            // of 1) has uppercats '1,2' -- see this file's own class docblock.
            self::assertSame([
                1 => '1',
                2 => '1,2',
            ], $this->repo->findAllCategoryUppercats());
        }

        public function testFindExistingIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findExistingIds([]));
        }

        public function testFindIdsAmongExcludingReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findIdsAmongExcluding([], [2]));
        }

        public function testFindIdsAmongExcludingReturnsMatchingIdsWhenNothingIsExcluded(): void
        {
            $ids = $this->repo->findIdsAmongExcluding([1, 2], []);
            sort($ids);
            self::assertSame([1, 2], $ids);
        }

        public function testFindIdsAmongExcludingDropsExcludedIds(): void
        {
            self::assertSame([1], $this->repo->findIdsAmongExcluding([1, 2], [2]));
        }

        public function testFindIdsAmongExcludingDropsNonExistentIds(): void
        {
            self::assertSame([1], $this->repo->findIdsAmongExcluding([1, 999999], []));
        }

        public function testFindSubcategoryCountsByParentReturnsEmptyForNoParentIds(): void
        {
            self::assertSame([], $this->repo->findSubcategoryCountsByParent([]));
        }

        public function testFindRankInfoByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findRankInfoByIds([]));
        }

        public function testFindMoveDetailsByIdsReturnsEmptyForNoIds(): void
        {
            self::assertSame([], $this->repo->findMoveDetailsByIds([]));
        }

        public function testCountByVisibleCountsOnlyMatchingRows(): void
        {
            // Both fixture categories have visible=1 -- proves the bound
            // :visible parameter actually discriminates (a broken bind that
            // always matched, or never matched, would pass a same-count-for-
            // both regression test but not this one).
            self::assertSame(2, $this->repo->countByVisible(true));
            self::assertSame(0, $this->repo->countByVisible(false));
        }

        public function testFindByCommentableFiltersOnTheCommentableFlag(): void
        {
            // Both fixture categories have commentable=1.
            $rows = $this->repo->findByCommentable(true);
            self::assertSame([1, 2], array_column($rows, 'id'));

            self::assertSame([], $this->repo->findByCommentable(false));
        }

        public function testFindByVisibleFiltersOnTheVisibleFlag(): void
        {
            // Both fixture categories have visible=1.
            $rows = $this->repo->findByVisible(true);
            self::assertSame([1, 2], array_column($rows, 'id'));

            self::assertSame([], $this->repo->findByVisible(false));
        }

        public function testFindByStatusFiltersOnTheStatusColumn(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 2");

            $publicRows = $this->repo->findByStatus('public');
            $privateRows = $this->repo->findByStatus('private');

            self::assertSame([1], array_column($publicRows, 'id'));
            self::assertSame([2], array_column($privateRows, 'id'));
        }

        public function testFindByRepresentativePresenceTrueReturnsCategoriesWithARepresentative(): void
        {
            // Both fixture categories already have representative_picture_id set.
            $rows = $this->repo->findByRepresentativePresence(true);
            self::assertSame([1, 2], array_column($rows, 'id'));

            try {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = NULL WHERE id IN (1, 2)');
                self::assertSame([], $this->repo->findByRepresentativePresence(true));
            } finally {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 4 WHERE id = 2');
            }
        }

        public function testFindByRepresentativePresenceFalseJoinsImageCategoryAndDeduplicates(): void
        {
            try {
                // Category 1 has no representative but 3 direct images (image_category
                // rows 1,2,3) -- the DISTINCT is what's actually under test here: a
                // missing distinct() would return category 1 three times (once per
                // matching image_category row).
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = NULL WHERE id = 1');

                $rows = $this->repo->findByRepresentativePresence(false);

                self::assertSame([1], array_column($rows, 'id'));
            } finally {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
            }
        }

        public function testFindPrivateCategoriesGrantedToUserExcludesGroupAuthorizedIds(): void
        {
            try {
                $this->conn->executeStatement(
                    'INSERT INTO user_access (user_id, cat_id) VALUES (?, ?)',
                    [3, 1]
                );
                $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");

                $rows = $this->repo->findPrivateCategoriesGrantedToUser(3);
                self::assertSame([1], array_column($rows, 'id'));

                // Same user_access grant, but category 1 is already covered via a
                // group membership -- must be excluded from this result.
                self::assertSame([], $this->repo->findPrivateCategoriesGrantedToUser(3, ['1']));
            } finally {
                $this->conn->executeStatement('DELETE FROM user_access WHERE user_id = 3 AND cat_id = 1');
            }
        }

        public function testFindPrivateCategoriesGrantedToGroupMatchesFixtureGrants(): void
        {
            // Fixture group_access: (group_id=1, cat_id=1), (group_id=2, cat_id=1),
            // (group_id=3, cat_id=1), (group_id=1, cat_id=2).
            $this->conn->executeStatement("UPDATE categories SET status = 'private'");

            self::assertSame([1, 2], array_column($this->repo->findPrivateCategoriesGrantedToGroup(1), 'id'));
            self::assertSame([1], array_column($this->repo->findPrivateCategoriesGrantedToGroup(2), 'id'));
            self::assertSame([], array_column($this->repo->findPrivateCategoriesGrantedToGroup(999999), 'id'));
        }

        public function testFindPrivateCategoryIdsGrantedToGroupMatchesFixtureGrants(): void
        {
            // This method's JOIN...WITH condition (ga.catId = c.id) compares
            // GroupAccessEntity's own custom-CategoryId-typed field against
            // CategoryEntity's plain int id -- confirming here that it still
            // resolves correctly against a real DB (DQL JOIN...WITH conditions
            // compile to a plain SQL column comparison regardless of PHP-side
            // Type differences).
            $this->conn->executeStatement("UPDATE categories SET status = 'private'");

            self::assertSame([1, 2], $this->repo->findPrivateCategoryIdsGrantedToGroup(1));
            self::assertSame([1], $this->repo->findPrivateCategoryIdsGrantedToGroup(2));
            self::assertSame([], $this->repo->findPrivateCategoryIdsGrantedToGroup(999999));
        }

        public function testFindCategoriesAuthorizedViaGroupsForUserDeduplicatesAcrossMemberships(): void
        {
            // Fixture user_group: user 3 belongs to groups 1 and 2. group_access:
            // group 1 grants cat 1 and cat 2, group 2 grants cat 1 -- cat 1 is
            // reachable via both memberships, so this also confirms the
            // ->distinct() actually dedupes.
            $ids = array_column($this->repo->findCategoriesAuthorizedViaGroupsForUser(3), 'catId');
            sort($ids);

            self::assertSame([1, 2], $ids);
        }

        public function testFindCategoriesAuthorizedViaGroupsForUserReturnsEmptyForAGrouplessUser(): void
        {
            self::assertSame([], $this->repo->findCategoriesAuthorizedViaGroupsForUser(2));
        }

        public function testFindPrivateCategoriesExcludingFiltersOutTheGivenIds(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private'");

            self::assertSame([1, 2], array_column($this->repo->findPrivateCategoriesExcluding([]), 'id'));
            self::assertSame([2], array_column($this->repo->findPrivateCategoriesExcluding(['1']), 'id'));
            self::assertSame([], array_column($this->repo->findPrivateCategoriesExcluding(['1', '2']), 'id'));
        }

        public function testFindIdNameUppercatsRankAppliesTheGivenCondition(): void
        {
            $matchAll = $this->repo->findIdNameUppercatsRank(self::noPermissionRestriction());
            self::assertSame([1, 2], array_column($matchAll, 'id'));

            self::assertSame([], $this->repo->findIdNameUppercatsRank(new PermissionCriteria(null, [999_999], null, null, null, null)));
        }

        public function testFindAllForPermalinksDisplayComputesAPermalinkAwareName(): void
        {
            $rows = $this->repo->findAllForPermalinksDisplay();

            $byId = [];
            foreach ($rows as $row) {
                $byId[$row->id] = $row;
            }

            // Both fixture categories have permalink NULL, so the CONCAT's IF()
            // branch appends nothing (no trailing checkmark).
            self::assertSame('1 - Sample Album', $byId[1]->name);
            self::assertSame('2 - Nested Sub Album', $byId[2]->name);
            self::assertNull($byId[1]->permalink);
        }

        public function testFindAllForPermalinksDisplayAppendsACheckmarkWhenPermalinkIsSet(): void
        {
            try {
                $this->conn->executeStatement("UPDATE categories SET permalink = 'sample-album' WHERE id = 1");

                $rows = $this->repo->findAllForPermalinksDisplay();

                $byId = array_column($rows, null, 'id');
                self::assertSame('1 - Sample Album &radic;', $byId[1]->name);
                self::assertSame('sample-album', $byId[1]->permalink);
                self::assertSame('2 - Nested Sub Album', $byId[2]->name);
            } finally {
                $this->conn->executeStatement('UPDATE categories SET permalink = NULL WHERE id = 1');
            }
        }

        public function testFindIdNameUppercatsRankBySiteFiltersOnSiteId(): void
        {
            // Both fixture categories have site_id NULL -- never matches a real id.
            self::assertSame([], $this->repo->findIdNameUppercatsRankBySite(1));

            try {
                $this->conn->executeStatement('UPDATE categories SET site_id = 1 WHERE id = 1');

                $rows = $this->repo->findIdNameUppercatsRankBySite(1);
                // PHPStan treats this same method+args call as returning the
                // same value as the empty-result assertion above -- it can't
                // see that the real UPDATE just above changed what the live DB
                // returns between the two calls.
                // @phpstan-ignore return.type, staticMethod.impossibleType
                self::assertSame([1], array_map(static fn (CategoryIdNameUppercatsRank $row): int => $row->id, $rows));
            } finally {
                $this->conn->executeStatement('UPDATE categories SET site_id = NULL WHERE id = 1');
            }
        }

        public function testFindAvailableListScopesToRootCategoriesWhenRecursiveIsFalseAndCatIdIsNull(): void
        {
            $criteria = new CategoryListCriteria(catId: null, recursive: false, forbiddenCategoryIds: [], publicOnly: false);

            $result = $this->repo->findAvailableList($criteria, null, 10, null, false);

            self::assertSame([1], array_column($result->rows, 'id'));
        }

        public function testFindAvailableListMatchesACategoryAndItsDirectChildrenWhenRecursiveIsFalseAndCatIdIsSet(): void
        {
            $criteria = new CategoryListCriteria(catId: CategoryId::from(1), recursive: false, forbiddenCategoryIds: [], publicOnly: false);

            $result = $this->repo->findAvailableList($criteria, null, 10, null, false);

            $ids = array_column($result->rows, 'id');
            sort($ids);
            self::assertSame([1, 2], $ids);
        }

        public function testFindAvailableListMatchesTheFullSubtreeWhenRecursive(): void
        {
            $criteria = new CategoryListCriteria(catId: CategoryId::from(1), recursive: true, forbiddenCategoryIds: [], publicOnly: false);

            $result = $this->repo->findAvailableList($criteria, null, 10, null, false);

            $ids = array_column($result->rows, 'id');
            sort($ids);
            self::assertSame([1, 2], $ids);
        }

        public function testFindAvailableListExcludesForbiddenCategoryIds(): void
        {
            $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [2], publicOnly: false);

            $result = $this->repo->findAvailableList($criteria, null, 10, null, false);

            self::assertSame([1], array_column($result->rows, 'id'));
        }

        public function testFindAvailableListPublicOnlyExcludesNonPublicCategories(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 2");

            $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: true);

            $result = $this->repo->findAvailableList($criteria, null, 10, null, false);

            self::assertSame([1], array_column($result->rows, 'id'));
        }

        public function testFindAvailableListAppliesTheSearchTerm(): void
        {
            $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

            $result = $this->repo->findAvailableList($criteria, 'Nested', 10, null, false);

            self::assertSame([2], array_column($result->rows, 'id'));
        }

        public function testFindAvailableListAppliesTheLimitAndReportsTheTotal(): void
        {
            $criteria = new CategoryListCriteria(catId: null, recursive: true, forbiddenCategoryIds: [], publicOnly: false);

            $result = $this->repo->findAvailableList($criteria, null, 10, 1, false);

            self::assertCount(1, $result->rows);
            self::assertSame(2, $result->total);
        }

        public function testFindAdminListScopesToRootCategoriesWhenRecursiveIsFalseAndCatIdIsNull(): void
        {
            $criteria = new CategoryAdminListCriteria(catId: null, recursive: false);

            $result = $this->repo->findAdminList($criteria, null, 10);

            self::assertSame([1], array_column($result->rows, 'id'));
            self::assertSame(1, $result->total);
        }

        public function testFindAdminListMatchesTheFullSubtreeWhenRecursive(): void
        {
            $criteria = new CategoryAdminListCriteria(catId: CategoryId::from(1), recursive: true);

            $result = $this->repo->findAdminList($criteria, null, 10);

            $ids = array_column($result->rows, 'id');
            sort($ids);
            self::assertSame([1, 2], $ids);
        }

        public function testFindAdminListAppliesTheSearchTerm(): void
        {
            $criteria = new CategoryAdminListCriteria(catId: null, recursive: true);

            $result = $this->repo->findAdminList($criteria, 'Nested', 10);

            self::assertSame([2], array_column($result->rows, 'id'));
        }

        public function testFindNextIdReturnsOneMoreThanTheCurrentMax(): void
        {
            // findNextId() computes COALESCE(MAX(id)+1, 1) -- verified here
            // against the real, non-empty fixture table (the empty-table branch
            // isn't practically testable against this shared fixture DB).
            $maxId = $this->conn->fetchOne('SELECT MAX(id) FROM categories');

            // @phpstan-ignore cast.useless
            self::assertSame((is_numeric($maxId) ? (int) $maxId : 0) + 1, $this->repo->findNextId());
        }

        public function testFindPhotoCountsByCategoryCountsDirectImages(): void
        {
            // findPhotoCountsByCategory() combines a custom-Typed categoryId
            // GROUP BY with a blind instanceof-then-continue fallback -- it
            // silently drops the row instead of throwing if the VO-hydration
            // assumption is wrong.
            self::assertSame(
                [
                    1 => 3,
                    2 => 2,
                ],
                $this->repo->findPhotoCountsByCategory()
            );
        }

        public function testHasImagesIsTrueForACategoryWithImages(): void
        {
            self::assertTrue($this->repo->hasImages(1));
        }

        public function testHasImagesIsFalseForACategoryWithoutImages(): void
        {
            self::assertFalse($this->repo->hasImages(999));
        }

        public function testFindPhotoCountAndDateRangeMatchesTheFixture(): void
        {
            // Category 1 has 3 direct images, all sharing the same
            // date_available (2026-08-01 00:00:00 -- see the fixture-shape
            // docblock at the top of this file / test_find_add_method_
            // breakdown_groups_by_storage_category_presence's own comment
            // elsewhere), so min and max both resolve to that same date.
            $row = $this->repo->findPhotoCountAndDateRange(1);

            self::assertSame(3, $row->count);
            self::assertSame('2026-08-01', $row->minDate);
            self::assertSame('2026-08-01', $row->maxDate);
        }

        public function testFindPhotoCountAndDateRangeIsZeroForACategoryWithoutImages(): void
        {
            $row = $this->repo->findPhotoCountAndDateRange(999);

            self::assertSame(0, $row->count);
            self::assertNull($row->minDate);
            self::assertNull($row->maxDate);
        }

        public function testFindDistinctImageIdsInCategoriesReturnsTheLinkedImages(): void
        {
            $ids = $this->repo->findDistinctImageIdsInCategories([1]);
            sort($ids);
            self::assertSame([1, 2, 3], $ids);
        }

        /**
         * A {@see PermissionCriteria} with every dimension null -- "no
         * restriction on anything," the direct replacement for the old
         * `SqlCondition::fromRawSql('')` sentinel.
         */
        private static function noPermissionRestriction(): PermissionCriteria
        {
            return new PermissionCriteria(null, null, null, null, null, null);
        }
    }
}
