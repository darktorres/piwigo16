<?php

declare(strict_types=1);

// SearchService calls several real, stable, already-migrated free functions
// that need more bootstrap ($user/$conf-driven access-level checks, the
// Category domain's own free-function delegate) than this isolated
// integration test wants to depend on. Same "minimal stub to load
// standalone" pattern as CategoryServiceTest.php -- is_admin()/
// is_classic_user() bodies copied verbatim from CategoryServiceTest.php/
// CommentServiceTest.php (function_exists() guards mean whichever
// Integration test file's stub loads first wins for the whole run, so
// every file declaring these must keep the bodies identical).
// trigger_change()/trigger_notify() are always available now via composer
// autoload.files (src/Piwigo/PluginConfig/functions.php), pure
// passthroughs with no handlers registered, so no local stubs are needed
// for them anymore.
//
// get_subcat_ids() is NOT stubbed -- always available now via composer
// autoload.files (src/Piwigo/Category/functions.php, P23 batch 8c), no
// explicit require needed. functions_search.inc.php itself is gone (P23
// batch 8c) -- SearchService now calls the real Piwigo\Search\SearchService
// methods directly; the QST_* bitmask flags it needs are class constants
// on Piwigo\Search\QSingleToken (P23 batch 8f-4), autoloaded.
//
// get_image_ids_for_tags()'s own stub was removed (P23 batch 8c) --
// SearchService now calls the real Piwigo\Tag\TagService::getImageIdsForTags()
// directly, which this Integration test's real DB connection satisfies
// without a stub (same DBAL-backed path this test already exercises via
// PermissionService::getSqlConditionFandF() elsewhere).
namespace {
    if (! defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', './');
    }

    // trigger_change()/trigger_notify() are always available now via
    // composer autoload.files (src/Piwigo/PluginConfig/functions.php), pure
    // passthroughs with no handlers registered, so no local stubs are
    // needed for them here.

    // is_admin()/is_classic_user() -- SearchService/SearchFilterRenderer now
    // call Piwigo\Auth\AccessControl::isAdmin()/isClassicUser() directly
    // (P23 batch 8d), which read Piwigo\Users\CurrentUser (Legacy Coupling
    // Retirement Track A batch A3); realisticUserGlobal() below sets
    // 'status' => 'normal' to match this file's old defaults (not admin,
    // is a classic user).

    // conf_get_param() -- P23 batch 8f-4: the function stub is gone.
    // SearchService/SearchFilterRenderer now call
    // Piwigo\Config\ConfigDb::confGetParam() directly, a real static
    // method with the same pure `global $conf` read the old stub
    // duplicated, so this isolated test needs no replacement at all.

    if (! function_exists('safe_unserialize')) {
        // Copied verbatim from the legacy include/functions.inc.php
        // (deleted in P23 batch 8f-4).
        /**
         * @param  array<int|string, mixed>|string  $value
         * @return mixed
         */
        function safe_unserialize($value)
        {
            if (is_string($value)) {
                return unserialize($value);
            }

            return $value;
        }
    }

    // get_default_language() -- SearchService now calls the real
    // Piwigo\Users\UserService::getDefaultLanguage() directly (P23 batch
    // 8d); this test's real DB connection resolves the fixture's default
    // user language ('en_UK'), whose 2-letter prefix still matches
    // Piwigo\Search\Inflector\Inflector_en the same way the old fixed 'en'
    // stub did.

    if (! function_exists('tag_alpha_compare')) {
        // Copied verbatim from TagServiceTest.php -- same simplification
        // precedent (plain alphabetical comparison stands in for the real
        // pwg_transliterate()-backed HtmlService::tagAlphaCompare()).
        /**
         * @param  array<string, mixed>  $a
         * @param  array<string, mixed>  $b
         */
        function tag_alpha_compare(array $a, array $b): int
        {
            $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
            $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

            return strcmp($name_a, $name_b);
        }
    }

    // generate_key()'s own stub was removed (P23 batch 8c) -- SearchService
    // now calls the real Piwigo\Session\SessionService::get()->generateKey()
    // directly, which this Integration test's real DB connection satisfies
    // without a stub.

    // QST_* bitmask flags -- P23 batch 8f-4: now class constants on
    // Piwigo\Search\QSingleToken (autoloaded with the class itself), so
    // the inline define() block this file used to carry is gone.
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Cache\PersistentFileCache;
    use Piwigo\Config\Config;
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

    private string $cacheDir;

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
        $this->repo = new SearchRepository($this->conn);

        // Self-contained scratch cache dir under this project's own _data/
        // (never the real _data/cache/ the dev app uses) -- created here,
        // torn down below, so this test never leaves cache files behind.
        $this->cacheDir = dirname(__DIR__, 2) . '/_data/search-service-test-cache';
        @mkdir($this->cacheDir . '/cache', 0o777, true);

        CurrentUser::set(User::fromUserArray(self::realisticUserGlobal()));
        $GLOBALS['filter'] = [];
        $GLOBALS['conf'] = [
            'data_location' => '_data/search-service-test-cache/',
            'default_filters_views' => '',
            'filters_views' => serialize([
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
            ]),
            'order_by' => 'ORDER BY id ASC',
            'calendar_datefield' => 'date_creation',
            'quick_search_include_sub_albums' => false,
            'rate' => true,
        ];

        $this->service = new SearchService(
            $this->repo,
            new PermissionService(new PermissionRepository($this->conn), new GroupRepository($this->conn)),
            new PersistentFileCache(),
            new MailService(),
            new HtmlService()
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $found = glob($this->cacheDir . '/cache/*.cache');
        $files = $found !== false ? $found : [];
        foreach ($files as $file) {
            @unlink($file);
        }

        @rmdir($this->cacheDir . '/cache');
        @rmdir($this->cacheDir);

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
        $this->repo->insertSearch(serialize(['q' => 'nature']), '2026-07-12 00:00:00', 1, 'psk-20260712-infotest01', null);

        $info = $this->service->getSearchInfo('psk-20260712-infotest01');

        self::assertIsArray($info);
        self::assertSame('psk-20260712-infotest01', $info['search_uuid']);
    }

    public function test_get_search_info_returns_null_for_an_invalid_identifier(): void
    {
        self::assertNull($this->service->getSearchInfo('garbage'));
    }

    public function test_get_search_array_round_trips_the_serialized_rules(): void
    {
        $rules = ['q' => 'nature', 'fields' => ['allwords' => ['words' => ['nature']]]];
        $this->repo->insertSearch(serialize($rules), '2026-07-12 00:00:00', 1, 'psk-20260712-arraytest0', null);

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
        $this->repo->insertSearch(serialize(['q' => 'x']), '2026-07-12 00:00:00', null, $uuid, null);

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

    public function test_get_quick_search_results_caches_across_calls(): void
    {
        $first = $this->service->getQuickSearchResults('family', []);
        self::assertSame([1], $first['items']);

        $cachedFound = glob($this->cacheDir . '/cache/*.cache');
        self::assertNotSame([], $cachedFound !== false ? $cachedFound : []);

        $second = $this->service->getQuickSearchResults('family', []);
        self::assertSame($first['items'], $second['items']);
        self::assertArrayNotHasKey('debug', $second);
    }
}
}
