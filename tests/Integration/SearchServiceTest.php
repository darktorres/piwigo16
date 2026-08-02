<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Bootstrap\RedirectService;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Core\HtmlRenderingInterface;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Event\Search\QsearchGetScopes;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Search\Event\QsearchResults;
    use Piwigo\Search\QExpression;
    use Piwigo\Search\QResults;
    use Piwigo\Search\QSearchScope;
    use Piwigo\Search\QSingleToken;
    use Piwigo\Search\SearchRepository;
    use Piwigo\Search\SearchService;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

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
    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new \LogicException('not implemented in this fake');
    }

    #[\Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new \LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new \LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new \LogicException('not implemented in this fake');
    }

    #[\Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new \RuntimeException('accessDenied called');
    }

    #[\Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new \RuntimeException('badRequest: ' . $msg);
    }

    #[\Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new \RuntimeException('pageNotFound: ' . ($msg ?? ''));
    }

    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new \RuntimeException('fatalError: ' . $msg);
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new \LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new \LogicException('not implemented in this fake');
    }

    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new \LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\Override]
    public function renderElementName(array $info): string
    {
        throw new \LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new \LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new \LogicException('not implemented in this fake');
    }
}

/**
 * A real class that deliberately does NOT implement InflectorInterface --
 * class_alias()'d onto a fake 'Piwigo\Search\Inflector\Inflector_zz' FQCN
 * by the Inflector-guard test below, standing in for exactly the real-world
 * scenario that guard defends against (a 3rd-party language pack shipping
 * a broken Inflector_xx.php for its own 2-letter code). Every real
 * Inflector_* class under src/Piwigo/Search/Inflector (currently only 'en'
 * and 'fr') correctly implements the interface, so there is no way to
 * reach this branch through any real language code -- class_alias() is a
 * genuine PHP class-resolution mechanism, not a mock of SearchService
 * itself.
 */
