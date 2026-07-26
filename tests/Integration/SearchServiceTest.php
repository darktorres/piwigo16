<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Bootstrap\RedirectService;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Search\QSingleToken;
    use Piwigo\Search\SearchRepository;
    use Piwigo\Search\SearchService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

/**
 * Same fixture shape as CategoryRepositoryTest/SearchRepositoryTest:
 * images 1-5 (1,2,3 in category 1, 4,5 in category 2, all 200x150,
 * fixture-photo-N.jpg / "Photo N"), tags 1 "nature", 2 "travel", 3
 * "family" (image 1 has all 3 tags, images 2/3 have tag 1 only).
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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new SearchRepository($this->conn);

        CurrentUser::set(User::fromUserArray(self::realisticUserGlobal()));
        CurrentConfig::setDefaultFiltersViews(null);
        CurrentConfig::setFiltersViews([
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
        CurrentConfig::setOrderBy('ORDER BY id ASC');
        CurrentConfig::setCalendarDatefield('date_creation');
        CurrentConfig::setQuickSearchIncludeSubAlbums(false);
        CurrentConfig::setRateEnabled(true);

        $this->service = new SearchService(
            $this->repo,
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
            new CategoryService(
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
                new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class))
            ),
            new MailService(),
            new HtmlService(),
            new RedirectService()
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Piwigo\Cache\CachePools::searchResults()->clear();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private static function realisticUserGlobal(): array
    {
        // Matches getuserdata()'s own guaranteed shape -- an incomplete
        // fixture (e.g. missing 'level') lets getSqlConditionFandF()'s
        // forbidden_images fallthrough build a malformed 'level<=' fragment
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
        CurrentUser::set(User::fromUserArray(array_merge(self::realisticUserGlobal(), ['forbidden_categories' => '2'])));

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
}
}
