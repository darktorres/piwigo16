<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
    use Exception;
    use LogicException;
    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Category\Event\DeleteSite;
    use Piwigo\Category\Event\GetCategoryPreferredImageOrders;
    use Piwigo\Category\Projection\CategoryIdNamePermalink;
    use Piwigo\Category\Projection\ComputedCategoryRow;
    use Piwigo\Category\Projection\ImageOrderPreference;
    use Piwigo\Category\Projection\RandomImageCategoryQuery;
    use Piwigo\Common\ValueObject\CategoryId;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\ActivityLoggerInterface;
    use Piwigo\Core\FilterState;
    use Piwigo\Core\HtmlRenderingInterface;
    use Piwigo\Core\HttpStatusLine;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Permalink\PermalinkRepository;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Site\SiteEntity;
    use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tests\Support\ImageStdParamsTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\TranslatorTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Users\User;
    use Piwigo\Users\UserRepository;
    use RuntimeException;

    /**
     * Real fake for the 4 write methods' per-call ActivityLoggerInterface
     * parameter -- same standalone-class shape as
     * CategoryAdminServiceFakeActivityLogger (CategoryAdminServiceTest.php),
     * just under this file's own name to avoid a cross-file class collision.
     */
    final class CategoryServiceFakeActivityLogger implements ActivityLoggerInterface
    {
        /**
         * @var list<array{object: string, objectId: int|string|array<int, int|string>, action: string, details: array<string, mixed>}>
         */
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
        public function setStatusHeader(int $code, string $text = ''): HttpStatusLine
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
                new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig),
                new UserRepository(EntityManagerFactory::build($this->conn), EventDispatcherTestFactory::get(), $currentConfig)
            );

            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
            ]));
            $currentConfig->rateEnabled = true;
            // getCategoryRepresentantProperties()'s own DerivativeImage::thumbUrl()/
            // url() calls need a real, populated ImageStdParams type map --
            // DerivativeImage::urlService() resolves UrlServiceInterface live
            // from the container once Kernel is booted, which this test already
            // is via parent::setUp() -- no explicit wiring needed here, same
            // reasoning as NotificationByMailSenderTest's own identical setUp.
            // ImageStdParams::loadFromDb() itself needs CurrentConfigService.
            CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
            ImageStdParamsTestFactory::get()->loadFromDb();
        }

        #[Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'public'");
            // Safety-net restore for tests that touch old_permalinks' hit
            // counter (findCategoryIdFromPermalinks()'s is_old branch) --
            // matches CategoryRepositoryTest's own tearDown restore value.
            $this->conn->executeStatement("UPDATE old_permalinks SET hit = 42, last_hit = '2026-07-07 05:02:38'");
            parent::tearDown();
        }

        public function testCompareByGlobalRankOrdersNaturally(): void
        {
            $rows = [
                [
                    'global_rank' => '1.10',
                ],
                [
                    'global_rank' => '1.2',
                ],
            ];
            usort($rows, CategoryService::compareByGlobalRank(...));

            self::assertSame('1.2', $rows[0]['global_rank']);
        }

        public function testCompareByRankOrdersNumerically(): void
        {
            $rows = [
                [
                    'rank' => 10,
                ],
                [
                    'rank' => 2,
                ],
            ];
            usort($rows, CategoryService::compareByRank(...));

            self::assertSame(2, $rows[0]['rank']);
        }

        public function testGetCategoryInfoReturnsNullForMissingCategory(): void
        {
            self::assertNull($this->service->getCategoryInfo(999999));
        }

        public function testGetCategoryInfoReturnsASingleLevelUpperNamesForARootCategory(): void
        {
            $info = $this->service->getCategoryInfo(1);

            self::assertNotNull($info);
            self::assertEquals([new CategoryIdNamePermalink(id: 1, name: 'Sample Album', permalink: null)], $info->upperNames);
        }

        public function testGetCategoryInfoResolvesUpperNamesForANestedCategory(): void
        {
            $info = $this->service->getCategoryInfo(2);

            self::assertNotNull($info);
            $upperNames = $info->upperNames;
            self::assertCount(2, $upperNames);
            $first = $upperNames[0];
            $second = $upperNames[1];
            self::assertSame('Sample Album', $first->name);
            self::assertSame('Nested Sub Album', $second->name);
        }

        public function testGetCategoryInfoCoercesTrueFalseStringColumnsToBool(): void
        {
            $info = $this->service->getCategoryInfo(1);

            self::assertNotNull($info);
            self::assertTrue($info->visible);
        }

        public function testGetPreferredImageOrdersReturnsTheFixedOptionList(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
                'status' => 'normal',
            ]));

            $orders = $this->service->getPreferredImageOrders();

            self::assertNotEmpty($orders);
            self::assertSame('', $orders[0]->orderBy);
            // 'Permissions' (level DESC) is only visible to admins.
            $permissionsOrder = array_values(array_filter($orders, static fn (ImageOrderPreference $o): bool => $o->orderBy === 'level DESC'));
            self::assertFalse($permissionsOrder[0]->visible);
        }

        public function testGetPreferredImageOrdersPermissionsOptionVisibleToAdmin(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
                'status' => 'admin',
            ]));

            $orders = $this->service->getPreferredImageOrders();

            $permissionsOrder = array_values(array_filter($orders, static fn (ImageOrderPreference $o): bool => $o->orderBy === 'level DESC'));
            self::assertTrue($permissionsOrder[0]->visible);
        }

        public function testGetSubcategoryIdsIncludesTheCategoryAndItsChildren(): void
        {
            $ids = $this->service->getSubcategoryIds([1]);
            sort($ids);

            self::assertSame([1, 2], $ids);
        }

        public function testFindCategoryIdFromPermalinksMatchesTheLastPermalinkFirst(): void
        {
            $this->conn->executeStatement("UPDATE categories SET permalink = 'sample-album' WHERE id = 1");

            $idx = null;
            $catId = $this->service->findCategoryIdFromPermalinks(['no-match', 'sample-album'], $idx, new PermalinkRepository(EntityManagerFactory::build($this->conn)));

            self::assertSame(1, $catId);
            self::assertSame(1, $idx);

            $this->conn->executeStatement('UPDATE categories SET permalink = NULL WHERE id = 1');
        }

        public function testFindCategoryIdFromPermalinksReturnsNullWhenNothingMatches(): void
        {
            $idx = null;
            $catId = $this->service->findCategoryIdFromPermalinks(['no-such-permalink'], $idx, new PermalinkRepository(EntityManagerFactory::build($this->conn)));

            self::assertNull($catId);
        }

        public function testGetDisplayImagesCountReportsPhotosAndSubalbums(): void
        {
            $text = CategoryService::getDisplayImagesCount(LangTestFactory::get(), 0, 5, 1, false, ' / ');

            self::assertStringContainsString('5', $text);
            self::assertStringContainsString('1', $text);
        }

        public function testGetDisplayImagesCountReturnsEmptyStringForZeroImages(): void
        {
            self::assertSame('', CategoryService::getDisplayImagesCount(LangTestFactory::get(), 0, 0, 0));
        }

        public function testGetRandomImageInCategoryReturnsNullForAnEmptyCategory(): void
        {
            self::assertNull($this->service->getRandomImageInCategory(new RandomImageCategoryQuery(
                id: CategoryId::from(1),
                uppercats: '1',
                countImages: 0,
            )));
        }

        public function testGetRandomImageInCategoryReturnsAnImageId(): void
        {
            $imageId = $this->service->getRandomImageInCategory(new RandomImageCategoryQuery(
                id: CategoryId::from(1),
                uppercats: '1',
                countImages: 3,
            ), false);

            self::assertContains($imageId, [1, 2, 3]);
        }

        public function testGetComputedCategoriesRollsUpChildCountsIntoParent(): void
        {
            $result = $this->service->getComputedCategories(1, 0, '');
            $cats = $result['categories'];

            // category 1 (root) should count its own 3 images plus category
            // 2's 2 images in its subtree total.
            self::assertSame(5, $cats[1]->countImages);
            self::assertSame(1, $cats[1]->countCategories);
            self::assertSame(2, $cats[2]->countImages);
            self::assertNotNull($result['lastPhotoDate']);
        }

        public function testGetComputedCategoriesPrunesCategoriesWithNoRecentActivity(): void
        {
            // filterDays=0 against fixture dates in the past means nothing
            // qualifies as "recent" -- every category should be pruned.
            $result = $this->service->getComputedCategories(1, 0, '', 0);

            self::assertSame([], $result['categories']);
        }

        public function testRemoveComputedCategoryDecrementsParentCounters(): void
        {
            $cats = [
                1 => new ComputedCategoryRow(
                    catId: 1,
                    idUppercat: null,
                    globalRank: null,
                    rank: null,
                    dateLast: null,
                    nbImages: 3,
                    userId: 1,
                    nbCategories: 1,
                    countCategories: 1,
                    countImages: 5,
                ),
                2 => new ComputedCategoryRow(
                    catId: 2,
                    idUppercat: 1,
                    globalRank: null,
                    rank: null,
                    dateLast: null,
                    nbImages: 2,
                    userId: 1,
                    countImages: 2,
                ),
            ];

            CategoryService::removeComputedCategory($cats, $cats[2]);

            self::assertArrayNotHasKey(2, $cats);
            self::assertSame(0, $cats[1]->nbCategories);
            self::assertSame(3, $cats[1]->countImages);
            self::assertSame(0, $cats[1]->countCategories);
        }

        public function testGetImageIdsForCategoriesReturnsImages(): void
        {
            $ids = $this->service->getImageIdsForCategories([1], 'AND', false);
            sort($ids);

            self::assertSame([1, 2, 3], $ids);
        }

        public function testGetCommonCategoriesCountsImagesPerCategory(): void
        {
            $common = $this->service->getCommonCategories([1, 2, 3], null, [], false);

            self::assertSame(3, $common[1]->counter);
        }

        public function testGetRelatedCategoriesMenuSetsCountImagesForDirectlyLinkedCategories(): void
        {
            $cats = $this->service->getRelatedCategoriesMenu([1, 2, 3], []);

            $byId = [];
            foreach ($cats as $cat) {
                $byId[$cat->id] = $cat;
            }

            self::assertSame(3, $byId[1]->countImages);
            self::assertGreaterThanOrEqual(1, $byId[1]->level);
        }

        public function testGetRelatedCategoriesMenuReturnsEmptyForNoItems(): void
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

        public function testGetImageIdsForCategoriesWithPermissionsBuildsValidSql(): void
        {
            // exercising usePermissions=true with a genuinely non-empty
            // condition is what catches the andWhere() double-AND-wrap bug
            // that an empty (guest-default) CurrentUser silently skips.
            CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));

            $ids = $this->service->getImageIdsForCategories([1], 'AND', true);
            sort($ids);

            self::assertSame([1, 2, 3], $ids);
        }

        public function testGetCommonCategoriesWithPermissionsBuildsValidSql(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));

            $common = $this->service->getCommonCategories([1, 2, 3], null, [4, 5], true);

            self::assertSame(3, $common[1]->counter);
        }

        public function testGetRelatedCategoriesMenuWithPermissionsBuildsValidSql(): void
        {
            // getRelatedCategoriesMenu() always calls getCommonCategories()
            // with usePermissions defaulted to true internally -- this is the
            // real menubar.inc.php code path the bug above was caught on.
            CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));

            $cats = $this->service->getRelatedCategoriesMenu([1, 2, 3], []);

            self::assertNotSame([], $cats);
        }

        public function testGetPreferredImageOrdersSkipsAMalformedEntryFromTheEventHandler(): void
        {
            EventDispatcherTestFactory::get()->addTypedHandler(
                GetCategoryPreferredImageOrders::class,
                // Missing the 3rd (visibility) element -- malformed, must be
                // skipped rather than crash on an undefined offset.
                static function (GetCategoryPreferredImageOrders $event): void {
                    $event->orders = [
                        ['Only Two Elements', 'name ASC'],
                        ['Real Order', 'hit DESC', true],
                    ];
                }
            );

            try {
                $orders = $this->service->getPreferredImageOrders();
            } finally {
                EventDispatcherTestFactory::get()->reset();
            }

            self::assertEquals([new ImageOrderPreference('Real Order', 'hit DESC', true)], $orders);
        }

        public function testFindCategoryIdFromPermalinksSkipsANonMatchBeforeFindingAnOlderMatch(): void
        {
            // Checked from the end first: 'no-such-permalink' misses
            // (continue), then 'old-sample-album' (the fixture's real
            // old_permalinks row, cat_id=1) matches -- also proves the is_old
            // branch touches touchOldPermalinkHit()'s hit counter.
            $idx = null;
            $catId = $this->service->findCategoryIdFromPermalinks(['old-sample-album', 'no-such-permalink'], $idx, new PermalinkRepository(EntityManagerFactory::build($this->conn)));

            self::assertSame(1, $catId);
            self::assertSame(0, $idx);

            $hit = $this->conn->createQueryBuilder()
                ->select('hit')
                ->from('old_permalinks')
                ->where("permalink = 'old-sample-album'")
                ->executeQuery()
                ->fetchOne();
            self::assertSame(43, is_numeric($hit) ? (int) $hit : null);
        }

        public function testFindCategoryIdFromPermalinksReturnsNullWhenTheSqlMatchHasNoExactStringKey(): void
        {
            // MySQL's default VARCHAR comparison ignores trailing whitespace
            // (PAD SPACE semantics, even under old_permalinks'
            // own utf8mb4_bin collation: 'old-sample-album' =
            // 'old-sample-album ' evaluates true) -- a permalink string with
            // trailing whitespace therefore SQL-matches findPermalinkMatches()'s
            // own WHERE...IN() clause (populating $permaHash with the
            // *stored*, unpadded key) but fails this method's own exact-string
            // isset() lookup against the very padded string it queried with.
            // The real, narrow gap this defensive tail return guards.
            $idx = null;
            $catId = $this->service->findCategoryIdFromPermalinks(['old-sample-album '], $idx, new PermalinkRepository(EntityManagerFactory::build($this->conn)));

            self::assertNull($catId);
            self::assertNull($idx);
        }

        public function testGetComputedCategoriesWalksUpThroughMoreThanOneAncestorLevel(): void
        {
            // `rank` is a genuine reserved word on both platforms (a bare
            // backtick is MySQL-only); visible/commentable are genuine
            // boolean columns (a bare `1` literal is rejected outright by
            // Postgres).
            $rank = $this->conn->getDatabasePlatform()
                ->quoteSingleIdentifier('rank');
            $trueLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement(
                "INSERT INTO categories (name, id_uppercat, {$rank}, status, visible, uppercats, commentable, global_rank) VALUES ('Grandchild Album', 2, 1, 'public', {$trueLiteral}, '1,2,0', {$trueLiteral}, '1.1.1')"
            );
            $grandchildId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement("UPDATE categories SET uppercats = '1,2,{$grandchildId}' WHERE id = {$grandchildId}");
            // Reuse image 1 (already directly in category 1) as a second,
            // additional link into the new grandchild -- proves the walk
            // propagates a real image count up two ancestor levels, not just
            // one.
            $this->conn->executeStatement("INSERT INTO image_category (image_id, category_id) VALUES (1, {$grandchildId})");

            try {
                $result = $this->service->getComputedCategories(1, 0, '');
                $cats = $result['categories'];

                self::assertSame(1, $cats[$grandchildId]->countImages);
                self::assertSame(3, $cats[2]->countImages); // own 2 + grandchild's 1
                self::assertSame(1, $cats[2]->countCategories);
                self::assertSame(6, $cats[1]->countImages); // own 3 + cat2's 2 + grandchild's 1
                self::assertSame(2, $cats[1]->countCategories); // cat2 + grandchild
            } finally {
                $this->conn->executeStatement("DELETE FROM categories WHERE id = {$grandchildId}");
            }
        }

        public function testGetRelatedCategoriesMenuSkipsAStaleAncestorIdInACorruptedUppercatsPath(): void
        {
            // Simulates category 2's uppercats containing a stale ancestor id
            // (999) that findCategoriesByIds() can't resolve (no such
            // category) -- the real ancestor (1) must still get its
            // count_categories incremented despite the phantom one.
            $this->conn->executeStatement("UPDATE categories SET uppercats = '1,999,2' WHERE id = 2");

            try {
                $cats = $this->service->getRelatedCategoriesMenu([4], []);

                $byId = [];
                foreach ($cats as $cat) {
                    $byId[$cat->id] = $cat;
                }

                self::assertSame(1, $byId[1]->countCategories);
            } finally {
                $this->conn->executeStatement("UPDATE categories SET uppercats = '1,2' WHERE id = 2");
            }
        }

        public function testCheckRestrictionsDeniesAccessToAForbiddenCategory(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
                'forbidden_categories' => '2,5',
            ]));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('CATEGORY_SERVICE_ACCESS_DENIED_MARKER');

            $this->service->checkRestrictions(2, new CategoryServiceFakeHtmlRendererDeniesAccess(), new CategoryServiceFakeRedirectServiceNeverCalled(), CurrentUserTestFactory::get());
        }

        public function testGetSubcatIdsWarnsAndSkipsANonNumericId(): void
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

        public function testGetRelatedCategoriesMenuWithUrlsSkipsARowWithNoCountImages(): void
        {
            // image 4 is only directly linked to category 2 -- category 1 (a
            // pure ancestor, reached only via cat2's own uppercats path) never
            // gets a 'count_images' key set. The loop's own isset() guard
            // "skips" building a 'url' for it (not building one would
            // otherwise crash on an undefined offset further down), but the
            // category itself stays in the returned list, by design:
            // menubar_related_categories.latte's own {foreach}
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

        public function testGetRelatedCategoriesMenuWithUrlsMergesCombinedCategoriesIntoTheUrl(): void
        {
            $urlService = UrlServiceTestFactory::build();
            $priorCat = [
                'id' => 99,
                'name' => 'Prior Combined Category',
                'permalink' => null,
            ];
            $pageCategory = [
                'id' => 1,
                'name' => 'Sample Album',
                'permalink' => null,
            ];

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

        public function testDeleteCategoriesDeleteOrphansModePreservesAnImageStillLinkedElsewhere(): void
        {
            $activityLogger = new CategoryServiceFakeActivityLogger();
            $urlService = UrlServiceTestFactory::build();

            $result = $this->service->createVirtualCategory('Orphan Diff Temp', $activityLogger, CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn));
            $tempIdRaw = $result->id;
            self::assertTrue(is_numeric($tempIdRaw));
            $tempId = (int) $tempIdRaw;

            // image 1 already belongs to category 1 -- linking it into the
            // temp category too makes it a real "non-orphan": deleting the
            // temp category must NOT delete it, unlike a genuinely orphaned
            // image.
            $this->conn->executeStatement("INSERT INTO image_category (image_id, category_id) VALUES (1, {$tempId})");

            $this->service->deleteCategories([$tempId], $activityLogger, $urlService, new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), EntityManagerFactory::build($this->conn), 'delete_orphans');

            self::assertNull($this->repo->findById($tempId));
            $stillLinked = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from('image_category')
                ->where('image_id = 1 AND category_id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(1, is_numeric($stillLinked) ? (int) $stillLinked : null);
        }

        public function testDeleteSiteDeletesTheSitesCategoriesAndDispatchesDeleteSiteForTheRowItself(): void
        {
            // Category can't depend on Site directly (a real deptrac
            // boundary), so the site's own `sites` row is deleted by a real
            // listener on DeleteSite instead of a direct CategoryRepository
            // call -- this registers the SAME listener shape
            // RequestBootstrap.php itself registers in production, to prove
            // the wiring (not just the individual pieces) actually works end
            // to end.
            $siteRepo = EntityManagerFactory::build($this->conn)->getRepository(SiteEntity::class);
            $siteUrl = 'p17-test-delete-site-' . bin2hex(random_bytes(4));
            $siteRepo->insert($siteUrl);
            // lastInsertId() isn't reliable straight after an ORM persist()+
            // flush() -- every other real caller in this codebase reads the
            // id back with a plain SELECT instead (see SiteRepositoryTest's
            // own identical pattern).
            $rawSiteId = $this->conn->createQueryBuilder()
                ->select('id')
                ->from('sites')
                ->where('galleries_url = :url')
                ->setParameter('url', $siteUrl)
                ->executeQuery()
                ->fetchOne();
            self::assertTrue(is_numeric($rawSiteId));
            $siteId = (int) $rawSiteId;

            $categoryId = $this->service->createVirtualCategory('Site Delete Temp', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn))->id;
            self::assertTrue(is_numeric($categoryId));
            $this->conn->executeStatement('UPDATE categories SET site_id = ? WHERE id = ?', [$siteId, $categoryId]);

            $handler = static function (DeleteSite $e) use ($siteRepo): void {
                $siteRepo->delete($e->siteId);
            };
            EventDispatcherTestFactory::get()->addTypedHandler(DeleteSite::class, $handler);

            try {
                $this->service->deleteSite($siteId, new CategoryServiceFakeActivityLogger(), UrlServiceTestFactory::build(), new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), EntityManagerFactory::build($this->conn));

                self::assertNull($this->repo->findById((int) $categoryId));
                self::assertNull($siteRepo->findGalleriesUrlById($siteId));
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(DeleteSite::class, $handler);
            }
        }

        public function testCheckCategoriesIntegrityDeletesOrphanedImageCategoryUserAccessAndGroupAccessRows(): void
        {
            // findOrphanedColumnValues()/deleteRowsWhereColumnIn() use real
            // DQL for all 3 of CategoryOrphanTarget's cases (old_permalinks'
            // own orphan check moved to OldPermalinkLookupInterface, a real
            // deptrac boundary, covered by this file's sibling old-permalinks
            // test below) -- this is the only coverage for these 3. All 3
            // target tables carry a real ON DELETE
            // CASCADE FK on the category-id column, so a genuine orphan can
            // never arise through normal writes -- disabling FK checks just
            // for these inserts reproduces the only real way this state has
            // ever existed in practice, same pattern
            // FilesystemIntegrityCheckerTest's own orphan tests use.
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->executeStatement('INSERT INTO image_category (image_id, category_id) VALUES (1, 60000)');
            $this->conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (1, 60000)');
            $this->conn->executeStatement('INSERT INTO group_access (group_id, cat_id) VALUES (1, 60000)');
            $this->enableForeignKeyChecks($this->conn);

            try {
                $this->service->checkCategoriesIntegrity(new PermalinkRepository(EntityManagerFactory::build($this->conn)));

                $orphanedImageCategoryCount = $this->conn->createQueryBuilder()
                    ->select('COUNT(*) AS c')
                    ->from('image_category')
                    ->where('image_id = 1 AND category_id = 60000')
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(0, is_numeric($orphanedImageCategoryCount) ? (int) $orphanedImageCategoryCount : null);

                $orphanedUserAccessCount = $this->conn->createQueryBuilder()
                    ->select('COUNT(*) AS c')
                    ->from('user_access')
                    ->where('user_id = 1 AND cat_id = 60000')
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(0, is_numeric($orphanedUserAccessCount) ? (int) $orphanedUserAccessCount : null);

                $orphanedGroupAccessCount = $this->conn->createQueryBuilder()
                    ->select('COUNT(*) AS c')
                    ->from('group_access')
                    ->where('group_id = 1 AND cat_id = 60000')
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(0, is_numeric($orphanedGroupAccessCount) ? (int) $orphanedGroupAccessCount : null);

                // A real, non-orphaned image_category row (fixture image 1 in
                // fixture category 1) survives untouched.
                $realImageCategoryCount = $this->conn->createQueryBuilder()
                    ->select('COUNT(*) AS c')
                    ->from('image_category')
                    ->where('image_id = 1 AND category_id = 1')
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(1, is_numeric($realImageCategoryCount) ? (int) $realImageCategoryCount : null);
            } finally {
                $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = 1 AND category_id = 60000');
                $this->conn->executeStatement('DELETE FROM user_access WHERE user_id = 1 AND cat_id = 60000');
                $this->conn->executeStatement('DELETE FROM group_access WHERE group_id = 1 AND cat_id = 60000');
            }
        }

        public function testCheckCategoriesIntegrityDeletesAnOrphanedOldPermalinksRowAndKeepsARealOne(): void
        {
            // fk_old_permalinks_cat_id now makes this orphan impossible to
            // create through normal writes, which is the point of the
            // constraint. The integrity check still has a narrow job: a dump
            // restored with referential checks disabled -- the default for
            // mysqldump output -- can reintroduce one, and that is how this
            // fixture is built here, matching the sibling
            // image_category/user_access/group_access test above.
            $isPostgres = $this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform;
            $this->conn->executeStatement($isPostgres ? "SET session_replication_role = 'replica'" : 'SET FOREIGN_KEY_CHECKS = 0');

            try {
                $this->conn->executeStatement(
                    "INSERT INTO old_permalinks (cat_id, permalink, hit) VALUES (60000, 'orphaned-permalink-test', 0)"
                );
            } finally {
                $this->conn->executeStatement($isPostgres ? "SET session_replication_role = 'origin'" : 'SET FOREIGN_KEY_CHECKS = 1');
            }

            $this->service->checkCategoriesIntegrity(new PermalinkRepository(EntityManagerFactory::build($this->conn)));

            $orphanCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from('old_permalinks')
                ->where("permalink = 'orphaned-permalink-test'")
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($orphanCount) ? (int) $orphanCount : null);

            $realCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*) AS c')
                ->from('old_permalinks')
                ->where("permalink = 'old-sample-album'")
                ->executeQuery()
                ->fetchOne();
            self::assertSame(1, is_numeric($realCount) ? (int) $realCount : null);
        }

        public function testUpdateGlobalRankReplacesAStaleUppercatsSegmentWithAnEmptyString(): void
        {
            // Simulates a category whose uppercats column still references an
            // ancestor id that no longer exists (deleted without a full
            // uppercats resync) -- catMapCallback()'s own defensive ''
            // fallback for an unmatched captured id.
            $this->conn->executeStatement("UPDATE categories SET uppercats = '1,999,2' WHERE id = 2");

            try {
                $this->service->updateGlobalRank();

                $globalRank = $this->conn->createQueryBuilder()
                    ->select('global_rank')
                    ->from('categories')
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
                $this->conn->executeStatement("UPDATE categories SET uppercats = '1,2', global_rank = '1.1' WHERE id = 2");
            }
        }

        public function testSetCatVisibleWarnsAndReturnsFalseForAnInvalidValue(): void
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

        public function testSetCatStatusWarnsAndReturnsFalseForAnInvalidValue(): void
        {
            $captured = null;
            set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
                $captured = $errstr;
                return true;
            });
            try {
                $result = $this->service->setCatStatus([1], 'archived', EntityManagerFactory::build($this->conn));
            } finally {
                restore_error_handler();
            }

            self::assertFalse($result);
            self::assertSame('setCatStatus invalid param archived', $captured);
        }

        public function testSetCatStatusPublicMakesParentCategoriesPublicToo(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private'");

            $this->service->setCatStatus([2], 'public', EntityManagerFactory::build($this->conn));

            self::assertSame('public', $this->repo->findCategoryStatus(1));
            self::assertSame('public', $this->repo->findCategoryStatus(2));
        }

        public function testSetCatStatusPrivateUsesThePrivateParentAsThePermissionReference(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (3, 2)');

            try {
                $this->service->setCatStatus([2], 'private', EntityManagerFactory::build($this->conn));

                // category 1 (the reference, since it's already private)
                // grants no direct user access at all -- the
                // inconsistent-access sweep must fall back to the sentinel
                // (-1) keep-list and remove category 2's own now-inconsistent
                // user_access row, proving the *parent* was used as the
                // reference rather than category 2 itself (which would have
                // kept its own row as "consistent with itself").
                $remaining = $this->conn->createQueryBuilder()
                    ->select('COUNT(*) AS c')
                    ->from('user_access')
                    ->where('cat_id = 2')
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(0, is_numeric($remaining) ? (int) $remaining : null);
            } finally {
                $this->conn->executeStatement('DELETE FROM user_access WHERE cat_id IN (1, 2)');
            }
        }

        public function testSetCatStatusPrivateRemovesInconsistentGroupAccessToo(): void
        {
            // deleteInconsistentAccess() handles CategoryAccessTarget::GroupAccess
            // via real DQL, alongside UserAccess -- same code path as the
            // sibling test above, which only exercises UserAccess
            // (setCatStatus()'s own foreach(CategoryAccessTarget::cases())
            // loop runs both every time, just without a group_access-specific
            // assertion). A throwaway new group, not a real fixture one -- the
            // fixture's own groups 1-3 already have real access to category 1
            // (the "reference"), which would make it the *consistent* case
            // this test isn't trying to cover.
            $groupsTable = $this->conn->getDatabasePlatform()
                ->quoteSingleIdentifier('groups');
            $this->conn->executeStatement("INSERT INTO {$groupsTable} (name) VALUES ('zzz-16i-probe-group')");
            $groupId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('INSERT INTO group_access (group_id, cat_id) VALUES (?, 2)', [$groupId]);

            try {
                $this->service->setCatStatus([2], 'private', EntityManagerFactory::build($this->conn));

                $remaining = $this->conn->createQueryBuilder()
                    ->select('COUNT(*) AS c')
                    ->from('group_access')
                    ->where('cat_id = 2 AND group_id = :groupId')
                    ->setParameter('groupId', $groupId)
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(0, is_numeric($remaining) ? (int) $remaining : null);
            } finally {
                $this->conn->executeStatement('DELETE FROM group_access WHERE group_id = ?', [$groupId]);
                $this->conn->executeStatement("DELETE FROM {$groupsTable} WHERE id = ?", [$groupId]);
            }
        }

        public function testGetCategoryRepresentantPropertiesThrowsForAMissingImage(): void
        {
            $this->expectException(Exception::class);
            $this->expectExceptionMessageIsOrContains('getCategoryRepresentantProperties(): image 999999 does not exist (stale representative_picture_id?)');

            $this->service->getCategoryRepresentantProperties(999999, UrlServiceTestFactory::build(), EntityManagerFactory::build($this->conn));
        }

        public function testGetCategoryRepresentantPropertiesReturnsAThumbUrlWhenSizeIsNull(): void
        {
            $urlService = UrlServiceTestFactory::build();

            $props = $this->service->getCategoryRepresentantProperties(1, $urlService, EntityManagerFactory::build($this->conn));

            self::assertSame($urlService->getRootUrl() . 'admin.php?page=photo-1', $props->url);
        }

        public function testUpdatePathRewritesImagePathsForStorageLinkedCategories(): void
        {
            $this->conn->executeStatement("UPDATE categories SET dir = 'sample-album', site_id = 1 WHERE id = 1");
            $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

            try {
                $this->service->updatePath(EntityManagerFactory::build($this->conn)->getRepository(SiteEntity::class));

                $path = $this->conn->createQueryBuilder()
                    ->select('path')
                    ->from('images')
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
                $this->conn->executeStatement('UPDATE categories SET dir = NULL, site_id = NULL WHERE id = 1');
                $realHash = $this->dbDriver === 'pgsql' ? '2e7e2251' : '2e7e3a53';
                $this->conn->executeStatement(
                    "UPDATE images SET storage_category_id = NULL, path = 'upload/2026/08/01/20260801000000-{$realHash}.jpg' WHERE id = 1"
                );
            }
        }

        public function testMoveCategoriesRejectsMovingACategoryIntoItsOwnSubAlbum(): void
        {
            PageStateTestFactory::get()->reset();
            $activityLogger = new CategoryServiceFakeActivityLogger();

            $this->service->moveCategories([1], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($this->conn), 2);

            self::assertContains('You cannot move an album in its own sub album', PageStateTestFactory::get()->errors);
            // the move must not have actually happened.
            $idUppercat = $this->conn->createQueryBuilder()
                ->select('id_uppercat')
                ->from('categories')
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertNull($idUppercat);
        }

        public function testCreateVirtualCategoryReturnsAnErrorWhenTheParentDoesNotExist(): void
        {
            $result = $this->service->createVirtualCategory('Orphan Parent Test', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn), 999999);

            self::assertSame('The parent album does not exist', $result->error);
        }

        public function testCreateVirtualCategoryInheritsInvisibilityFromAnInvisibleParent(): void
        {
            // visible is a genuine boolean column -- bare `0`/`1` literals in
            // the SQL text (unlike a bound parameter, which the driver
            // coerces implicitly) are rejected outright by Postgres.
            $falseLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
            $trueLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement("UPDATE categories SET visible = {$falseLiteral} WHERE id = 1");

            try {
                $result = $this->service->createVirtualCategory('Invisible Child Test', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn), 1);
                $newIdRaw = $result->id;
                self::assertTrue(is_numeric($newIdRaw));
                $newId = (int) $newIdRaw;

                $visible = $this->conn->createQueryBuilder()
                    ->select('visible')
                    ->from('categories')
                    ->where('id = ' . $newId)
                    ->executeQuery()
                    ->fetchOne();
                // A native PHP bool on Postgres, numeric 1/0 on MySQL.
                self::assertSame(0, (int) (bool) $visible);

                $this->conn->executeStatement('DELETE FROM categories WHERE id = ' . $newId);
            } finally {
                $this->conn->executeStatement("UPDATE categories SET visible = {$trueLiteral} WHERE id = 1");
            }
        }

        public function testCreateVirtualCategoryWithInheritPropagatesTheParentsGroupsAndUsers(): void
        {
            $this->conn->executeStatement("UPDATE categories SET status = 'private' WHERE id = 1");
            $this->conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (3, 1)');

            try {
                $result = $this->service->createVirtualCategory('Inherited Child Test', new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn), 1, [
                    'inherit' => true,
                ]);
                $newIdRaw = $result->id;
                self::assertTrue(is_numeric($newIdRaw));
                $newId = (int) $newIdRaw;

                // category 1's own fixture groups (1, 2, 3) must all have been
                // copied onto the new child.
                $groupIds = $this->repo->findAccessGroupIds(CategoryId::from($newId));
                sort($groupIds);
                self::assertSame([1, 2, 3], $groupIds);

                // the user_access row just added to category 1 must also have
                // been copied.
                self::assertSame([3], $this->repo->findAccessUserIds(CategoryId::from($newId)));

                $this->conn->executeStatement('DELETE FROM categories WHERE id = ' . $newId);
            } finally {
                $this->conn->executeStatement('DELETE FROM user_access WHERE cat_id = 1');
            }
        }

        public function testGetImageIdsForCategoriesReturnsEmptyForNoCategoryIds(): void
        {
            // the empty-input early return -- never reaches the repository or
            // the permission-condition SQL building below it.
            self::assertSame([], $this->service->getImageIdsForCategories([]));
        }

        public function testUpdateCategoryWithAScalarIdClearsAStaleRepresentativePictureId(): void
        {
            // updateCategory()'s own docblock: legacy
            // ws_functions/pwg.images.php callers passed a raw, never-int-cast
            // scalar category id, not an array -- calling with a bare int here
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
            $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 999999 WHERE id = 1');
            $this->enableForeignKeyChecks($this->conn);

            try {
                $result = $this->service->updateCategory(1);

                self::assertNull($result);

                $repId = $this->conn->createQueryBuilder()
                    ->select('representative_picture_id')
                    ->from('categories')
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
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
            }
        }

        public function testUpdateCategoryWithTheDefaultAllScopesToEveryCategory(): void
        {
            // Proves the `$ids === 'all'` branch really builds a `1=1`
            // WHERE (matching every category), not just a single one --
            // both categories 1 and 2 get a dangling representative_picture_id,
            // and 'all' must clear (then re-pick, allowRandomRepresentative
            // defaults to false) both of them, not just whichever one a
            // narrower scalar/array branch would have hit.
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 999999 WHERE id IN (1, 2)');
            $this->enableForeignKeyChecks($this->conn);

            try {
                $result = $this->service->updateCategory('all');

                self::assertNull($result);

                $repIds = $this->conn->fetchFirstColumn(
                    'SELECT representative_picture_id FROM categories WHERE id IN (1, 2) ORDER BY id'
                );
                foreach ($repIds as $repId) {
                    self::assertNotSame(999999, $repId);
                }
            } finally {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 4 WHERE id = 2');
            }
        }

        public function testUpdateCategoryWithAnEmptyArrayReturnsFalse(): void
        {
            // The array branch's own `count($ids) === 0` early-return guard --
            // never reaches the wrong-representative sweep below it.
            self::assertFalse($this->service->updateCategory([]));
        }

        public function testUpdateCategoryWithANonIntegerIdStringIsIntvalCastBeforeBinding(): void
        {
            // A real caller (ws_functions/pwg.images.php, per this method's
            // own docblock) never sends a decimal string, but '2.9' proves
            // every array element is actually intval()-cast to a clean int
            // before it reaches the query -- Doctrine\DBAL\Driver\PgSQL\Statement::
            // bindValue() passes ArrayParameterType::INTEGER values straight
            // through uncoerced to pg_send_execute(), so a raw '2.9' text
            // value bound against an integer column would fail outright
            // rather than scoping cleanly to category 2.
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 999999 WHERE id = 2');
            $this->enableForeignKeyChecks($this->conn);

            try {
                $result = $this->service->updateCategory(['2.9']);

                self::assertNull($result);

                $repId = $this->conn->createQueryBuilder()
                    ->select('representative_picture_id')
                    ->from('categories')
                    ->where('id = 2')
                    ->executeQuery()
                    ->fetchOne();
                self::assertNotSame(999999, is_numeric($repId) ? (int) $repId : null);
            } finally {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 4 WHERE id = 2');
            }
        }

        public function testSetCatVisibleWithUnlockChildUnlocksDescendantCategoriesToo(): void
        {
            // visible is a genuine boolean column -- bare `0`/`1` literals in
            // the SQL text (unlike a bound parameter, which the driver
            // coerces implicitly) are rejected outright by Postgres.
            $falseLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
            $trueLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement("UPDATE categories SET visible = {$falseLiteral} WHERE id = 2");

            try {
                // unlockChild=true merges getSubcatIds([1]) (== [1, 2]) into
                // the ancestor-only list getUppercatIds([1]) would otherwise
                // produce (just [1], category 1 has no parent of its own) --
                // category 2 only ends up unlocked because of that merge.
                $this->service->setCatVisible([1], true, true);

                $visible = $this->conn->createQueryBuilder()
                    ->select('visible')
                    ->from('categories')
                    ->where('id = 2')
                    ->executeQuery()
                    ->fetchOne();
                // A native PHP bool on Postgres, numeric 1/0 on MySQL.
                self::assertSame(1, (int) (bool) $visible);
            } finally {
                $this->conn->executeStatement("UPDATE categories SET visible = {$trueLiteral} WHERE id = 2");
            }
        }

        public function testMoveCategoriesReturnsEarlyForNoCategoryIds(): void
        {
            PageStateTestFactory::get()->reset();
            $activityLogger = new CategoryServiceFakeActivityLogger();

            $this->service->moveCategories([], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($this->conn));

            // the count()===0 early return skips updateCategoryParent(),
            // updateUppercats()/updateGlobalRank(), the PageState::addInfo()
            // summary and the activity-log record below it entirely -- an
            // empty $categoryIds reaching any of that would still "succeed"
            // silently, so the only observable proof of the early return is
            // that none of it ran.
            self::assertSame([], $activityLogger->calls);
            self::assertSame([], PageStateTestFactory::get()->infos);
        }

        public function testMoveCategoriesToRootSetsParentStatusPublic(): void
        {
            PageStateTestFactory::get()->reset();
            $activityLogger = new CategoryServiceFakeActivityLogger();

            try {
                // default $newParent = -1 -> $newParentSql = 'NULL' -> moving
                // to root, the branch that hardcodes $parentStatus = 'public'
                // rather than looking an actual parent category up.
                $this->service->moveCategories([2], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($this->conn));

                $idUppercat = $this->conn->createQueryBuilder()
                    ->select('id_uppercat')
                    ->from('categories')
                    ->where('id = 2')
                    ->executeQuery()
                    ->fetchOne();
                self::assertNull($idUppercat);
                self::assertSame('public', $this->repo->findCategoryStatus(2));
            } finally {
                $this->conn->executeStatement(
                    "UPDATE categories SET id_uppercat = 1, uppercats = '1,2', global_rank = '1.1' WHERE id = 2"
                );
            }
        }

        public function testMoveCategoriesIntoAPrivateParentCascadesPrivateStatus(): void
        {
            PageStateTestFactory::get()->reset();
            $activityLogger = new CategoryServiceFakeActivityLogger();

            $privateParent = $this->service->createVirtualCategory(
                'ct_move_private_parent_' . uniqid(),
                $activityLogger,
                CurrentUserTestFactory::get(),
                EntityManagerFactory::build($this->conn),
                null,
                [
                    'status' => 'private',
                ]
            );
            $privateParentIdRaw = $privateParent->id;
            self::assertTrue(is_numeric($privateParentIdRaw));
            $privateParentId = (int) $privateParentIdRaw;

            try {
                // moving into a real, non-root parent (status looked up via
                // findCategoryStatus()) that happens to be private -- the
                // setCatStatus(..., 'private') cascade onto the moved
                // categories themselves only fires on this branch.
                $this->service->moveCategories([2], $activityLogger, PageStateTestFactory::get(), EntityManagerFactory::build($this->conn), $privateParentId);

                self::assertSame('private', $this->repo->findCategoryStatus(2));
            } finally {
                $this->conn->executeStatement(
                    "UPDATE categories SET id_uppercat = 1, uppercats = '1,2', global_rank = '1.1', status = 'public' WHERE id = 2"
                );
                $this->conn->executeStatement('DELETE FROM categories WHERE id = ' . $privateParentId);
            }
        }

        public function testCreateVirtualCategoryWithLastPositionRanksAfterExistingSiblings(): void
        {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->newcatDefaultPosition = 'last';

            try {
                $result = $this->service->createVirtualCategory('ct_last_position_' . uniqid(), new CategoryServiceFakeActivityLogger(), CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn));
                $newIdRaw = $result->id;
                self::assertTrue(is_numeric($newIdRaw));
                $newId = (int) $newIdRaw;

                $rank = $this->conn->createQueryBuilder()
                    ->select($this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank'))
                    ->from('categories')
                    ->where('id = ' . $newId)
                    ->executeQuery()
                    ->fetchOne();
                // category 1 is the only other root-level album, at rank 1 --
                // newcatDefaultPosition=last must place the new sibling right
                // after it rather than at the default rank 0.
                self::assertSame(2, is_numeric($rank) ? (int) $rank : null);

                $this->conn->executeStatement('DELETE FROM categories WHERE id = ' . $newId);
            } finally {
                $currentConfig->newcatDefaultPosition = 'first';
            }
        }

        public function testSetRepresentativeImageForCategoriesUpdatesBothCategories(): void
        {
            try {
                $this->service->setRepresentativeImageForCategories([1, 2], 3);

                $repIds = $this->conn->fetchFirstColumn(
                    'SELECT representative_picture_id FROM categories WHERE id IN (1, 2) ORDER BY id'
                );
                self::assertSame([3, 3], $repIds);
            } finally {
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 1 WHERE id = 1');
                $this->conn->executeStatement('UPDATE categories SET representative_picture_id = 4 WHERE id = 2');
            }
        }

        public function testGetImageIdsOutsideCategoriesExcludesTheGivenCategory(): void
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