final class SearchServiceTestNotAnInflector
{
}

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
 * already confirmed live works even for a plain, non-catastrophic
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
        // UserService's own ProcessCache usage (reached via this test's own
        // ProcessCache::forgetStatic('default_user') calls below) now goes
        // through a transitional static shim (singleton/service-locator
        // elimination campaign, Phase 1), which needs a real container.
        \Piwigo\Core\Kernel::boot();

        $this->conn = DbConnection::build();
        $this->repo = new SearchRepository($this->conn);

        CurrentUser::current()->set(User::fromUserArray(self::realisticUserGlobal()));
        $currentConfig->setDefaultFiltersViews(null);
        $currentConfig->setFiltersViews([
            'expert' => ['access' => 'everybody'],
            'words' => ['access' => 'everybody'],
            'author' => ['access' => 'everybody'],
            'file_type' => ['access' => 'everybody'],
            'added_by' => ['access' => 'everybody'],
            'album' => ['access' => 'everybody'],
            'post_date' => ['access' => 'everybody'],
            'creation_date' => ['access' => 'everybody'],
            'ratio' => ['access' => 'everybody'],
            'rating' => ['access' => 'everybody'],
            'file_size' => ['access' => 'everybody'],
            'height' => ['access' => 'everybody'],
            'width' => ['access' => 'everybody'],
            'tags' => ['access' => 'everybody'],
        ]);
        $currentConfig->setOrderBy('ORDER BY id ASC');
        $currentConfig->setCalendarDatefield('date_creation');
        $currentConfig->setQuickSearchIncludeSubAlbums(false);
        $currentConfig->setRateEnabled(true);

        $this->service = new SearchService(
            \Piwigo\Auth\AccessControl::current(),
            $this->repo,
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
            new CategoryService(
                \Piwigo\Core\Lang::current(),
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
                new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
                \Piwigo\Config\CurrentConfig::current()
            ),
            new MailService(),
            new HtmlService(),
            new RedirectService(\Piwigo\Core\Lang::current(), $this->userService()),
            new SessionService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),\Piwigo\Config\CurrentConfig::current()), \Piwigo\PluginConfig\EventDispatcher::get(),
            \Piwigo\Users\CurrentUser::current(),
            \Piwigo\Core\Lang::current(),
            \Piwigo\Config\CurrentConfig::current(),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Piwigo\Cache\CachePools::searchResults()->clear();
        \Piwigo\Core\Kernel::reset();

        parent::tearDown();
    }

    private function userService(): \Piwigo\Users\UserService
    {
        // Kernel is already booted in setUp() above -- resolve the same
        // container-shared instance a real request would get, matching
        // RedirectService's own real production callers (singleton/
        // service-locator elimination campaign, Phase 6).
        $userService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Users\UserService::class);
        if (! $userService instanceof \Piwigo\Users\UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Users\UserService::class);
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
        return new SearchService(
            \Piwigo\Auth\AccessControl::current(),
            $repo,
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
            new CategoryService(
                \Piwigo\Core\Lang::current(),
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
                new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
                \Piwigo\Config\CurrentConfig::current()
            ),
            new MailService(),
            $htmlRenderer,
            new RedirectService(\Piwigo\Core\Lang::current(), $this->userService()),
            new SessionService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),\Piwigo\Config\CurrentConfig::current()), \Piwigo\PluginConfig\EventDispatcher::get(),
            \Piwigo\Users\CurrentUser::current(),
            \Piwigo\Core\Lang::current(),
            \Piwigo\Config\CurrentConfig::current(),
        );
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

    public function test_get_search_id_pattern_recognizes_a_uuid(): void
    {
        self::assertSame('search_uuid = ?', SearchService::getSearchIdPattern('psk-20260712-abcdefghij'));
    }

    public function test_get_search_id_pattern_recognizes_a_numeric_id(): void
    {
        self::assertSame('id = ?', SearchService::getSearchIdPattern(42));
    }

    public function test_get_search_id_pattern_rejects_garbage(): void
    {
        self::assertNull(SearchService::getSearchIdPattern('not-a-valid-identifier'));
    }

    public function test_get_search_info_returns_the_stored_row(): void
    {
        $this->repo->insertSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-infotest01', null);

        $info = $this->service->getSearchInfo('psk-20260712-infotest01');

        self::assertNotNull($info);
        self::assertSame('psk-20260712-infotest01', $info->searchUuid);
    }

    public function test_get_search_info_returns_null_for_an_invalid_identifier(): void
    {
        self::assertNull($this->service->getSearchInfo('garbage'));
    }

    public function test_get_search_array_round_trips_the_json_encoded_rules(): void
    {
        $rules = ['q' => 'nature', 'fields' => ['allwords' => ['words' => ['nature']]]];
        $this->repo->insertSearch($rules, '2026-07-12 00:00:00', 1, 'psk-20260712-arraytest0', null);

        $decoded = $this->service->getSearchArray('psk-20260712-arraytest0');

        self::assertSame($rules, $decoded);
    }

    public function test_get_search_array_returns_false_for_a_missing_search(): void
    {
        self::assertFalse($this->service->getSearchArray('psk-20260712-nosuchuid0'));
    }

    public function test_get_available_search_uuid_matches_the_expected_shape(): void
    {
        $uuid = $this->service->getAvailableSearchUuid();

        // Case-insensitive, matching SearchService::getSearchIdPattern()'s
        // own regex -- generate_key()'s base64-derived charset includes
        // uppercase letters.
        self::assertMatchesRegularExpression('/^psk-\d{8}-[a-z0-9]{10}$/i', $uuid);
    }

    public function test_get_available_search_uuid_skips_a_colliding_uuid(): void
    {
        $uuid = $this->service->getAvailableSearchUuid();
        $this->repo->insertSearch(['q' => 'x'], '2026-07-12 00:00:00', null, $uuid, null);

        $next = $this->service->getAvailableSearchUuid();

        self::assertNotSame($uuid, $next);
        self::assertSame(0, $this->repo->countByUuid($next));
    }

    public function test_split_allwords_splits_on_whitespace(): void
    {
        self::assertSame(['nature', 'travel'], SearchService::splitAllwords('nature travel'));
    }

    public function test_split_allwords_returns_null_for_blank_input(): void
    {
        self::assertNull(SearchService::splitAllwords('   '));
    }

    public function test_split_allwords_throws_when_preg_split_hits_the_backtrack_limit(): void
    {
        $originalLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '0');

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('splitAllwords(): preg_split() failed');

            SearchService::splitAllwords('nature travel');
        } finally {
            ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
        }
    }

    public function test_qsearch_get_text_token_search_sql_is_injection_safe(): void
    {
        // [SEC-18] a term with a single quote must not break out of the
        // generated REGEXP/MATCH clauses -- proven by actually executing
        // them against the real fixture DB, not just eyeballing the SQL.
        $token = new QSingleToken("nature's", 0, null);

        // ['name', 'comment'] matches the images_ft_name_comment FULLTEXT
        // index's exact column list (MySQL requires an exact match to use
        // MATCH() against it) -- the same pair every real call site passes.
        $clauses = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

        self::assertNotSame([], $clauses);

        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')'
        )->fetchOne();

        self::assertSame(0, is_numeric($count) ? (int) $count : null);
    }

    public function test_get_regular_search_results_filters_by_width_and_height(): void
    {
        $search = ['fields' => [
            'width_min' => 100, 'width_max' => 300,
            'height_min' => 100, 'height_max' => 300,
        ]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_ratio(): void
    {
        // every fixture image is 200x150 -- ratio 1.333, the "Landscape"
        // bucket (1.05 < ratio < 2).
        $search = ['fields' => ['ratios' => ['Landscape']]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_regular_search_results_filters_by_category(): void
    {
        $search = ['fields' => ['cat' => ['words' => [1], 'sub_inc' => false]]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3], $items);
    }

    public function test_get_regular_search_results_filters_by_tags(): void
    {
        $search = ['fields' => ['tags' => ['words' => [1], 'mode' => 'AND']]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3], $items);
    }

    public function test_get_regular_search_results_combines_two_filters_via_intersection(): void
    {
        // cat=1 -> {1,2,3}; tags=1 -> {1,2,3} -- intersection is still
        // {1,2,3}, proving the multi-filter array_intersect() path (not
        // just the single-filter reset() shortcut) produces a valid
        // list<int>.
        $search = ['fields' => [
            'cat' => ['words' => [1], 'sub_inc' => false],
            'tags' => ['words' => [1], 'mode' => 'AND'],
        ]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3], $items);
    }

    public function test_get_regular_search_results_custom_search_clause(): void
    {
        $results = $this->service->getRegularSearchResults([], 'id = 1');

        self::assertSame([1], $results['items']);
    }

    public function test_get_regular_search_results_returns_empty_for_no_filters(): void
    {
        $results = $this->service->getRegularSearchResults([]);

        self::assertSame([], $results['items']);
        self::assertFalse($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_expert_string(): void
    {
        // The 'expert' criterion delegates to getQuickSearchResults() itself
        // -- "family" resolves via the tag-name quick-search path to image 1.
        $search = ['fields' => ['expert' => ['string' => 'family']]];

        $results = $this->service->getRegularSearchResults($search);

        self::assertSame([1], $results['items']);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_author_field_with_no_match(): void
    {
        // Every fixture image has a NULL author -- proves the criterion
        // executes end to end (well-formed empty result), not that it
        // matches anything.
        $search = ['fields' => ['author' => ['words' => ['Someone']]]];

        $results = $this->service->getRegularSearchResults($search);

        self::assertSame([], $results['items']);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_filetypes(): void
    {
        // every fixture image's path ends in .jpg.
        $search = ['fields' => ['filetypes' => ['jpg']]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_added_by(): void
    {
        // every fixture image has added_by = 1.
        $search = ['fields' => ['added_by' => [1]]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_date_posted_preset(): void
    {
        // NOW()-relative rather than a hardcoded literal, so this stays
        // correct regardless of the real wall-clock date the suite runs on.
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = NOW() - INTERVAL 1 HOUR WHERE id IN (1, 2)'
        );
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = NOW() - INTERVAL 30 HOUR WHERE id IN (3, 4, 5)'
        );

        try {
            $search = ['fields' => ['date_posted' => ['preset' => '24h']]];
            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2], $items);
            self::assertTrue($results['search_details']['has_filters_filled']);
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::images() . " SET date_available = '2026-08-01 00:00:00' WHERE id IN (1,2,3,4,5)"
            );
        }
    }

    public function test_get_regular_search_results_filters_by_date_created_preset(): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_creation = NOW() - INTERVAL 1 DAY WHERE id IN (1, 2, 3)'
        );
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_creation = NOW() - INTERVAL 60 DAY WHERE id IN (4, 5)'
        );

        try {
            $search = ['fields' => ['date_created' => ['preset' => '7d']]];
            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1, 2, 3], $items);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
        }
    }

    public function test_get_regular_search_results_date_created_custom_range(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2024-03-15 12:00:00' WHERE id = 1");
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2025-01-01 00:00:00' WHERE id = 2");

        try {
            // Mixes a 'y'/'m'/'d'-prefixed string entry of each shape plus a
            // non-string (int) entry that matches none of them -- exercises
            // dateFilterClause()'s custom-range subclause building for all 3
            // prefix shapes plus the mixed-type $custom array-building loop.
            $search = ['fields' => ['date_created' => [
                'preset' => 'custom',
                'custom' => ['y2024', 'm2023-06', 'd2022-05-15', 20250101],
            ]]];
            $results = $this->service->getRegularSearchResults($search);

            $items = $results['items'];
            sort($items);
            self::assertSame([1], $items);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
        }
    }

    public function test_get_regular_search_results_date_posted_with_unrecognized_preset_matches_everything(): void
    {
        // dateFilterClause() falls back to a permissive '1=1' clause for a
        // preset that's neither a recognized threshold nor 'custom'.
        $search = ['fields' => ['date_posted' => ['preset' => 'not-a-real-preset']]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_regular_search_results_filters_by_ratings_null_and_numeric_bucket(): void
    {
        // image5's rating_score is NULL (the '0' bucket); image1 is 4.50
        // (falls in the '5' bucket's [4,5) range).
        $search = ['fields' => ['ratings' => ['0', '5']]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 5], $items);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_filters_by_filesize_range(): void
    {
        // every fixture image's filesize is 1 (KB) -- comfortably inside a
        // [1-100, 2+100] BETWEEN range.
        $search = ['fields' => ['filesize_min' => 1, 'filesize_max' => 2]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
        self::assertTrue($results['search_details']['has_filters_filled']);
    }

    public function test_get_regular_search_results_allwords_matches_by_album_title(): void
    {
        // 'cat-title' matches category 2's name ("Nested Sub Album", images
        // 4 and 5) -- exercises searchAllwords()'s category-name matching
        // sub-branch (image ids folded into the word's own field clauses),
        // and 'mode' omitted exercises the "default to AND" fallback.
        $search = ['fields' => ['allwords' => [
            'words' => ['Nested'],
            'fields' => ['cat-title'],
        ]]];

        $results = $this->service->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        self::assertSame([4, 5], $items);
    }

    public function test_get_regular_search_results_allwords_matches_by_tag_name(): void
    {
        // 'travel' (tag 2) only tags image 1 -- exercises searchAllwords()'s
        // tag-name matching sub-branch.
        $search = ['fields' => ['allwords' => [
            'words' => ['travel'],
            'fields' => ['tags'],
            'mode' => 'OR',
        ]]];

        $results = $this->service->getRegularSearchResults($search);

        self::assertSame([1], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_finds_a_tag_named_match(): void
    {
        // "family" only tags image 1 -- exercises qsearchGetTags() ->
        // qsearchEval() -> permission-filtered final query end to end,
        // including the SEC-18 quote()-based FULLTEXT/REGEXP clauses.
        $results = $this->service->getQuickSearchResultsNoCache('family', []);

        self::assertSame([1], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_returns_empty_for_no_match(): void
    {
        $results = $this->service->getQuickSearchResultsNoCache('nosuchtermatall', []);

        self::assertSame([], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_finds_a_category_named_match(): void
    {
        // "Nested" only matches category 2's name ("Nested Sub Album",
        // fixture) -- exercises qsearchGetCategories()'s P23 batch 3 fix
        // (categories filtered via $user['forbidden_categories'] instead
        // of an INNER JOIN against user_cache_categories) end to end.
        // Category 2 holds images 4 and 5 (piwigo_image_category fixture).
        $results = $this->service->getQuickSearchResultsNoCache('Nested', []);

        self::assertSame([4, 5], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_excludes_a_forbidden_category_match(): void
    {
        // Same search as above, but with category 2 marked forbidden for
        // this user -- proves the NOT IN (...) replacement actually
        // excludes it, not just that it's syntactically present.
        CurrentUser::current()->set(User::fromUserArray(array_merge(self::realisticUserGlobal(), ['forbidden_categories' => '2'])));

        $results = $this->service->getQuickSearchResultsNoCache('Nested', []);

        self::assertSame([], $results['items']);
    }

    /**
     * CachePools::searchResults() (gap-closure Stage 4a) replaces the
     * older PersistentFileCache/cacheUpdateTime mechanism -- proven the
     * same way TagServiceTest/ForbiddenCategoriesCacheTest prove their own
     * pool wiring: mutate the underlying data (tag image 2 "family", which
     * the fixture doesn't already do -- only image 1 is) after the first
     * (caching) call, then show a 2nd call with the same query still
     * returns the stale (pre-mutation) result.
     */
    public function test_get_quick_search_results_caches_across_calls(): void
    {
        $first = $this->service->getQuickSearchResults('family', []);
        self::assertSame([1], $first['items']);

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (2, 3)'
        );

        try {
            $second = $this->service->getQuickSearchResults('family', []);
            self::assertSame($first['items'], $second['items'], 'a cache hit must not re-query the DB');
            self::assertArrayNotHasKey('debug', $second);
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 2 AND tag_id = 3'
            );
        }
    }

    public function test_qsearch_get_text_token_search_sql_falls_back_to_regexp_for_a_leading_wildcard(): void
    {
        // QST_WILDCARD_BEGIN forces useFt=false regardless of term length --
        // MySQL FULLTEXT can't do a leading-wildcard prefix match.
        $token = new QSingleToken('hoto', QSingleToken::QST_WILDCARD_BEGIN, null);

        $clauses = $this->service->qsearchGetTextTokenSearchSql($token, ['name']);

        self::assertNotSame([], $clauses);
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')'
        )->fetchOne();
        // every fixture image is named "Photo N" -- "hoto" (no left
        // boundary required) matches all 5.
        self::assertSame(5, is_numeric($count) ? (int) $count : null);
    }

    public function test_qsearch_get_text_token_search_sql_falls_back_to_regexp_for_a_quoted_trailing_wildcard(): void
    {
        $modifier = QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END;
        $token = new QSingleToken('Phot', $modifier, null);

        $clauses = $this->service->qsearchGetTextTokenSearchSql($token, ['name']);

        self::assertNotSame([], $clauses);
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')'
        )->fetchOne();
        self::assertSame(5, is_numeric($count) ? (int) $count : null);
    }

    public function test_qsearch_get_text_token_search_sql_falls_back_to_regexp_when_every_split_part_is_short(): void
    {
        // "ab-cd" splits (on the punctuation class) into ["ab","cd"], both
        // shorter than 4 chars -- forces useFt=false even though the whole
        // term itself is longer than 3 chars.
        $token = new QSingleToken('ab-cd', 0, null);

        $clauses = $this->service->qsearchGetTextTokenSearchSql($token, ['name']);

        self::assertNotSame([], $clauses);
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')'
        )->fetchOne();
        self::assertSame(0, is_numeric($count) ? (int) $count : null);
    }

    public function test_qsearch_get_text_token_search_sql_wraps_a_quoted_term_in_double_quotes_for_fulltext(): void
    {
        $token = new QSingleToken('nature', QSingleToken::QST_QUOTED, null);

        $clauses = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

        $expectedQuoted = $this->repo->quote('"nature"');
        self::assertSame(['MATCH(name, comment) AGAINST(' . $expectedQuoted . ' IN BOOLEAN MODE)'], $clauses);
    }

    public function test_qsearch_get_text_token_search_sql_appends_a_star_for_a_trailing_wildcard_fulltext_term(): void
    {
        $token = new QSingleToken('travel', QSingleToken::QST_WILDCARD_END, null);

        $clauses = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

        $expectedQuoted = $this->repo->quote('travel*');
        self::assertSame(['MATCH(name, comment) AGAINST(' . $expectedQuoted . ' IN BOOLEAN MODE)'], $clauses);
    }

    public function test_qsearch_get_text_token_search_sql_throws_when_preg_split_hits_the_backtrack_limit(): void
    {
        // 'hello-world' (>3 chars, unquoted, no wildcard) forces
        // $useFt=true so the preg_split() branch actually runs -- same
        // ini_set('pcre.backtrack_limit', '0') technique as
        // MetadataServiceTest::test_parse_svg_dimensions_returns_null_when_preg_replace_hits_the_backtrack_limit().
        // Must contain at least one of the pattern's own delimiter
        // characters (confirmed live: a plain 'helloworld' with none of
        // them never makes preg_split() attempt any real matching work at
        // all, so it never backtracks and backtrack_limit=0 has nothing
        // to bite -- only a string the split pattern actually has to work
        // on, like the real '-' here, reproduces PREG_BACKTRACK_LIMIT_ERROR).
        $originalLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '0');

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('qsearchGetTextTokenSearchSql(): preg_split() failed');

            $this->service->qsearchGetTextTokenSearchSql(new QSingleToken('hello-world', 0, null), ['name']);
        } finally {
            ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
        }
    }

    public function test_get_quick_search_results_no_cache_matches_the_author_scope_when_populated(): void
    {
        // Every fixture image has a NULL author -- a non-empty author:
        // term never matches, but proves the 'author' scope's non-empty
        // branch runs end to end.
        $results = $this->service->getQuickSearchResultsNoCache('author:someone', []);

        self::assertSame([], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_wildcarded_empty_author_matches_authored_images(): void
    {
        $results = $this->service->getQuickSearchResultsNoCache('author:*', []);

        self::assertSame([], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_plain_empty_author_matches_unauthored_images(): void
    {
        $results = $this->service->getQuickSearchResultsNoCache('author:', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_width_and_height_scopes(): void
    {
        // every fixture image is 200x150.
        $results = $this->service->getQuickSearchResultsNoCache('width:200 height:150', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_ratio_scope(): void
    {
        // 200/150 = 1.3333... -- comfortably inside the explicit range.
        $results = $this->service->getQuickSearchResultsNoCache('ratio:1.3..1.4', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_size_scope(): void
    {
        // width*height = 200*150 = 30000 for every fixture image.
        $results = $this->service->getQuickSearchResultsNoCache('size:30000', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_hits_scope(): void
    {
        // every fixture image's hit counter is 0.
        $results = $this->service->getQuickSearchResultsNoCache('hits:0', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_score_scope_excluding_unrated(): void
    {
        // rating_score: 4.50/3.00/5.00/2.00/NULL for images 1-5 -- image5's
        // NULL never satisfies a numeric BETWEEN-style clause.
        $results = $this->service->getQuickSearchResultsNoCache('score:2..5', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_filesize_scope(): void
    {
        // 1024*filesize = 1024*1 = 1024 for every fixture image.
        $results = $this->service->getQuickSearchResultsNoCache('filesize:1000..2000', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_created_scope_with_no_match(): void
    {
        // date_creation is NULL for every fixture image.
        $results = $this->service->getQuickSearchResultsNoCache('created:2024..2027', []);

        self::assertSame([], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_filters_by_posted_scope(): void
    {
        // date_available is '2026-08-01 00:00:00' for every fixture image.
        $results = $this->service->getQuickSearchResultsNoCache('posted:2024..2027', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_id_scope(): void
    {
        $results = $this->service->getQuickSearchResultsNoCache('id:1..3', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3], $items);
    }

    public function test_get_quick_search_results_no_cache_filters_by_file_scope(): void
    {
        // only image 1's filename ('fixture-photo-1.jpg') contains
        // "photo-1" -- image 10 doesn't exist, so there's no accidental
        // substring collision.
        $results = $this->service->getQuickSearchResultsNoCache('file:photo-1', []);

        self::assertSame([1], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_unhandled_scope_falls_through_the_default_hook_branch(): void
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

    public function test_qsearch_get_tags_direct_call_with_a_nullable_wildcarded_tag_scope_matches_every_tagged_image(): void
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

    public function test_qsearch_get_tags_direct_call_with_a_nullable_empty_tag_scope_matches_untagged_images(): void
    {
        $scopes = [new QSearchScope('tag', [], true)];
        $expr = new QExpression('tag:', $scopes);
        $qsr = new QResults();

        $this->service->qsearchGetTags($expr, $qsr);

        $imageIds = $qsr->tag_iids[0];
        sort($imageIds);
        self::assertSame([4, 5], $imageIds);
    }

    public function test_qsearch_get_categories_direct_call_with_a_nullable_wildcarded_category_scope_matches_every_categorized_image(): void
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

    public function test_qsearch_get_categories_direct_call_with_a_nullable_empty_category_scope_matches_uncategorized_images(): void
    {
        $scopes = [new QSearchScope('category', [], true)];
        $expr = new QExpression('category:', $scopes);
        $qsr = new QResults();

        $this->service->qsearchGetCategories($expr, $qsr);

        self::assertSame([], $qsr->cat_iids[0]);
    }

    public function test_get_quick_search_results_no_cache_a_lone_not_prefixed_tag_match_produces_no_results(): void
    {
        // NOT alone (no positive criterion) can never qualify a single
        // top-level token -- exercises both qsearchGetTags()'s own NOT-ids
        // accumulation and qsearchEval()'s own NOT branch.
        $results = $this->service->getQuickSearchResultsNoCache('-family', []);

        self::assertSame([], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_a_lone_not_prefixed_category_match_produces_no_results(): void
    {
        $results = $this->service->getQuickSearchResultsNoCache('-Sample', []);

        self::assertSame([], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_narrows_two_adjacent_short_terms_to_a_shared_tag_match(): void
    {
        // "dog" (<=3 chars) is too short for a real fixture tag -- insert a
        // temporary one so 2 adjacent short terms genuinely share a match,
        // exercising qsearchGetTags()'s own short-token intersection.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::tags() . " (name, url_name, lastmodified) VALUES ('dog', 'dog', NOW())"
        );
        $tagId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (2, ?)',
            [$tagId]
        );

        try {
            $results = $this->service->getQuickSearchResultsNoCache('dog dog', []);

            self::assertSame([2], $results['items']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE tag_id = ?', [$tagId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE id = ?', [$tagId]);
        }
    }

    public function test_get_quick_search_results_no_cache_narrows_two_adjacent_short_terms_to_a_shared_category_match(): void
    {
        // "Sub" (<=3 chars) whole-word-matches category 2's name ("Nested
        // Sub Album") -- exercises qsearchGetCategories()'s own analogous
        // short-token intersection.
        $results = $this->service->getQuickSearchResultsNoCache('Sub Sub', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_expands_to_subalbums_when_enabled(): void
    {
        CurrentConfig::current()->setQuickSearchIncludeSubAlbums(true);

        // "Sample" matches category 1 ("Sample Album") only, by name --
        // with sub-album inclusion enabled this expands to include
        // category 2 (its child, per the fixture's uppercats), pulling in
        // images 4 and 5 too.
        $results = $this->service->getQuickSearchResultsNoCache('Sample', []);

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 2, 3, 4, 5], $items);
    }

    public function test_get_quick_search_results_no_cache_finds_no_subalbums_for_a_leaf_category_match_with_subalbums_enabled(): void
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
        CurrentConfig::current()->setQuickSearchIncludeSubAlbums(true);
        $originalUppercats = $this->conn->fetchOne('SELECT uppercats FROM ' . Tables::categories() . ' WHERE id = 2');
        self::assertIsString($originalUppercats);
        $this->conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '999' WHERE id = 2");

        try {
            // "Nested" matches category 2 ("Nested Sub Album") by name only.
            $results = $this->service->getQuickSearchResultsNoCache('Nested', []);

            self::assertSame([], $results['items']);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET uppercats = ? WHERE id = 2', [$originalUppercats]);
        }
    }

    public function test_get_quick_search_results_no_cache_or_keyword_unions_two_tag_matches(): void
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

    public function test_get_quick_search_results_no_cache_evaluates_a_parenthesized_sub_group(): void
    {
        // "(nature)" is a nested QMultiToken sub-expression -- exercises
        // qsearchEval()'s own recursive branch (a non-QSingleToken child).
        // "nature" tags images 1,2,3; "family" tags image 1 only -- the
        // implicit AND between the group and the trailing word intersects
        // down to image 1.
        $results = $this->service->getQuickSearchResultsNoCache('(nature) family', []);

        self::assertSame([1], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_throws_when_a_qsearch_get_scopes_handler_returns_something_other_than_a_qsearch_get_scopes_instance(): void
    {
        // addEventHandler(), not addTypedHandler() -- a real plugin
        // handler is untyped from PHPStan's perspective, and this test
        // exercises dispatchChange()'s own runtime enforcement, not a
        // static one.
        $handler = static fn (): mixed => null;
        EventDispatcher::get()->addEventHandler(QsearchGetScopes::class, $handler);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('must return an instance of');

        try {
            $this->service->getQuickSearchResultsNoCache('family', []);
        } finally {
            EventDispatcher::get()->removeEventHandler(QsearchGetScopes::class, $handler);
        }
    }

    public function test_get_quick_search_results_no_cache_falls_back_when_a_hook_returns_non_array_items_and_qs(): void
    {
        $handler = static function (QsearchResults $event): QsearchResults {
            $searchResults = $event->searchResults;
            $searchResults['items'] = 'not-an-array';
            $searchResults['qs'] = 'not-an-array-either';

            return new QsearchResults($searchResults, $event->expression, $event->qsr);
        };
        EventDispatcher::get()->addTypedHandler(QsearchResults::class, $handler);

        try {
            $results = $this->service->getQuickSearchResultsNoCache('family', []);
        } finally {
            EventDispatcher::get()->removeEventHandler(QsearchResults::class, $handler);
        }

        // The hook only corrupts $searchResults['items']/['qs'] -- both
        // safely fall back ([] / the reconstructed default qs) -- but the
        // real tag/category match computed *before* the hook ran (the
        // fixture's own 'family' tag, id 3, linked to image 1) still
        // reaches the final result via $ids, independently of whatever
        // the hook did. Confirmed live: this method never discards that
        // real match just because the hook's own extra items were unusable.
        self::assertSame([1], $results['items']);
        self::assertSame(['q' => 'family', 'unmatched_terms' => []], $results['qs']);
    }

    public function test_get_quick_search_results_no_cache_merges_extra_numeric_ids_from_a_plugin_hook(): void
    {
        $handler = static function (QsearchResults $event): QsearchResults {
            $searchResults = $event->searchResults;
            $searchResults['items'] = ['4', 'not-numeric'];

            return new QsearchResults($searchResults, $event->expression, $event->qsr);
        };
        EventDispatcher::get()->addTypedHandler(QsearchResults::class, $handler);

        try {
            $results = $this->service->getQuickSearchResultsNoCache('family', []);
        } finally {
            EventDispatcher::get()->removeEventHandler(QsearchResults::class, $handler);
        }

        $items = $results['items'];
        sort($items);
        self::assertSame([1, 4], $items);
    }

    public function test_get_quick_search_results_no_cache_returns_early_for_an_empty_query(): void
    {
        $results = $this->service->getQuickSearchResultsNoCache('', []);

        self::assertSame([], $results['items']);
        self::assertSame(['q' => '', 'unmatched_terms' => []], $results['qs']);
    }

    public function test_get_quick_search_results_no_cache_works_with_a_non_default_calendar_datefield(): void
    {
        // calendarDatefield() !== 'date_creation' takes the else branch,
        // appending 'date' to $postedDateAliases instead of
        // $createdDateAliases -- proves the scope list still builds and the
        // search still functions correctly either way.
        CurrentConfig::current()->setCalendarDatefield('date_available');

        $results = $this->service->getQuickSearchResultsNoCache('family', []);

        self::assertSame([1], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_applies_a_custom_images_where_clause(): void
    {
        // "nature" alone matches images 1,2,3; a custom images_where
        // narrows that down further, proving the clause is genuinely
        // applied (not coincidentally the same result).
        $results = $this->service->getQuickSearchResultsNoCache('nature', ['images_where' => 'id = 2']);

        self::assertSame([2], $results['items']);
    }

    public function test_get_validated_search_info_calls_fatal_error_for_an_invalid_identifier(): void
    {
        $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fatalError: Invalid search identifier');

        $service->getValidatedSearchInfo('not-a-valid-identifier', null);
    }

    public function test_get_validated_search_info_calls_fatal_error_when_a_uuid_search_is_looked_up_by_bare_id(): void
    {
        $id = $this->repo->insertSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-fatalidtst', null);

        $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fatalError: this search is not reachable with its id, need the search_uuid instead');

        $service->getValidatedSearchInfo((string) $id, null);
    }

    public function test_get_validated_search_array_calls_bad_request_when_the_search_is_not_found(): void
    {
        $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('badRequest: this search identifier does not exist');

        // getSearchIdPattern()'s own search_uuid regex requires exactly 10
        // alphanumeric chars after the date ('doesnotexist' is 12) --
        // confirmed live, a too-long suffix doesn't match *any* recognised
        // pattern at all, so getValidatedSearchInfo()'s earlier "Invalid
        // search identifier" fatalError() fires first instead of ever
        // reaching the not-found badRequest() this test means to exercise.
        $service->getValidatedSearchArray('psk-20260712-doesnotexi', null);
    }

    public function test_get_search_results_calls_bad_request_when_the_search_identifier_does_not_exist(): void
    {
        $service = $this->makeServiceWithRenderer(new FatalSignalHtmlRenderer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('badRequest: this search identifier does not exist');

        $service->getSearchResults('psk-20260712-doesnotexist', null);
    }

    public function test_get_search_results_resolves_a_saved_quick_search_query(): void
    {
        $id = $this->repo->insertSearch(['q' => 'family'], '2026-07-12 00:00:00', 1, 'psk-20260712-quicksrch1', null);

        $results = $this->service->getSearchResults((string) $id, true, '');

        self::assertSame([1], $results['items']);
    }

    public function test_get_quick_search_results_no_cache_throws_when_the_default_users_language_resolves_to_an_inflector_class_that_does_not_implement_the_interface(): void
    {
        // See SearchServiceTestNotAnInflector's own docblock above for why
        // class_alias() is the only real way in.
        if (! class_exists('Piwigo\\Search\\Inflector\\Inflector_zz', false)) {
            class_alias(SearchServiceTestNotAnInflector::class, 'Piwigo\\Search\\Inflector\\Inflector_zz');
        }

        $originalLanguage = $this->conn->fetchOne('SELECT language FROM ' . Tables::userInfos() . ' WHERE user_id = 2');
        self::assertIsString($originalLanguage);
        // user_id=2 is CurrentConfig::defaultUserId()'s own default (the
        // guest account) -- getDefaultLanguage() reads *this* row, entirely
        // independent of CurrentUser (id=1 in this file's own setUp()).
        $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'zz_ZZ' WHERE user_id = 2");
        \Piwigo\Core\ProcessCache::forgetStatic('default_user');

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Inflector_zz does not implement InflectorInterface');

            $this->service->getQuickSearchResultsNoCache('nature', []);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . ' SET language = ? WHERE user_id = 2', [$originalLanguage]);
            \Piwigo\Core\ProcessCache::forgetStatic('default_user');
        }
    }

    // A test forcing getAvailableSearchUuid()'s internal retry-on-collision
    // branch to fire deterministically on the very first candidate would
    // need to substitute SearchRepository::countByUuid()'s behavior --
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
