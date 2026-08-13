<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Error;
    use Exception;
    use LogicException;
    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Bootstrap\RedirectService;
    use Piwigo\Cache\CachePools;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\DeploymentPolicy;
    use Piwigo\Config\FilterViewsSelection;
    use Piwigo\Core\CurrentLogger;
    use Piwigo\Core\FilterState;
    use Piwigo\Core\HtmlRenderingInterface;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\ProcessCache;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Event\Search\QsearchGetScopes;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Permission\SqlCondition;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Search\Event\QsearchGetImagesSqlScopes;
    use Piwigo\Search\Event\QsearchResults;
    use Piwigo\Search\QExpression;
    use Piwigo\Search\QResults;
    use Piwigo\Search\QsearchClause;
    use Piwigo\Search\QSearchScope;
    use Piwigo\Search\QSingleToken;
    use Piwigo\Search\SearchRepository;
    use Piwigo\Search\SearchService;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\TranslatorTestFactory;
    use Piwigo\Users\User;
    use Piwigo\Users\UserService;
    use RuntimeException;

    /**
     * Test-only HtmlRenderingInterface: turns the `never`-typed
     * badRequest()/fatalError() calls into a catchable exception instead of a
     * real header()+exit() redirect, so the "invalid identifier"/"not found"
     * gates on SearchService's own $htmlRenderer can be observed from a test.
     * Every other method throws too -- none of the scenarios exercised through
     * this fake ever reach tag/category matching (which is the only other
     * HtmlRenderingInterface method SearchService itself calls,
     * tagAlphaCompare()).
     */
    final class FatalSignalHtmlRenderer implements HtmlRenderingInterface
    {
        /**
         * @param array<int, array<string, mixed>> $catInformations
         */
        #[Override]
        public function getCatDisplayName(array $catInformations, ?string $url = ''): string
        {
            throw new LogicException('not implemented in this fake');
        }

        #[Override]
        public function getCatDisplayNameCache(
            string $uppercats,
            ?string $url = '',
            bool $singleLink = false,
            ?string $linkClass = null,
            ?string $authKey = null,
        ): string {
            throw new LogicException('not implemented in this fake');
        }

        /**
         * @param array<string, mixed> $a
         * @param array<string, mixed> $b
         */
        #[Override]
        public function nameCompare(array $a, array $b): int
        {
            throw new LogicException('not implemented in this fake');
        }

        /**
         * @param array<string, mixed> $a
         * @param array<string, mixed> $b
         */
        #[Override]
        public function tagAlphaCompare(array $a, array $b): int
        {
            throw new LogicException('not implemented in this fake');
        }

        #[Override]
        public function accessDenied(RedirectServiceInterface $redirectService): never
        {
            throw new RuntimeException('accessDenied called');
        }

        #[Override]
        public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
        {
            throw new RuntimeException('badRequest: ' . $msg);
        }

        #[Override]
        public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
        {
            throw new RuntimeException('pageNotFound: ' . ($msg ?? ''));
        }

        #[Override]
        public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
        {
            throw new RuntimeException('fatalError: ' . $msg);
        }

        /**
         * @param list<array<string, mixed>> $tags
         */
        #[Override]
        public function getTagsContentTitle(array $tags): string
        {
            throw new LogicException('not implemented in this fake');
        }

        /**
         * @param array<string, mixed>|null $category
         * @param list<array<string, mixed>> $combinedCategories
         */
        #[Override]
        public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
        {
            throw new LogicException('not implemented in this fake');
        }

        #[Override]
        public function setStatusHeader(int $code, string $text = ''): void
        {
            throw new LogicException('not implemented in this fake');
        }

        /**
         * @param array<string, mixed> $info
         */
        #[Override]
        public function renderElementName(array $info): string
        {
            throw new LogicException('not implemented in this fake');
        }

        /**
         * @param array<string, mixed> $info
         */
        #[Override]
        public function renderElementDescription(array $info, string $param = ''): string
        {
            throw new LogicException('not implemented in this fake');
        }

        /**
         * @param array<string, mixed> $info
         */
        #[Override]
        public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
        {
            throw new LogicException('not implemented in this fake');
        }
    }

    /**
     * A real class that deliberately does NOT implement InflectorInterface --
     * class_alias()'d onto a fake 'Piwigo\Search\Inflector\InflectorZz' FQCN
     * by the Inflector-guard test below, standing in for exactly the real-world
     * scenario that guard defends against (a 3rd-party language pack shipping
     * a broken InflectorXx.php for its own 2-letter code). Every real
     * Inflector* class under src/Piwigo/Search/Inflector (currently only 'en'
     * and 'fr') correctly implements the interface, so there is no way to
     * reach this branch through any real language code -- class_alias() is a
     * genuine PHP class-resolution mechanism, not a mock of SearchService
     * itself.
     */
    final class SearchServiceTestNotAnInflector {}

    /**
     * Same fixture shape as CategoryRepositoryTest/SearchRepositoryTest:
     * images 1-5 (1,2,3 in category 1, 4,5 in category 2, all 200x150,
     * fixture-photo-N.jpg / "Photo N"), tags 1 "nature", 2 "travel", 3
     * "family" (image 1 has all 3 tags, images 2/3 have tag 1 only).
     *
     * qsearchGetTags()/qsearchGetCategories()'s own `! is_numeric($tag['id'])`/
     * `! is_numeric($cat['id'])` `continue` branches are NOT chased here for
     * the same reason as SearchRepositoryTest's own documented residual: `id`
     * is always a native-int NOT NULL primary key under this project's DBAL
     * driver, so those branches are unreachable through any real fetched row.
     * qsearchGetTextTokenSearchSql()'s `preg_split() failed` throw and
     * splitAllwords()'s own `preg_split() failed` throw ARE exercised below
     * (not left as documented-dead-code like the `is_numeric()` pair above):
     * no *crafted input string* can make either fail, but a real PCRE
     * resource-exhaustion error is still reachable in-process via
     * `ini_set('pcre.backtrack_limit', '0')`, the exact same technique
     * MetadataServiceTest::test_parse_svg_dimensions_returns_null_when_preg_replace_hits_the_backtrack_limit()
     * already uses, working even for a plain, non-catastrophic
     * pattern -- see the 2 tests near the end of this file.
     * SearchService::getValidatedSearchInfo()/getValidatedSearchArray()'s
     * `fatalError()`/`badRequest()` gates ARE exercised below, via a
     * dedicated {@see FatalSignalHtmlRenderer} test double (built with
     * {@see makeServiceWithRenderer()}) instead of the real HtmlService, since
     * those methods are typed `: never` and would otherwise attempt a real
     * header()+exit() redirect.
     */
    final class SearchServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private SearchService $service;

        private SearchRepository $repo;

        private Connection $conn;

        private function filterState(): FilterState
        {
            $filterState = Kernel::container()->get(FilterState::class);
            if (! $filterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }

            return $filterState;
        }

        /**
         * SearchService's own userService()/tagService() lazy-default helpers
         * both resolve ProcessCache via this same container, so this resolves
         * the same shared instance -- forget() here is actually observed by
         * $this->service internally.
         */
        private function processCache(): ProcessCache
        {
            $processCache = Kernel::container()->get(ProcessCache::class);
            if (! $processCache instanceof ProcessCache) {
                throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
            }

            return $processCache;
        }

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
            // UserService's own ProcessCache usage (reached via this test's own
            // processCache() helper below) needs a real, booted container.
            Kernel::boot();

            $this->conn = DbConnection::build();
            $this->repo = new SearchRepository(EntityManagerFactory::build($this->conn));

            CurrentUserTestFactory::get()->set(User::fromUserArray(self::realisticUserGlobal()));
            $currentConfig->defaultFiltersViews = null;
            $currentConfig->filtersViews = FilterViewsSelection::fromArray([
                'expert' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'words' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'author' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'file_type' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'added_by' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'album' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'post_date' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'creation_date' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'ratio' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'rating' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'file_size' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'height' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'width' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
                'tags' => [
                    'access' => 'everybody',
                    'default' => false,
                ],
            ]);
            $currentConfig->orderBy = 'ORDER BY id ASC';
            $currentConfig->calendarDatefield = 'date_creation';
            $currentConfig->quickSearchIncludeSubAlbums = false;
            $currentConfig->rateEnabled = true;

            $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
            $this->service = new SearchService(
                $accessLevelChecker,
                $this->repo,
                new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $this->filterState(), $accessLevelChecker),
                new CategoryService(
                    LangTestFactory::get(),
                    new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()),
                    new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $this->filterState(), $accessLevelChecker),
                    CurrentConfigTestFactory::get(),
                    new EventDispatcher(),
                    TranslatorTestFactory::get(),
                    $accessLevelChecker
                ),
                HtmlServiceTestFactory::build(),
                new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()),
                new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
                EventDispatcherTestFactory::get(),
                CurrentUserTestFactory::get(),
                LangTestFactory::get(),
                CurrentConfigTestFactory::get(),
                new CurrentLogger(),
                new DeploymentPolicy(),
                CurrentPathsTestFactory::get(),
            );
        }

        #[Override]
        protected function tearDown(): void
        {
            CachePools::searchResults()->clear();
            Kernel::reset();

            parent::tearDown();
        }

        private function userService(): UserService
        {
            // Kernel is already booted in setUp() above -- resolve the same
            // container-shared instance a real request would get, matching
            // RedirectService's own real production callers.
            $userService = Kernel::container()->get(UserService::class);
            if (! $userService instanceof UserService) {
                throw new LogicException('Container returned an unexpected type for ' . UserService::class);
            }

            return $userService;
        }

        /**
         * Same dependency graph as setUp()'s own $this->service, but with a
         * caller-supplied $repo (for forcing an internal collision retry) and/or
         * HtmlRenderingInterface (for observing the fatalError()/badRequest()
         * gates without a real header()+exit() redirect).
         */
        private function makeService(SearchRepository $repo, HtmlRenderingInterface $htmlRenderer): SearchService
        {
            $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());

            return new SearchService(
                $accessLevelChecker,
                $repo,
                new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $this->filterState(), $accessLevelChecker),
                new CategoryService(
                    LangTestFactory::get(),
                    new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()),
                    new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $this->filterState(), $accessLevelChecker),
                    CurrentConfigTestFactory::get(),
                    new EventDispatcher(),
                    TranslatorTestFactory::get(),
                    $accessLevelChecker
                ),
                $htmlRenderer,
                new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()),
                new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
                EventDispatcherTestFactory::get(),
                CurrentUserTestFactory::get(),
                LangTestFactory::get(),
                CurrentConfigTestFactory::get(),
                new CurrentLogger(),
                new DeploymentPolicy(),
                CurrentPathsTestFactory::get(),
            );
        }

        /**
         * `NOW() - INTERVAL n UNIT` -- MySQL's own bare-token interval syntax,
         * not portable: PostgreSQL requires the interval as a quoted string
         * (`INTERVAL 'n unit'`), rejecting the bare-token form outright with a
         * syntax error. Used by the date_posted/date_created preset tests
         * below to backdate fixture rows relative to the DB server's real
         * wall clock.
         */
        private function nowMinusInterval(int $amount, string $unit): string
        {
            return $this->dbDriver === 'pgsql'
                ? "NOW() - INTERVAL '{$amount} {$unit}'"
                : "NOW() - INTERVAL {$amount} {$unit}";
        }

        private function makeServiceWithRenderer(HtmlRenderingInterface $htmlRenderer): SearchService
        {
            return $this->makeService($this->repo, $htmlRenderer);
        }

        /**
         * @return array<string, mixed>
         */
        private static function realisticUserGlobal(): array
        {
            // Matches getuserdata()'s own guaranteed shape -- an incomplete
            // fixture (e.g. missing 'level') lets getSqlConditionFandF()'s
            // forbidden_images fallthrough build a malformed 'level <=' fragment
            // with no right-hand value, same gotcha documented in
            // CategoryServiceTest.
            return [
                'id' => 1,
                'status' => 'normal',
                'forbidden_categories' => '0',
                'level' => '0',
                'image_access_type' => 'NOT IN',
                'image_access_list' => '',
                'cache_update_time' => '2026-07-12 00:00:00',
            ];
        }

        public function testGetSearchIdPatternRecognizesAUuid(): void
        {
            self::assertSame('search_uuid = ?', SearchService::getSearchIdPattern('psk-20260712-abcdefghij'));
        }

        public function testGetSearchIdPatternRecognizesANumericId(): void
        {
            self::assertSame('id = ?', SearchService::getSearchIdPattern(42));
        }

        public function testGetSearchIdPatternRejectsGarbage(): void
        {
            self::assertNull(SearchService::getSearchIdPattern('not-a-valid-identifier'));
        }

        public function testGetSearchInfoReturnsTheStoredRow(): void
        {
            $this->repo->insertSavedSearch([
                'q' => 'nature',
            ], '2026-07-12 00:00:00', 1, 'psk-20260712-infotest01', null);

            $info = $this->service->getSearchInfo('psk-20260712-infotest01');

            self::assertNotNull($info);
            self::assertSame('psk-20260712-infotest01', $info->searchUuid);
        }

        public function testGetSearchInfoReturnsNullForAnInvalidIdentifier(): void
        {
            self::assertNull($this->service->getSearchInfo('garbage'));
        }

        public function testGetSearchArrayRoundTripsTheJsonEncodedRules(): void
        {
            $rules = [
                'q' => 'nature',
                'fields' => [
                    'allwords' => [
                        'words' => ['nature'],
                    ],
                ],
            ];
            $this->repo->insertSavedSearch($rules, '2026-07-12 00:00:00', 1, 'psk-20260712-arraytest0', null);

            $decoded = $this->service->getSearchArray('psk-20260712-arraytest0');

            self::assertSame($rules, $decoded);
        }

        public function testGetSearchArrayReturnsFalseForAMissingSearch(): void
        {
            self::assertFalse($this->service->getSearchArray('psk-20260712-nosuchuid0'));
        }

        public function testGetAvailableSearchUuidMatchesTheExpectedShape(): void
        {
            $uuid = $this->service->getAvailableSearchUuid();

            // Case-insensitive, matching SearchService::getSearchIdPattern()'s
            // own regex -- generate_key()'s base64-derived charset includes
            // uppercase letters.
            self::assertMatchesRegularExpression('/^psk-\d{8}-[a-z0-9]{10}$/i', $uuid);
        }

        public function testGetAvailableSearchUuidSkipsACollidingUuid(): void
        {
            $uuid = $this->service->getAvailableSearchUuid();
            $this->repo->insertSavedSearch([
                'q' => 'x',
            ], '2026-07-12 00:00:00', null, $uuid, null);

            $next = $this->service->getAvailableSearchUuid();

            self::assertNotSame($uuid, $next);
            self::assertSame(0, $this->repo->countSavedSearchByUuid($next));
        }

        public function testSplitAllwordsSplitsOnWhitespace(): void
        {
            self::assertSame(['nature', 'travel'], SearchService::splitAllwords('nature travel'));
        }

        public function testSplitAllwordsReturnsNullForBlankInput(): void
        {
            self::assertNull(SearchService::splitAllwords('   '));
        }

        public function testSplitAllwordsThrowsWhenPregSplitHitsTheBacktrackLimit(): void
        {
            $originalLimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', '0');

            try {
                $this->expectException(Exception::class);
                $this->expectExceptionMessageIsOrContains('splitAllwords(): preg_split() failed');

                SearchService::splitAllwords('nature travel');
            } finally {
                ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
            }
        }

        public function testQsearchGetTextTokenSearchSqlIsInjectionSafe(): void
        {
            // A term with a single quote must not break out of the generated
            // REGEXP/MATCH clauses -- proven by actually executing them (with
            // their own bound values) against the real fixture DB, not just
            // eyeballing the SQL.
            $token = new QSingleToken("nature's", 0, null);

            // ['name', 'comment'] matches the images_ft_name_comment FULLTEXT
            // index's exact column list (MySQL requires an exact match to use
            // MATCH() against it) -- the same pair every real call site passes.
            [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

            self::assertNotSame([], $clauses);

            $count = $this->conn->executeQuery(
                'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
                $values
            )->fetchOne();

            self::assertSame(0, is_numeric($count) ? (int) $count : null);
        }

        public function testGetRegularSearchResultsFiltersByWidthAndHeight(): void
        {
            $search = [
                'fields' => [
                    'width_min' => 100,
                    'width_max' => 300,
                    'height_min' => 100,
                    'height_max' => 300,
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByRatio(): void
        {
            // every fixture image is 200x150 -- ratio 1.333, the "Landscape"
            // bucket (1.05 < ratio < 2).
            $search = [
                'fields' => [
                    'ratios' => ['Landscape'],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetRegularSearchResultsFiltersByCategory(): void
        {
            $search = [
                'fields' => [
                    'cat' => [
                        'words' => [1],
                        'sub_inc' => false,
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        }

        public function testGetRegularSearchResultsFiltersByTags(): void
        {
            $search = [
                'fields' => [
                    'tags' => [
                        'words' => [1],
                        'mode' => 'AND',
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        }

        public function testGetRegularSearchResultsCombinesTwoFiltersViaIntersection(): void
        {
            // cat=1 -> {1,2,3}; tags=1 -> {1,2,3} -- intersection is still
            // {1,2,3}, proving the multi-filter array_intersect() path (not
            // just the single-filter reset() shortcut) produces a valid
            // list<int>.
            $search = [
                'fields' => [
                    'cat' => [
                        'words' => [1],
                        'sub_inc' => false,
                    ],
                    'tags' => [
                        'words' => [1],
                        'mode' => 'AND',
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        }

        public function testGetRegularSearchResultsCustomSearchClause(): void
        {
            $results = $this->service->getRegularSearchResults([], new SqlCondition('i.id = 1'));

            self::assertSame([1], $results['items']);
        }

        public function testGetRegularSearchResultsReturnsEmptyForNoFilters(): void
        {
            $results = $this->service->getRegularSearchResults([]);

            self::assertSame([], $results['items']);
            self::assertFalse($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByExpertString(): void
        {
            // The 'expert' criterion delegates to getQuickSearchResults() itself
            // -- "family" resolves via the tag-name quick-search path to image 1.
            $search = [
                'fields' => [
                    'expert' => [
                        'string' => 'family',
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            self::assertSame([1], $results['items']);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByAuthorFieldWithNoMatch(): void
        {
            // Every fixture image has a NULL author -- proves the criterion
            // executes end to end (well-formed empty result), not that it
            // matches anything.
            $search = [
                'fields' => [
                    'author' => [
                        'words' => ['Someone'],
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            self::assertSame([], $results['items']);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByFiletypes(): void
        {
            // every fixture image's path ends in .jpg.
            $search = [
                'fields' => [
                    'filetypes' => ['jpg'],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByAddedBy(): void
        {
            // every fixture image has added_by = 1.
            $search = [
                'fields' => [
                    'added_by' => [1],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByDatePostedPreset(): void
        {
            // NOW()-relative rather than a hardcoded literal, so this stays
            // correct regardless of the real wall-clock date the suite runs on.
            $this->conn->executeStatement(
                'UPDATE images SET date_available = ' . $this->nowMinusInterval(1, 'HOUR') . ' WHERE id IN (1, 2)'
            );
            $this->conn->executeStatement(
                'UPDATE images SET date_available = ' . $this->nowMinusInterval(30, 'HOUR') . ' WHERE id IN (3, 4, 5)'
            );

            try {
                $search = [
                    'fields' => [
                        'date_posted' => [
                            'preset' => '24h',
                        ],
                    ],
                ];
                $results = $this->service->getRegularSearchResults($search);

                $items = $results['items'];
                sort($items);
                self::assertSame([1, 2], $items);
                self::assertTrue($results['search_details']['has_filters_filled']);
            } finally {
                $this->conn->executeStatement(
                    "UPDATE images SET date_available = '2026-08-01 00:00:00' WHERE id IN (1,2,3,4,5)"
                );
            }
        }

        public function testGetRegularSearchResultsFiltersByDateCreatedPreset(): void
        {
            $this->conn->executeStatement(
                'UPDATE images SET date_creation = ' . $this->nowMinusInterval(1, 'DAY') . ' WHERE id IN (1, 2, 3)'
            );
            $this->conn->executeStatement(
                'UPDATE images SET date_creation = ' . $this->nowMinusInterval(60, 'DAY') . ' WHERE id IN (4, 5)'
            );

            try {
                $search = [
                    'fields' => [
                        'date_created' => [
                            'preset' => '7d',
                        ],
                    ],
                ];
                $results = $this->service->getRegularSearchResults($search);

                $items = $results['items'];
                sort($items);
                self::assertSame([1, 2, 3], $items);
            } finally {
                $this->conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
            }
        }

        public function testGetRegularSearchResultsDateCreatedCustomRange(): void
        {
            $this->conn->executeStatement("UPDATE images SET date_creation = '2024-03-15 12:00:00' WHERE id = 1");
            $this->conn->executeStatement("UPDATE images SET date_creation = '2025-01-01 00:00:00' WHERE id = 2");

            try {
                // Mixes a 'y'/'m'/'d'-prefixed string entry of each shape plus a
                // non-string (int) entry that matches none of them -- exercises
                // dateFilterClause()'s custom-range subclause building for all 3
                // prefix shapes plus the mixed-type $custom array-building loop.
                $search = [
                    'fields' => [
                        'date_created' => [
                            'preset' => 'custom',
                            'custom' => ['y2024', 'm2023-06', 'd2022-05-15', 20250101],
                        ],
                    ],
                ];
                $results = $this->service->getRegularSearchResults($search);

                $items = $results['items'];
                sort($items);
                self::assertSame([1], $items);
            } finally {
                $this->conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
            }
        }

        public function testGetRegularSearchResultsDatePostedWithUnrecognizedPresetMatchesEverything(): void
        {
            // dateFilterClause() falls back to a permissive '1=1' clause for a
            // preset that's neither a recognized threshold nor 'custom'.
            $search = [
                'fields' => [
                    'date_posted' => [
                        'preset' => 'not-a-real-preset',
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetRegularSearchResultsFiltersByRatingsNullAndNumericBucket(): void
        {
            // image5's rating_score is NULL (the '0' bucket); image1 is 4.50
            // (falls in the '5' bucket's [4,5) range).
            $search = [
                'fields' => [
                    'ratings' => ['0', '5'],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 5], $items);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsFiltersByFilesizeRange(): void
        {
            // every fixture image's filesize is 1 (KB) -- comfortably inside a
            // [1-100, 2+100] BETWEEN range.
            $search = [
                'fields' => [
                    'filesize_min' => 1,
                    'filesize_max' => 2,
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
            self::assertTrue($results['search_details']['has_filters_filled']);
        }

        public function testGetRegularSearchResultsAllwordsMatchesByAlbumTitle(): void
        {
            // 'cat-title' matches category 2's name ("Nested Sub Album", images
            // 4 and 5) -- exercises searchAllwords()'s category-name matching
            // sub-branch (image ids folded into the word's own field clauses),
            // and 'mode' omitted exercises the "default to AND" fallback.
            $search = [
                'fields' => [
                    'allwords' => [
                        'words' => ['Nested'],
                        'fields' => ['cat-title'],
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([4, 5], $items);
        }

        public function testGetRegularSearchResultsAllwordsMatchesByTagName(): void
        {
            // 'travel' (tag 2) only tags image 1 -- exercises searchAllwords()'s
            // tag-name matching sub-branch.
            $search = [
                'fields' => [
                    'allwords' => [
                        'words' => ['travel'],
                        'fields' => ['tags'],
                        'mode' => 'OR',
                    ],
                ],
            ];

            $results = $this->service->getRegularSearchResults($search);

            self::assertSame([1], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheFindsATagNamedMatch(): void
        {
            // "family" only tags image 1 -- exercises qsearchGetTags() ->
            // qsearchEval() -> permission-filtered final query end to end,
            // including the quote()-based FULLTEXT/REGEXP clauses.
            $results = $this->service->getQuickSearchResultsNoCache('family', []);

            self::assertSame([1], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheReturnsEmptyForNoMatch(): void
        {
            $results = $this->service->getQuickSearchResultsNoCache('nosuchtermatall', []);

            self::assertSame([], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheFindsACategoryNamedMatch(): void
        {
            // "Nested" only matches category 2's name ("Nested Sub Album",
            // fixture) -- exercises qsearchGetCategories(), which filters
            // categories via $user['forbidden_categories'] instead of an
            // INNER JOIN against user_cache_categories, end to end. Category 2
            // holds images 4 and 5 (image_category fixture).
            $results = $this->service->getQuickSearchResultsNoCache('Nested', []);

            self::assertSame([4, 5], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheExcludesAForbiddenCategoryMatch(): void
        {
            // Same search as above, but with category 2 marked forbidden for
            // this user -- proves the NOT IN (...) replacement actually
            // excludes it, not just that it's syntactically present.
            CurrentUserTestFactory::get()->set(User::fromUserArray(array_merge(self::realisticUserGlobal(), [
                'forbidden_categories' => '2',
            ])));

            $results = $this->service->getQuickSearchResultsNoCache('Nested', []);

            self::assertSame([], $results['items']);
        }

        /**
         * CachePools::searchResults() backs quick-search result caching --
         * proven the same way TagServiceTest/ForbiddenCategoriesCacheTest prove
         * their own pool wiring: mutate the underlying data (tag image 2
         * "family", which the fixture doesn't already do -- only image 1 is)
         * after the first (caching) call, then show a 2nd call with the same
         * query still returns the stale (pre-mutation) result.
         */
        public function testGetQuickSearchResultsCachesAcrossCalls(): void
        {
            $first = $this->service->getQuickSearchResults('family', []);
            self::assertSame([1], $first['items']);

            $this->conn->executeStatement(
                'INSERT INTO image_tag (image_id, tag_id) VALUES (2, 3)'
            );

            try {
                $second = $this->service->getQuickSearchResults('family', []);
                self::assertSame($first['items'], $second['items'], 'a cache hit must not re-query the DB');
                self::assertArrayNotHasKey('debug', $second);
            } finally {
                $this->conn->executeStatement(
                    'DELETE FROM image_tag WHERE image_id = 2 AND tag_id = 3'
                );
            }
        }

        public function testQsearchGetTextTokenSearchSqlFallsBackToRegexpForALeadingWildcard(): void
        {
            // QST_WILDCARD_BEGIN forces useFt=false regardless of term length --
            // MySQL FULLTEXT can't do a leading-wildcard prefix match.
            $token = new QSingleToken('hoto', QSingleToken::QST_WILDCARD_BEGIN, null);

            [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name']);

            self::assertNotSame([], $clauses);
            $count = $this->conn->executeQuery(
                'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
                $values
            )->fetchOne();
            // every fixture image is named "Photo N" -- "hoto" (no left
            // boundary required) matches all 5.
            self::assertSame(5, is_numeric($count) ? (int) $count : null);
        }

        public function testQsearchGetTextTokenSearchSqlFallsBackToRegexpForAQuotedTrailingWildcard(): void
        {
            $modifier = QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END;
            $token = new QSingleToken('Phot', $modifier, null);

            [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name']);

            self::assertNotSame([], $clauses);
            $count = $this->conn->executeQuery(
                'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
                $values
            )->fetchOne();
            self::assertSame(5, is_numeric($count) ? (int) $count : null);
        }

        public function testQsearchGetTextTokenSearchSqlFallsBackToRegexpWhenEverySplitPartIsShort(): void
        {
            // "ab-cd" splits (on the punctuation class) into ["ab","cd"], both
            // shorter than 4 chars -- forces useFt=false even though the whole
            // term itself is longer than 3 chars.
            $token = new QSingleToken('ab-cd', 0, null);

            [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name']);

            self::assertNotSame([], $clauses);
            $count = $this->conn->executeQuery(
                'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
                $values
            )->fetchOne();
            self::assertSame(0, is_numeric($count) ? (int) $count : null);
        }

        public function testQsearchGetTextTokenSearchSqlWrapsAQuotedTermInDoubleQuotesForFulltext(): void
        {
            $token = new QSingleToken('nature', QSingleToken::QST_QUOTED, null);

            [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

            if ($this->dbDriver === 'pgsql') {
                self::assertSame(["tsv_search @@ to_tsquery('simple', ?)"], $clauses);
                self::assertSame(['nature'], $values);
            } else {
                self::assertSame(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE) AND (name LIKE ? OR comment LIKE ?)'], $clauses);
                self::assertSame(['"nature"', '%nature%', '%nature%'], $values);
            }
        }

        public function testQsearchGetTextTokenSearchSqlAppendsAStarForATrailingWildcardFulltextTerm(): void
        {
            $token = new QSingleToken('travel', QSingleToken::QST_WILDCARD_END, null);

            [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

            if ($this->dbDriver === 'pgsql') {
                self::assertSame(["tsv_search @@ to_tsquery('simple', ?)"], $clauses);
                self::assertSame(['travel:*'], $values);
            } else {
                self::assertSame(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE) AND (name LIKE ? OR comment LIKE ?)'], $clauses);
                self::assertSame(['travel*', 'travel%', 'travel%'], $values);
            }
        }

        public function testQsearchGetTextTokenSearchSqlThrowsWhenPregSplitHitsTheBacktrackLimit(): void
        {
            // 'hello-world' (>3 chars, unquoted, no wildcard) forces
            // $useFt=true so the preg_split() branch actually runs -- same
            // ini_set('pcre.backtrack_limit', '0') technique as
            // MetadataServiceTest::test_parse_svg_dimensions_returns_null_when_preg_replace_hits_the_backtrack_limit().
            // Must contain at least one of the pattern's own delimiter
            // characters: a plain 'helloworld' with none of
            // them never makes preg_split() attempt any real matching work at
            // all, so it never backtracks and backtrack_limit=0 has nothing
            // to bite -- only a string the split pattern actually has to work
            // on, like the real '-' here, reproduces PREG_BACKTRACK_LIMIT_ERROR.
            $originalLimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', '0');

            try {
                $this->expectException(Exception::class);
                $this->expectExceptionMessageIsOrContains('qsearchGetTextTokenSearchSql(): preg_split() failed');

                $this->service->qsearchGetTextTokenSearchSql(new QSingleToken('hello-world', 0, null), ['name']);
            } finally {
                ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
            }
        }

        public function testGetQuickSearchResultsNoCacheMatchesTheAuthorScopeWhenPopulated(): void
        {
            // Every fixture image has a NULL author -- a non-empty author:
            // term never matches, but proves the 'author' scope's non-empty
            // branch runs end to end.
            $results = $this->service->getQuickSearchResultsNoCache('author:someone', []);

            self::assertSame([], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheWildcardedEmptyAuthorMatchesAuthoredImages(): void
        {
            $results = $this->service->getQuickSearchResultsNoCache('author:*', []);

            self::assertSame([], $results['items']);
        }

        public function testGetQuickSearchResultsNoCachePlainEmptyAuthorMatchesUnauthoredImages(): void
        {
            $results = $this->service->getQuickSearchResultsNoCache('author:', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByWidthAndHeightScopes(): void
        {
            // every fixture image is 200x150.
            $results = $this->service->getQuickSearchResultsNoCache('width:200 height:150', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByRatioScope(): void
        {
            // 200/150 = 1.3333... -- comfortably inside the explicit range.
            $results = $this->service->getQuickSearchResultsNoCache('ratio:1.3..1.4', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersBySizeScope(): void
        {
            // width*height = 200*150 = 30000 for every fixture image.
            $results = $this->service->getQuickSearchResultsNoCache('size:30000', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByHitsScope(): void
        {
            // every fixture image's hit counter is 0.
            $results = $this->service->getQuickSearchResultsNoCache('hits:0', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByScoreScopeExcludingUnrated(): void
        {
            // rating_score: 4.50/3.00/5.00/2.00/NULL for images 1-5 -- image5's
            // NULL never satisfies a numeric BETWEEN-style clause.
            $results = $this->service->getQuickSearchResultsNoCache('score:2..5', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByFilesizeScope(): void
        {
            // 1024*filesize = 1024*1 = 1024 for every fixture image.
            $results = $this->service->getQuickSearchResultsNoCache('filesize:1000..2000', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByCreatedScopeWithNoMatch(): void
        {
            // date_creation is NULL for every fixture image.
            $results = $this->service->getQuickSearchResultsNoCache('created:2024..2027', []);

            self::assertSame([], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByPostedScope(): void
        {
            // date_available is '2026-08-01 00:00:00' for every fixture image.
            $results = $this->service->getQuickSearchResultsNoCache('posted:2024..2027', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByIdScope(): void
        {
            $results = $this->service->getQuickSearchResultsNoCache('id:1..3', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        }

        public function testGetQuickSearchResultsNoCacheFiltersByFileScope(): void
        {
            // only image 1's filename ('fixture-photo-1.jpg') contains
            // "photo-1" -- image 10 doesn't exist, so there's no accidental
            // substring collision.
            $results = $this->service->getQuickSearchResultsNoCache('file:photo-1', []);

            self::assertSame([1], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheUnhandledScopeFallsThroughTheDefaultHookBranch(): void
        {
            // 'tag' has no dedicated case in qsearchGetImages()'s own switch (it
            // has its own dedicated qsearchGetTags() path instead) -- a
            // tag-scoped token falls to the default/plugin-hook branch there,
            // contributing nothing to images_iids; the final match comes
            // entirely from qsearchGetTags()'s own tag_iids.
            $results = $this->service->getQuickSearchResultsNoCache('tag:nature', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        }

        public function testQsearchGetTagsDirectCallWithANullableWildcardedTagScopeMatchesEveryTaggedImage(): void
        {
            // The real quick-search scope list registers 'tag' as non-nullable,
            // so an empty-term tag-scoped token can never survive
            // QMultiToken::push() through the normal
            // getQuickSearchResultsNoCache() path -- calling qsearchGetTags()
            // directly with a hand-built nullable 'tag' scope is the only way
            // to reach this branch.
            $scopes = [new QSearchScope('tag', [], true)];
            $expr = new QExpression('tag:*', $scopes);
            $qsr = new QResults();

            $this->service->qsearchGetTags($expr, $qsr);

            $imageIds = $qsr->tag_iids[0];
            sort($imageIds);
            self::assertSame([1, 2, 3], $imageIds);
        }

        public function testQsearchGetTagsDirectCallWithANullableEmptyTagScopeMatchesUntaggedImages(): void
        {
            $scopes = [new QSearchScope('tag', [], true)];
            $expr = new QExpression('tag:', $scopes);
            $qsr = new QResults();

            $this->service->qsearchGetTags($expr, $qsr);

            $imageIds = $qsr->tag_iids[0];
            sort($imageIds);
            self::assertSame([4, 5], $imageIds);
        }

        public function testQsearchGetCategoriesDirectCallWithANullableWildcardedCategoryScopeMatchesEveryCategorizedImage(): void
        {
            // No real quick-search scope ever has id 'category' (the registered
            // scope list only has tag/photo/file/author/numeric/date scopes) --
            // same direct-call rationale as the tag tests above.
            $scopes = [new QSearchScope('category', [], true)];
            $expr = new QExpression('category:*', $scopes);
            $qsr = new QResults();

            $this->service->qsearchGetCategories($expr, $qsr);

            $imageIds = $qsr->cat_iids[0];
            sort($imageIds);
            self::assertSame([1, 2, 3, 4, 5], $imageIds);
        }

        public function testQsearchGetCategoriesDirectCallWithANullableEmptyCategoryScopeMatchesUncategorizedImages(): void
        {
            $scopes = [new QSearchScope('category', [], true)];
            $expr = new QExpression('category:', $scopes);
            $qsr = new QResults();

            $this->service->qsearchGetCategories($expr, $qsr);

            self::assertSame([], $qsr->cat_iids[0]);
        }

        public function testQsearchGetImagesDispatchesTheHookForAnUnrecognizedScopeAndAppliesTheReturnedClause(): void
        {
            // No real quick-search scope ever has id 'custom_field' -- reaches
            // qsearchGetImages()'s own default/plugin-hook branch, same
            // direct-call rationale as the tag/category tests above.
            $handler = static fn (QsearchGetImagesSqlScopes $event): QsearchGetImagesSqlScopes => new QsearchGetImagesSqlScopes(
                [new QsearchClause('i.id = ?', [1])],
                $event->token,
                $event->expr
            );
            EventDispatcherTestFactory::get()->addTypedHandler(QsearchGetImagesSqlScopes::class, $handler);

            try {
                $scopes = [new QSearchScope('custom_field', [], true)];
                $expr = new QExpression('custom_field:*', $scopes);
                $qsr = new QResults();

                $this->service->qsearchGetImages($expr, $qsr);

                self::assertSame([1], $qsr->images_iids[0]);
            } finally {
                EventDispatcherTestFactory::get()->removeEventHandler(QsearchGetImagesSqlScopes::class, $handler);
            }
        }

        public function testQsearchGetImagesMergesParamsFromMultipleHookClauses(): void
        {
            $handler = static fn (QsearchGetImagesSqlScopes $event): QsearchGetImagesSqlScopes => new QsearchGetImagesSqlScopes(
                [
                    new QsearchClause('i.id = ?', [1]),
                    new QsearchClause('i.id = ?', [2]),
                ],
                $event->token,
                $event->expr
            );
            EventDispatcherTestFactory::get()->addTypedHandler(QsearchGetImagesSqlScopes::class, $handler);

            try {
                $scopes = [new QSearchScope('custom_field', [], true)];
                $expr = new QExpression('custom_field:*', $scopes);
                $qsr = new QResults();

                $this->service->qsearchGetImages($expr, $qsr);

                $imageIds = $qsr->images_iids[0];
                sort($imageIds);
                self::assertSame([1, 2], $imageIds);
            } finally {
                EventDispatcherTestFactory::get()->removeEventHandler(QsearchGetImagesSqlScopes::class, $handler);
            }
        }

        public function testQsearchGetImagesReturnsNoMatchesForAnUnrecognizedScopeWithNoListener(): void
        {
            $scopes = [new QSearchScope('custom_field', [], true)];
            $expr = new QExpression('custom_field:*', $scopes);
            $qsr = new QResults();

            $this->service->qsearchGetImages($expr, $qsr);

            self::assertSame([], $qsr->images_iids[0]);
        }

        public function testGetQuickSearchResultsNoCacheALoneNotPrefixedTagMatchProducesNoResults(): void
        {
            // NOT alone (no positive criterion) can never qualify a single
            // top-level token -- exercises both qsearchGetTags()'s own NOT-ids
            // accumulation and qsearchEval()'s own NOT branch.
            $results = $this->service->getQuickSearchResultsNoCache('-family', []);

            self::assertSame([], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheALoneNotPrefixedCategoryMatchProducesNoResults(): void
        {
            $results = $this->service->getQuickSearchResultsNoCache('-Sample', []);

            self::assertSame([], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheNarrowsTwoAdjacentShortTermsToASharedTagMatch(): void
        {
            // "dog" (<=3 chars) is too short for a real fixture tag -- insert a
            // temporary one so 2 adjacent short terms genuinely share a match,
            // exercising qsearchGetTags()'s own short-token intersection.
            $this->conn->executeStatement(
                "INSERT INTO tags (name, url_name, lastmodified) VALUES ('dog', 'dog', NOW())"
            );
            $tagId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                'INSERT INTO image_tag (image_id, tag_id) VALUES (2, ?)',
                [$tagId]
            );

            try {
                $results = $this->service->getQuickSearchResultsNoCache('dog dog', []);

                self::assertSame([2], $results['items']);
            } finally {
                $this->conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId]);
                $this->conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
            }
        }

        public function testGetQuickSearchResultsNoCacheNarrowsTwoAdjacentShortTermsToASharedCategoryMatch(): void
        {
            // "Sub" (<=3 chars) whole-word-matches category 2's name ("Nested
            // Sub Album") -- exercises qsearchGetCategories()'s own analogous
            // short-token intersection.
            $results = $this->service->getQuickSearchResultsNoCache('Sub Sub', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheExpandsToSubalbumsWhenEnabled(): void
        {
            CurrentConfigTestFactory::get()->quickSearchIncludeSubAlbums = true;

            // "Sample" matches category 1 ("Sample Album") only, by name --
            // with sub-album inclusion enabled this expands to include
            // category 2 (its child, per the fixture's uppercats), pulling in
            // images 4 and 5 too.
            $results = $this->service->getQuickSearchResultsNoCache('Sample', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3, 4, 5], $items);
        }

        public function testGetQuickSearchResultsNoCacheFindsNoSubalbumsForALeafCategoryMatchWithSubalbumsEnabled(): void
        {
            // findSubcategoryIds() matches on `uppercats`, which always
            // contains a category's own id -- so with real, uncorrupted data
            // getSubcatIds() can never return [] for a category that itself
            // just matched (it always matches at least itself). Temporarily
            // corrupting category 2's own `uppercats` (simulating the same
            // kind of stale/broken hierarchy row Admin\CategoryRepairService
            // exists to fix) is the only way to make findSubcategoryIds([2])
            // genuinely return [], exercising qsearchGetCategories()'s own
            // "$subcatIds === []" ternary branch -- as opposed to the sibling
            // test above, whose category 1 always DOES have a real child.
            CurrentConfigTestFactory::get()->quickSearchIncludeSubAlbums = true;
            $originalUppercats = $this->conn->fetchOne('SELECT uppercats FROM categories WHERE id = 2');
            self::assertIsString($originalUppercats);
            $this->conn->executeStatement("UPDATE categories SET uppercats = '999' WHERE id = 2");

            try {
                // "Nested" matches category 2 ("Nested Sub Album") by name only.
                $results = $this->service->getQuickSearchResultsNoCache('Nested', []);

                self::assertSame([], $results['items']);
            } finally {
                $this->conn->executeStatement('UPDATE categories SET uppercats = ? WHERE id = 2', [$originalUppercats]);
            }
        }

        public function testGetQuickSearchResultsNoCacheOrKeywordUnionsTwoTagMatches(): void
        {
            // "family" tags image 1 only; "nature" tags images 1,2,3. The
            // literal "OR" keyword sets QST_OR on the following token
            // (QMultiToken::parse()), exercising qsearchEval()'s own
            // OR-modifier union branch -- every other multi-term search test
            // in this file exercises the implicit AND/intersection instead.
            $results = $this->service->getQuickSearchResultsNoCache('family OR nature', []);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        }

        public function testGetQuickSearchResultsNoCacheEvaluatesAParenthesizedSubGroup(): void
        {
            // "(nature)" is a nested QMultiToken sub-expression -- exercises
            // qsearchEval()'s own recursive branch (a non-QSingleToken child).
            // "nature" tags images 1,2,3; "family" tags image 1 only -- the
            // implicit AND between the group and the trailing word intersects
            // down to image 1.
            $results = $this->service->getQuickSearchResultsNoCache('(nature) family', []);

            self::assertSame([1], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheThrowsWhenAQsearchGetScopesHandlerReturnsSomethingOtherThanAQsearchGetScopesInstance(): void
        {
            // addEventHandler(), not addTypedHandler() -- a real plugin
            // handler is untyped from PHPStan's perspective, and this test
            // exercises dispatchChange()'s own runtime enforcement, not a
            // static one.
            $handler = static fn (): mixed => null;
            EventDispatcherTestFactory::get()->addEventHandler(QsearchGetScopes::class, $handler);

            $this->expectException(Error::class);
            $this->expectExceptionMessageIsOrContains('must return an instance of');

            try {
                $this->service->getQuickSearchResultsNoCache('family', []);
            } finally {
                EventDispatcherTestFactory::get()->removeEventHandler(QsearchGetScopes::class, $handler);
            }
        }

        public function testGetQuickSearchResultsNoCacheFallsBackWhenAHookReturnsNonArrayItemsAndQs(): void
        {
            $handler = static function (QsearchResults $event): QsearchResults {
                $searchResults = $event->searchResults;
                $searchResults['items'] = 'not-an-array';
                $searchResults['qs'] = 'not-an-array-either';

                return new QsearchResults($searchResults, $event->expression, $event->qsr);
            };
            EventDispatcherTestFactory::get()->addTypedHandler(QsearchResults::class, $handler);

            try {
                $results = $this->service->getQuickSearchResultsNoCache('family', []);
            } finally {
                EventDispatcherTestFactory::get()->removeEventHandler(QsearchResults::class, $handler);
            }

            // The hook only corrupts $searchResults['items']/['qs'] -- both
            // safely fall back ([] / the reconstructed default qs) -- but the
            // real tag/category match computed *before* the hook ran (the
            // fixture's own 'family' tag, id 3, linked to image 1) still
            // reaches the final result via $ids, independently of whatever
            // the hook did -- this method never discards that
            // real match just because the hook's own extra items were unusable.
            self::assertSame([1], $results['items']);
            self::assertSame([
                'q' => 'family',
                'unmatched_terms' => [],
            ], $results['qs']);
        }

        public function testGetQuickSearchResultsNoCacheMergesExtraNumericIdsFromAPluginHook(): void
        {
            $handler = static function (QsearchResults $event): QsearchResults {
                $searchResults = $event->searchResults;
                $searchResults['items'] = ['4', 'not-numeric'];

                return new QsearchResults($searchResults, $event->expression, $event->qsr);
            };
            EventDispatcherTestFactory::get()->addTypedHandler(QsearchResults::class, $handler);

            try {
                $results = $this->service->getQuickSearchResultsNoCache('family', []);
            } finally {
                EventDispatcherTestFactory::get()->removeEventHandler(QsearchResults::class, $handler);
            }

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 4], $items);
        }

        public function testGetQuickSearchResultsNoCacheReturnsEarlyForAnEmptyQuery(): void
        {
            $results = $this->service->getQuickSearchResultsNoCache('', []);

            self::assertSame([], $results['items']);
            self::assertSame([
                'q' => '',
                'unmatched_terms' => [],
            ], $results['qs']);
        }

        public function testGetQuickSearchResultsNoCacheWorksWithANonDefaultCalendarDatefield(): void
        {
            // calendarDatefield() !== 'date_creation' takes the else branch,
            // appending 'date' to $postedDateAliases instead of
            // $createdDateAliases -- proves the scope list still builds and the
            // search still functions correctly either way.
            CurrentConfigTestFactory::get()->calendarDatefield = 'date_available';

            $results = $this->service->getQuickSearchResultsNoCache('family', []);

            self::assertSame([1], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheAppliesACustomImagesWhereClause(): void
        {
            // "nature" alone matches images 1,2,3; a custom images_where
            // narrows that down further, proving the clause is genuinely
            // applied (not coincidentally the same result).
            $results = $this->service->getQuickSearchResultsNoCache('nature', [
                'images_where' => 'id = 2',
            ]);

            self::assertSame([2], $results['items']);
        }

        public function testGetValidatedSearchInfoCallsFatalErrorForAnInvalidIdentifier(): void
        {
            $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('fatalError: Invalid search identifier');

            $service->getValidatedSearchInfo('not-a-valid-identifier', null);
        }

        public function testGetValidatedSearchInfoCallsFatalErrorWhenAUuidSearchIsLookedUpByBareId(): void
        {
            $id = $this->repo->insertSavedSearch([
                'q' => 'nature',
            ], '2026-07-12 00:00:00', 1, 'psk-20260712-fatalidtst', null);

            $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('fatalError: this search is not reachable with its id, need the search_uuid instead');

            $service->getValidatedSearchInfo((string) $id, null);
        }

        public function testGetValidatedSearchArrayCallsBadRequestWhenTheSearchIsNotFound(): void
        {
            $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('badRequest: this search identifier does not exist');

            // getSearchIdPattern()'s own search_uuid regex requires exactly 10
            // alphanumeric chars after the date ('doesnotexist' is 12) --
            // a too-long suffix doesn't match *any* recognised
            // pattern at all, so getValidatedSearchInfo()'s earlier "Invalid
            // search identifier" fatalError() fires first instead of ever
            // reaching the not-found badRequest() this test means to exercise.
            $service->getValidatedSearchArray('psk-20260712-doesnotexi', null);
        }

        public function testGetSearchResultsCallsBadRequestWhenTheSearchIdentifierDoesNotExist(): void
        {
            $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('badRequest: this search identifier does not exist');

            $service->getSearchResults('psk-20260712-doesnotexist', null);
        }

        public function testGetSearchResultsResolvesASavedQuickSearchQuery(): void
        {
            $id = $this->repo->insertSavedSearch([
                'q' => 'family',
            ], '2026-07-12 00:00:00', 1, 'psk-20260712-quicksrch1', null);

            $results = $this->service->getSearchResults((string) $id, true, '');

            self::assertSame([1], $results['items']);
        }

        public function testGetQuickSearchResultsNoCacheThrowsWhenTheDefaultUsersLanguageResolvesToAnInflectorClassThatDoesNotImplementTheInterface(): void
        {
            // See SearchServiceTestNotAnInflector's own docblock above for why
            // class_alias() is the only real way in.
            if (! class_exists('Piwigo\\Search\\Inflector\\InflectorZz', false)) {
                class_alias(SearchServiceTestNotAnInflector::class, 'Piwigo\\Search\\Inflector\\InflectorZz');
            }

            $originalLanguage = $this->conn->fetchOne('SELECT language FROM user_infos WHERE user_id = 2');
            self::assertIsString($originalLanguage);
            // user_id=2 is CurrentConfig::defaultUserId()'s own default (the
            // guest account) -- getDefaultLanguage() reads *this* row, entirely
            // independent of CurrentUser (id=1 in this file's own setUp()).
            $this->conn->executeStatement("UPDATE user_infos SET language = 'zz_ZZ' WHERE user_id = 2");
            $this->processCache()
                ->forget('default_user');

            try {
                $this->expectException(LogicException::class);
                $this->expectExceptionMessageIsOrContains('InflectorZz does not implement InflectorInterface');

                $this->service->getQuickSearchResultsNoCache('nature', []);
            } finally {
                $this->conn->executeStatement('UPDATE user_infos SET language = ? WHERE user_id = 2', [$originalLanguage]);
                $this->processCache()
                    ->forget('default_user');
            }
        }

        // A test forcing getAvailableSearchUuid()'s internal retry-on-collision
        // branch to fire deterministically on the very first candidate would
        // need to substitute SearchRepository::countSavedSearchByUuid()'s behavior --
        // SearchRepository is `final` (a real architectural choice, matching
        // this codebase's other repository classes) and SearchService's
        // constructor takes the concrete class directly, not an interface, so
        // there is no injectable seam here. The two tests above already cover
        // the real, user-observable contract (matches the expected uuid shape;
        // a real DB collision from a prior call's uuid produces a different
        // next uuid) -- forcing the exact internal retry-recursion path would
        // require either reflection or loosening the final class, neither of
        // which is worth it for an implementation detail this deeply internal.
    }
}
