<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\FilterViewDefinition;
use Piwigo\Config\FilterViewsSelection;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SortRenderer;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\Event\QsearchGetImagesSqlScopes;
use Piwigo\Search\Event\QsearchResults;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\QExpression;
use Piwigo\Search\QResults;
use Piwigo\Search\QsearchClause;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Search\SearchServiceTestFatalSignalHtmlRenderer;
use Piwigo\Tests\Unit\Search\SearchServiceTestNotAnInflector;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Piwigo\Search\SearchService -- has its own dedicated
 * tests/Integration/SearchServiceTest.php (~100 tests); this ports the
 * same scenarios down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern for the repository, plus a real
 * Kernel::boot() (PermissionServiceTest.php's/PermalinkServiceTest.php's
 * own established beforeEach()/afterEach() precedent) for the rest of
 * this service's 16-dependency constructor -- CategoryService,
 * PermissionService, UserService and RedirectService all need real,
 * container-wired collaborators the way the Integration original builds
 * them, not a bare Kernel-free construction.
 *
 * Same fixture shape as CategoryRepositoryTest/SearchRepositoryTest:
 * images 1-5 (1,2,3 in category 1 "Sample Album", 4,5 in category 2
 * "Nested Sub Album" -- a child of category 1, all 200x150,
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
 * `ini_set('pcre.backtrack_limit', '0')`.
 * SearchService::getValidatedSearchInfo()/getValidatedSearchArray()'s
 * `fatalError()`/`badRequest()` gates ARE exercised below, via
 * {@see SearchServiceTestFatalSignalHtmlRenderer} instead of the real
 * HtmlService, since those methods are typed `: never` and would
 * otherwise attempt a real header()+exit() redirect.
 *
 * This class has no bulk-delete-shaped method of its own (it's a
 * read/search-only service, plus one INSERT via insertSavedSearch()) --
 * the shared-fixture-corruption risk documented on GroupRepositoryTest.php/
 * TagRepositoryTest.php (a mutated bulk delete()/removeAll*() wiping real
 * fixture rows across pest --mutate's repeated re-execution) doesn't
 * apply here the same way; still worth a post-mutate fixture check per
 * this project's own standing mitigation, since `search` table rows
 * accumulate from insertSavedSearch() calls either way.
 */
function searchServiceTestConn(): Connection
{
    return DbConnection::build();
}

function searchServiceTestRepo(): SearchRepository
{
    return new SearchRepository(EntityManagerFactory::build(searchServiceTestConn()));
}

/**
 * InnoDB's FULLTEXT index is not updated synchronously on INSERT -- new
 * words sit in an in-memory cache (`innodb_ft_cache_size`) until the
 * table is closed, so a MATCH()/AGAINST() query run immediately after
 * inserting a fresh row can miss it. Only tests that insert a row and
 * then search for it by a >3-char term
 * (qsearchGetTextTokenSearchSql()'s own FULLTEXT threshold) in the same
 * request are affected.
 *
 * FLUSH TABLES, not OPTIMIZE TABLE -- this used to run a real
 * `OPTIMIZE TABLE $table`, which does force the sync but, on InnoDB, is
 * mapped internally to `ALTER TABLE ... FORCE` -- a genuine table
 * rebuild that bumps the table's own metadata/definition version. Under
 * --parallel, this broadly disrupted every OTHER worker's own
 * already-prepared statement against `categories`/`tags` (both real
 * FULLTEXT-indexed tables many other Unit test files also touch): the
 * next execution of that stale statement throws mysqli's "Table
 * definition has changed, please retry transaction" -- reproduced live
 * this session (TelemetryServiceTest's own plain `COUNT(c.id) FROM
 * categories c` query), traced via a live `information_schema.processlist`
 * capture that caught this exact `OPTIMIZE TABLE categories` statement
 * running mid-suite. `FLUSH TABLES $table` closes and reopens the
 * table's cached handle -- InnoDB's own documented mechanism for
 * syncing the FULLTEXT word cache without a schema-level rebuild -- so
 * it achieves the identical visibility guarantee (confirmed live: all 4
 * real call sites below, 131/131 tests, across several repeated runs)
 * without bumping the table definition version other sessions rely on.
 */
function searchServiceTestFlushFulltext(Connection $conn, string $table): void
{
    $conn->executeStatement('FLUSH TABLES ' . $table);
}

function searchServiceTestFilterState(): FilterState
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
 * the same shared instance -- forget() here is actually observed by a
 * service built via searchServiceTestService()/searchServiceTestMakeService()
 * internally.
 */
function searchServiceTestProcessCache(): ProcessCache
{
    $processCache = Kernel::container()->get(ProcessCache::class);
    if (! $processCache instanceof ProcessCache) {
        throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
    }

    return $processCache;
}

function searchServiceTestUserService(): UserService
{
    $userService = Kernel::container()->get(UserService::class);
    if (! $userService instanceof UserService) {
        throw new LogicException('Container returned an unexpected type for ' . UserService::class);
    }

    return $userService;
}

function searchServiceTestTagService(): TagService
{
    $tagService = Kernel::container()->get(TagService::class);
    if (! $tagService instanceof TagService) {
        throw new LogicException('Container returned an unexpected type for ' . TagService::class);
    }

    return $tagService;
}

function searchServiceTestImageService(): ImageService
{
    $imageService = Kernel::container()->get(ImageService::class);
    if (! $imageService instanceof ImageService) {
        throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
    }

    return $imageService;
}

function searchServiceTestPreferencesService(): PreferencesService
{
    $preferencesService = Kernel::container()->get(PreferencesService::class);
    if (! $preferencesService instanceof PreferencesService) {
        throw new LogicException('Container returned an unexpected type for ' . PreferencesService::class);
    }

    return $preferencesService;
}

function searchServiceTestSearchResultsCachePool(): SearchResultsCachePool
{
    $searchResultsCachePool = Kernel::container()->get(SearchResultsCachePool::class);
    if (! $searchResultsCachePool instanceof SearchResultsCachePool) {
        throw new LogicException('Container returned an unexpected type for ' . SearchResultsCachePool::class);
    }

    return $searchResultsCachePool;
}

/**
 * Same dependency graph as beforeEach()'s own default service below,
 * with a caller-supplied HtmlRenderingInterface (for observing the
 * fatalError()/badRequest() gates without a real header()+exit()
 * redirect). Builds its own $repo off the SAME connection as every
 * other collaborator here, deliberately -- not a separate
 * searchServiceTestRepo() call: composer test's own parallel runner
 * put this file's 100+ tests worth of DriverException("Too many
 * connections") failures under real load, confirmed live as this
 * exact duplication (a 2nd, separate DbConnection::build() call per
 * searchServiceTestService() invocation, on top of every test's own
 * direct searchServiceTestConn()/searchServiceTestRepo() calls for
 * setup/cleanup).
 */
function searchServiceTestMakeService(HtmlRenderingInterface $htmlRenderer): SearchService
{
    $conn = searchServiceTestConn();
    $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());

    return new SearchService(
        $accessLevelChecker,
        new SearchRepository(EntityManagerFactory::build($conn)),
        new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), searchServiceTestFilterState(), $accessLevelChecker),
        new CategoryService(
            LangTestFactory::get(),
            new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()),
            new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), searchServiceTestFilterState(), $accessLevelChecker),
            CurrentConfigTestFactory::get(),
            new EventDispatcher(),
            TranslatorTestFactory::get(),
            $accessLevelChecker),
        $htmlRenderer,
        new RedirectService(LangTestFactory::get(), searchServiceTestUserService(), EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get())),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
        EventDispatcherTestFactory::get(),
        CurrentUserTestFactory::get(),
        CurrentConfigTestFactory::get(),
        new SortRenderer($conn),
        searchServiceTestTagService(),
        searchServiceTestImageService(),
        searchServiceTestUserService(),
        searchServiceTestPreferencesService(),
        searchServiceTestSearchResultsCachePool(),
    );
}

function searchServiceTestService(): SearchService
{
    return searchServiceTestMakeService(HtmlServiceTestFactory::build());
}

function searchServiceTestServiceWithRenderer(HtmlRenderingInterface $htmlRenderer): SearchService
{
    return searchServiceTestMakeService($htmlRenderer);
}

/**
 * `NOW() - INTERVAL n UNIT` -- MySQL's own bare-token interval syntax,
 * not portable: PostgreSQL requires the interval as a quoted string
 * (`INTERVAL 'n unit'`), rejecting the bare-token form outright with a
 * syntax error. Used by the date_posted/date_created preset tests below
 * to backdate fixture rows relative to the DB server's real wall clock.
 */
function searchServiceTestNowMinusInterval(int $amount, string $unit): string
{
    return DbCredentials::fromEnv()->driver === 'pgsql'
        ? "NOW() - INTERVAL '{$amount} {$unit}'"
        : "NOW() - INTERVAL {$amount} {$unit}";
}

/**
 * @return array<string, mixed>
 */
function searchServiceTestRealisticUserGlobal(): array
{
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

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));

    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->reset();
    ConfigLoader::applyDefaults();
    ConfigLoader::applyEnvOverrides();

    CurrentUserTestFactory::get()->set(User::fromUserArray(searchServiceTestRealisticUserGlobal()));
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
    $currentConfig->orderBy = PhotoSortOrder::fromConfigFragment('ORDER BY id ASC');
    $currentConfig->calendarDatefield = 'date_creation';
    $currentConfig->quickSearchIncludeSubAlbums = false;
    $currentConfig->rateEnabled = true;
});

afterEach(function (): void {
    // insertSavedSearch() calls below use literal 'psk-2026...' uuids
    // (matching the Integration original's own hardcoded style) plus
    // getAvailableSearchUuid()'s own real 'psk-{8-digit date}-{10 chars}'
    // generated ones -- this whole DB is shared/persistent across the
    // Unit suite (and across every pest --mutate re-execution), unlike a
    // real Integration run's own per-class resetDatabase(), so leftover
    // rows accumulate without this cleanup.
    //
    // REGEXP '^psk-[0-9]{8}-', not a broader `LIKE 'psk-%'` -- confirmed
    // live: composer test's own parallel runner puts this file and
    // SearchRepositoryTest.php in different worker processes against the
    // SAME real, shared DB, and a `LIKE 'psk-%'` here matched (and
    // deleted) that file's own still-in-flight 'psk-rt...'-shaped rows
    // mid-test, causing real, intermittent getSearchArray()/
    // getSearchInfo() failures in THIS file. Every real row this file's
    // own tests ever produce is genuinely date-shaped (either a literal
    // 'psk-2026...' or the real generator's own output), so this narrower
    // pattern loses no real coverage.
    searchServiceTestConn()
        ->executeStatement('DELETE FROM search' . " WHERE search_uuid REGEXP '^psk-[0-9]{8}-'");
    searchServiceTestSearchResultsCachePool()
        ->clear();
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    LangTestFactory::get()->reset();
    TranslatorTestFactory::get()->reset();
    Kernel::reset();
});

test('getSearchIdPattern() recognizes a uuid', function (): void {
    expect(SearchService::getSearchIdPattern('psk-20260712-abcdefghij'))->toBe('search_uuid = ?');
});

test('getSearchIdPattern() recognizes a numeric id', function (): void {
    expect(SearchService::getSearchIdPattern(42))->toBe('id = ?');
});

test('getSearchIdPattern() rejects garbage', function (): void {
    expect(SearchService::getSearchIdPattern('not-a-valid-identifier'))->toBeNull();
});

test('getSearchInfo() returns the stored row', function (): void {
    searchServiceTestRepo()->insertSavedSearch([
        'q' => 'nature',
    ], '2026-07-12 00:00:00', 1, 'psk-20260712-infotest01', null);

    $info = searchServiceTestService()
        ->getSearchInfo('psk-20260712-infotest01');

    if (! $info instanceof Search) {
        throw new LogicException('expected a Search projection, got null');
    }

    expect($info->searchUuid)
        ->toBe('psk-20260712-infotest01');
});

test('getSearchInfo() returns null for an invalid identifier', function (): void {
    expect(searchServiceTestService()->getSearchInfo('garbage'))
        ->toBeNull();
});

test('getSearchArray() round-trips the json-encoded rules', function (): void {
    $rules = [
        'q' => 'nature',
        'fields' => [
            'allwords' => [
                'words' => ['nature'],
            ],
        ],
    ];
    searchServiceTestRepo()
        ->insertSavedSearch($rules, '2026-07-12 00:00:00', 1, 'psk-20260712-arraytest0', null);

    $decoded = searchServiceTestService()
        ->getSearchArray('psk-20260712-arraytest0');

    expect($decoded)
        ->toBe($rules);
});

test('getSearchArray() returns false for a missing search', function (): void {
    expect(searchServiceTestService()->getSearchArray('psk-20260712-nosuchuid0'))
        ->toBeFalse();
});

test('getSearchArray() returns false, not true, for a real row whose rules column is genuinely NULL', function (): void {
    // insertSavedSearch()'s own $rules param is never nullable, so a
    // real NULL `rules` column only happens via a raw, non-service
    // write (a legacy/corrupted row) -- inserted directly here to reach
    // it. Search::$rules stays null (not decoded to []) when the raw
    // column is NULL, so `$search->rules ?? false`'s own fallback value
    // is what this test targets, not just "is rules present".
    searchServiceTestConn()
        ->executeStatement(
            "INSERT INTO search (search_uuid, created_on, created_by, rules) VALUES ('psk-20260712-nullrulz1', '2026-07-12 00:00:00', 1, NULL)"
        );

    expect(searchServiceTestService()->getSearchArray('psk-20260712-nullrulz1'))
        ->toBeFalse();
});

test('getAvailableSearchUuid() matches the expected shape', function (): void {
    $uuid = searchServiceTestService()
        ->getAvailableSearchUuid();

    // Case-insensitive, matching SearchService::getSearchIdPattern()'s
    // own regex -- generate_key()'s base64-derived charset includes
    // uppercase letters.
    expect($uuid)
        ->toMatch('/^psk-\d{8}-[a-z0-9]{10}$/i');
});

test('getAvailableSearchUuid() skips a colliding uuid', function (): void {
    $service = searchServiceTestService();
    $uuid = $service->getAvailableSearchUuid();
    searchServiceTestRepo()
        ->insertSavedSearch([
            'q' => 'x',
        ], '2026-07-12 00:00:00', null, $uuid, null);

    $next = $service->getAvailableSearchUuid();

    expect($next)
        ->not->toBe($uuid)
        ->and(searchServiceTestRepo()->countSavedSearchByUuid($next))
        ->toBe(0);
});

test('saveSearch() persists the rules under a fresh uuid and returns a matching uuid+url pair', function (): void {
    // saveSearch() has no dedicated test anywhere -- not even in the
    // Integration original (a real spec gap, same shape as
    // TagRepositoryTest.php's own findByIdsOrAll() finding). 2 real
    // production callers (`Controller\Api\Images\
    // ImageFilteredSearchCreateController`, `Controller\SearchController`)
    // depend on its own [uuid, url] contract.
    //
    // saveSearch() also unconditionally calls
    // PreferencesService::updateParam() for a real (non-guest,
    // non-generic) user like this file's own default -- every test
    // below that calls it saves/restores user_infos.preferences for
    // that reason, not just the one test dedicated to that behavior
    // itself. Confirmed the hard way: this file's own 2 earlier drafts
    // of this test and the forkedFrom test below silently corrupted
    // user_id=1's real preferences column for the rest of the suite.
    $conn = searchServiceTestConn();
    $originalPreferences = $conn->fetchOne('SELECT preferences FROM user_infos WHERE user_id = 1');

    try {
        $rules = [
            'q' => 'nature',
        ];

        [$uuid, $url] = searchServiceTestService()->saveSearch($rules, UrlServiceTestFactory::build());

        expect($uuid)
            ->toMatch('/^psk-\d{8}-[a-z0-9]{10}$/i')
            ->and($url)
            ->toContain($uuid);

        $info = searchServiceTestService()
            ->getSearchInfo($uuid);
        if (! $info instanceof Search) {
            throw new LogicException('expected a persisted Search row, got null');
        }

        expect($info->rules)
            ->toBe($rules)
            ->and($info->createdBy)
            ->toBe(1)
            ->and($info->forkedFrom)
            ->toBeNull();
    } finally {
        $conn->executeStatement('UPDATE user_infos SET preferences = ? WHERE user_id = 1', [$originalPreferences]);
    }
});

test('saveSearch() threads a real forkedFrom id into the persisted row', function (): void {
    // Same saveSearch()-always-writes-preferences reasoning as the
    // sibling test above.
    $conn = searchServiceTestConn();
    $originalPreferences = $conn->fetchOne('SELECT preferences FROM user_infos WHERE user_id = 1');

    try {
        $originalId = searchServiceTestRepo()
            ->insertSavedSearch([
                'q' => 'nature',
            ], '2026-07-12 00:00:00', 1, 'psk-20260712-forkorigin', null);

        [$uuid] = searchServiceTestService()->saveSearch([
            'q' => 'nature refined',
        ], UrlServiceTestFactory::build(), $originalId);

        $info = searchServiceTestService()
            ->getSearchInfo($uuid);
        if (! $info instanceof Search) {
            throw new LogicException('expected a persisted Search row, got null');
        }

        expect($info->forkedFrom)
            ->toBe($originalId);
    } finally {
        $conn->executeStatement('UPDATE user_infos SET preferences = ? WHERE user_id = 1', [$originalPreferences]);
    }
});

test('saveSearch() updates the current user\'s gallery_search_filters preference for a real registered user', function (): void {
    // The `! isAGuest() && ! isGeneric()` gate -- beforeEach()'s default
    // user (status 'normal') is neither, so this must actually reach
    // PreferencesService::updateParam(), persisting the search's own
    // top-level field names into user_infos.preferences (one combined
    // JSON column, not a per-param row).
    $conn = searchServiceTestConn();
    $originalPreferences = $conn->fetchOne('SELECT preferences FROM user_infos WHERE user_id = 1');

    try {
        searchServiceTestService()->saveSearch([
            'q' => 'x',
            'fields' => [
                'tags' => [
                    'words' => [1],
                ],
                'author' => [
                    'words' => ['a'],
                ],
            ],
        ], UrlServiceTestFactory::build());

        $stored = $conn->fetchOne('SELECT preferences FROM user_infos WHERE user_id = 1');
        if (! is_string($stored)) {
            throw new LogicException('expected a real JSON preferences string, got ' . get_debug_type($stored));
        }

        $decoded = json_decode($stored, true);
        if (! is_array($decoded)) {
            throw new LogicException('expected preferences to decode to an array, got ' . get_debug_type($decoded));
        }

        expect($decoded['gallery_search_filters'] ?? null)->toBe(['tags', 'author']);
    } finally {
        $conn->executeStatement('UPDATE user_infos SET preferences = ? WHERE user_id = 1', [$originalPreferences]);
    }
});

test('splitAllwords() splits on whitespace', function (): void {
    expect(SearchService::splitAllwords('nature travel'))->toBe(['nature', 'travel']);
});

test('splitAllwords() deduplicates a genuinely repeated word', function (): void {
    // Every other test's own input has zero real duplicates, so
    // array_unique()'s own effect (as opposed to just "the words got
    // split") was never actually observed.
    expect(SearchService::splitAllwords('nature travel nature'))->toBe(['nature', 'travel']);
});

test('splitAllwords() returns null for blank input', function (): void {
    expect(SearchService::splitAllwords('   '))->toBeNull();
});

test('splitAllwords() drops or space-replaces every one of its 26 special characters correctly', function (): void {
    // One word between every consecutive pair of the real
    // $dropCharMatch/$dropCharReplace entries, in their own real
    // array order -- removing (RemoveArrayItem) or reordering either
    // array shifts every later char's own replacement out of
    // alignment with str_replace()'s positional pairing, which this
    // single pass-through is sensitive to end to end. Backtick/
    // apostrophe/backslash are real *removals* (empty-string
    // replacement, no word split), not space-replacements -- confirmed
    // live via a hand-run of the real $dropCharMatch/$dropCharReplace
    // pair: 'g`h\'i' collapses to one word 'ghi', not 3.
    $input = 'a;b&c(d)e<f>g`h\'i"j|k,l@m?n%o. p[q]r{s}t:u\\v/w=x\'y!z*aa';

    expect(SearchService::splitAllwords($input))->toBe([
        'a', 'b', 'c', 'd', 'e', 'f', 'ghi', 'j', 'k', 'l', 'm', 'n', 'o',
        'p', 'q', 'r', 's', 't', 'uv', 'w', 'xy', 'z', 'aa',
    ]);
});

test('splitAllwords() throws when preg_split() hits the backtrack limit', function (): void {
    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        expect(static fn (): ?array => SearchService::splitAllwords('nature travel'))
            ->toThrow(Exception::class, 'splitAllwords(): preg_split() failed');
    } finally {
        ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
    }
});

test('qsearchGetTextTokenSearchSql() is injection-safe', function (): void {
    // A term with a single quote must not break out of the generated
    // REGEXP/MATCH clauses -- proven by actually executing them (with
    // their own bound values) against the real fixture DB, not just
    // eyeballing the SQL.
    $token = new QSingleToken("nature's", 0, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'images_fts');

    expect($clauses)
        ->not->toBe([]);

    $count = searchServiceTestConn()
        ->executeQuery(
            'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
            $values
        )->fetchOne();

    expect(is_numeric($count) ? (int) $count : null)
        ->toBe(0);
});

test('getRegularSearchResults() filters by width and height', function (): void {
    $search = [
        'fields' => [
            'width_min' => 100,
            'width_max' => 300,
            'height_min' => 100,
            'height_max' => 300,
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by ratio', function (): void {
    // every fixture image is 200x150 -- ratio 1.333, the "Landscape"
    // bucket (1.05 < ratio < 2).
    $search = [
        'fields' => [
            'ratios' => ['Landscape'],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getRegularSearchResults() filters by category', function (): void {
    $search = [
        'fields' => [
            'cat' => [
                'words' => [1],
                'sub_inc' => false,
            ],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('getRegularSearchResults() filters by tags', function (): void {
    $search = [
        'fields' => [
            'tags' => [
                'words' => [1],
                'mode' => 'AND',
            ],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('getRegularSearchResults() combines two filters via intersection', function (): void {
    // cat=1 -> {1,2,3}; tags=1 -> {1,2,3} -- intersection is still
    // {1,2,3}, proving the multi-filter array_intersect() path (not just
    // the single-filter reset() shortcut) produces a valid list<int>.
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

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('getRegularSearchResults() custom search clause', function (): void {
    $results = searchServiceTestService()
        ->getRegularSearchResults([], SqlCondition::fromRawSql('i.id = 1'));

    expect($results['items'])->toBe([1]);
});

test('getRegularSearchResults() returns empty for no filters', function (): void {
    $results = searchServiceTestService()
        ->getRegularSearchResults([]);

    expect($results['items'])->toBe([])
        ->and($results['search_details']['has_filters_filled'])->toBeFalse();
});

test('getRegularSearchResults() skips a criterion entirely when its own display filter access is denied', function (string $filterKey, array $fields): void {
    // Every one of getRegularSearchResults()'s ~14 near-identical
    // criterion blocks gates on its own
    // `(bool) ($displayFilters[$filterKey]['access'] ?? false)` --
    // beforeEach() grants every filter 'everybody' access, so none of
    // this file's own "filters by X" tests can ever observe THIS
    // specific criterion's own access gate being denied (as opposed to
    // some other criterion's). Forcing exactly one filter to
    // 'admins-only' (denied, since beforeEach()'s user status is
    // 'normal') while every other filter stays granted proves each
    // criterion's own gate independently, not just "some gate somewhere
    // works".
    $currentConfig = CurrentConfigTestFactory::get();
    $filtersViews = $currentConfig->filtersViews;
    if (! $filtersViews instanceof FilterViewsSelection) {
        throw new RuntimeException('beforeEach() always sets a real, non-null filtersViews.');
    }
    $filters = $filtersViews->filters;
    $filters[$filterKey] = new FilterViewDefinition(access: 'admins-only', default: false);
    $currentConfig->filtersViews = new FilterViewsSelection(filters: $filters, lastFiltersConf: $filtersViews->lastFiltersConf);

    $results = searchServiceTestService()
        ->getRegularSearchResults([
            'fields' => $fields,
        ]);

    expect($results['search_details']['has_filters_filled'])->toBeFalse();
})->with([
    'expert' => [
        'expert', [
            'expert' => [
                'string' => 'x',
            ],
        ]],
    'allwords' => [
        'words', [
            'allwords' => [
                'words' => ['x'],
                'fields' => ['name'],
            ],
        ]],
    'author' => [
        'author', [
            'author' => [
                'words' => ['x'],
            ],
        ]],
    'filetypes' => [
        'file_type', [
            'filetypes' => ['jpg'],
        ]],
    'added_by' => [
        'added_by', [
            'added_by' => [1],
        ]],
    'cat' => [
        'album', [
            'cat' => [
                'words' => [1],
                'sub_inc' => false,
            ],
        ]],
    'date_posted' => [
        'post_date', [
            'date_posted' => [
                'preset' => '24h',
            ],
        ]],
    'date_created' => [
        'creation_date', [
            'date_created' => [
                'preset' => '7d',
            ],
        ]],
    'ratios' => [
        'ratio', [
            'ratios' => ['Landscape'],
        ]],
    'ratings' => [
        'rating', [
            'ratings' => ['5'],
        ]],
    'filesize' => [
        'file_size', [
            'filesize_min' => 1,
            'filesize_max' => 2,
        ]],
    'height' => [
        'height', [
            'height_min' => 100,
            'height_max' => 300,
        ]],
    'width' => [
        'width', [
            'width_min' => 100,
            'width_max' => 300,
        ]],
    'tags' => [
        'tags', [
            'tags' => [
                'words' => [1],
                'mode' => 'AND',
            ],
        ]],
]);

test('getRegularSearchResults() falls back to defaultFiltersViews() when filtersViews() is null', function (): void {
    // beforeEach() always sets a real, non-null filtersViews() --
    // CurrentConfig::defaultFiltersViews()'s own right-hand fallback
    // (CurrentConfig's own DEFAULT_FILTERS_VIEWS constant, 'everybody'
    // access for 'tags' among others) is only reachable when
    // filtersViews() is null.
    CurrentConfigTestFactory::get()->filtersViews = null;

    $results = searchServiceTestService()
        ->getRegularSearchResults([
            'fields' => [
                'tags' => [
                    'words' => [1],
                    'mode' => 'AND',
                ],
            ],
        ]);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by expert string', function (): void {
    // The 'expert' criterion delegates to getQuickSearchResults() itself
    // -- "family" resolves via the tag-name quick-search path to image 1.
    $search = [
        'fields' => [
            'expert' => [
                'string' => 'family',
            ],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    expect($results['items'])->toBe([1])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by author field with no match', function (): void {
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

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    expect($results['items'])->toBe([])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by filetypes', function (): void {
    // every fixture image's path ends in .jpg. 2 filetypes (not 1) --
    // each builds its own indexed `:filetype{$i}` clause/param pair, so
    // this is what actually exercises the loop's own per-index param
    // naming, not just its single-element degenerate case.
    //
    // Filtered down to known-real ids rather than an unfiltered toBe(),
    // since another --parallel worker's own FULLTEXT-deadlock-exempted
    // disposable image (tagServiceTestDisposableImageId()'s own path
    // also ends in .jpg) can transiently match this same filetypes
    // scope too.
    $search = [
        'fields' => [
            'filetypes' => ['png', 'jpg'],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = array_values(array_intersect($results['items'], [1, 2, 3, 4, 5]));
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by added_by', function (): void {
    // every fixture image has added_by = 1.
    $search = [
        'fields' => [
            'added_by' => [1],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by date_posted preset', function (): void {
    // NOW()-relative rather than a hardcoded literal, so this stays
    // correct regardless of the real wall-clock date the suite runs on.
    $conn = searchServiceTestConn();
    $conn->executeStatement(
        'UPDATE images SET date_available = ' . searchServiceTestNowMinusInterval(1, 'HOUR') . ' WHERE id IN (1, 2)'
    );
    $conn->executeStatement(
        'UPDATE images SET date_available = ' . searchServiceTestNowMinusInterval(30, 'HOUR') . ' WHERE id IN (3, 4, 5)'
    );

    try {
        $search = [
            'fields' => [
                'date_posted' => [
                    'preset' => '24h',
                ],
            ],
        ];
        $results = searchServiceTestService()
            ->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        expect($items)
            ->toBe([1, 2])
            ->and($results['search_details']['has_filters_filled'])->toBeTrue();
    } finally {
        $conn->executeStatement(
            "UPDATE images SET date_available = '2026-08-01 00:00:00' WHERE id IN (1,2,3,4,5)"
        );
    }
});

test('getRegularSearchResults() filters by date_created preset', function (): void {
    $conn = searchServiceTestConn();
    $conn->executeStatement(
        'UPDATE images SET date_creation = ' . searchServiceTestNowMinusInterval(1, 'DAY') . ' WHERE id IN (1, 2, 3)'
    );
    $conn->executeStatement(
        'UPDATE images SET date_creation = ' . searchServiceTestNowMinusInterval(60, 'DAY') . ' WHERE id IN (4, 5)'
    );

    try {
        $search = [
            'fields' => [
                'date_created' => [
                    'preset' => '7d',
                ],
            ],
        ];
        $results = searchServiceTestService()
            ->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        expect($items)
            ->toBe([1, 2, 3]);
    } finally {
        $conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
    }
});

test('getRegularSearchResults() date_created custom range', function (): void {
    // Each of the 3 real fixture images below sits in a DIFFERENT one
    // of the 3 'y'/'m'/'d'-prefixed custom entries' own date range, and
    // NONE of them share a year -- proving each prefix shape's own
    // begin/end boundary construction independently and correctly
    // (not just "the loop runs"), including the 'm'/'d' branches' own
    // `! isset($customDates['y' . $year])` exclusion guard actually
    // NOT excluding them here (no 'y2023'/'y2022' entry is present).
    // Image 2 sits well inside June 2023 (not on a month boundary), so
    // a boundary-day-count mutation in the 'm' branch's own
    // cal_days_in_month() concat would still likely be caught by a
    // grosser malformed-date failure, without this test being
    // sensitive to the exact day-30-vs-31 edge itself.
    //
    // The plain '2023'/'2022-05' entries below (matching no ymd prefix,
    // contributing no subcondition of their own) exist only to make the
    // 'm'/'d' branches' own `! isset($customDates['y' . $year])`/
    // `! isset($customDates['m' . $year . '-' . $month])` exclusion
    // checks observable: a ConcatRemoveLeft mutation stripping the
    // 'y'/'m' prefix off that lookup key would otherwise still miss
    // ('y2023'/'m2022-05' are absent either way) -- these bare entries
    // populate the UN-prefixed key instead, so only a real, correct
    // prefix concat avoids colliding with them.
    $conn = searchServiceTestConn();
    $conn->executeStatement("UPDATE images SET date_creation = '2024-03-15 12:00:00' WHERE id = 1");
    $conn->executeStatement("UPDATE images SET date_creation = '2023-06-10 00:00:00' WHERE id = 2");
    $conn->executeStatement("UPDATE images SET date_creation = '2022-05-15 12:00:00' WHERE id = 3");

    try {
        // Mixes a 'y'/'m'/'d'-prefixed string entry of each shape plus a
        // non-string (int) entry that matches none of them -- exercises
        // dateFilterClause()'s custom-range subclause building for all 3
        // prefix shapes plus the mixed-type $custom array-building loop.
        $search = [
            'fields' => [
                'date_created' => [
                    'preset' => 'custom',
                    'custom' => ['y2024', 'm2023-06', 'd2022-05-15', 20250101, '2023', '2022-05'],
                ],
            ],
        ];
        $results = searchServiceTestService()
            ->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        expect($items)
            ->toBe([1, 2, 3]);
    } finally {
        $conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
    }
});

test('getRegularSearchResults() date_posted with unrecognized preset matches everything', function (): void {
    // dateFilterClause() falls back to a permissive '1=1' clause for a
    // preset that's neither a recognized threshold nor 'custom'.
    $search = [
        'fields' => [
            'date_posted' => [
                'preset' => 'not-a-real-preset',
            ],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getRegularSearchResults() filters by ratings null and numeric bucket', function (): void {
    // image5's rating_score is NULL (the '0' bucket); image1 is 4.50
    // (falls in the '5' bucket's [4,5) range).
    $search = [
        'fields' => [
            'ratings' => ['0', '5'],
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by filesize range', function (): void {
    // every fixture image's filesize is 1 (KB) -- comfortably inside a
    // [1-100, 2+100] BETWEEN range.
    $search = [
        'fields' => [
            'filesize_min' => 1,
            'filesize_max' => 2,
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() treats a null or zero min/max as "not set" for filesize/height/width, independently', function (string $minKey, string $maxKey, mixed $minValue, mixed $maxValue): void {
    // filesize/height/width each gate on a 4-term chain
    // (`$minRaw !== null && $minRaw !== 0 && $maxRaw !== null &&
    // $maxRaw !== 0`) -- the sibling "filters by X" tests above only
    // ever supply real non-null, non-zero values for both bounds, so
    // none of these 4 terms' own independent effect (as opposed to the
    // whole chain being true or false together) was ever provable.
    // Every fixture image would otherwise match (width/height 200x150,
    // filesize 1), so a missing/zero bound must suppress the filter
    // entirely, not just narrow the range.
    $search = [
        'fields' => [
            $minKey => $minValue,
            $maxKey => $maxValue,
        ],
    ];

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    expect($results['search_details']['has_filters_filled'])->toBeFalse();
})->with([
    'filesize_min null' => ['filesize_min', 'filesize_max', null, 2],
    'filesize_min zero' => ['filesize_min', 'filesize_max', 0, 2],
    'filesize_max null' => ['filesize_min', 'filesize_max', 1, null],
    'filesize_max zero' => ['filesize_min', 'filesize_max', 1, 0],
    'height_min null' => ['height_min', 'height_max', null, 300],
    'height_min zero' => ['height_min', 'height_max', 0, 300],
    'height_max null' => ['height_min', 'height_max', 100, null],
    'height_max zero' => ['height_min', 'height_max', 100, 0],
    'width_min null' => ['width_min', 'width_max', null, 300],
    'width_min zero' => ['width_min', 'width_max', 0, 300],
    'width_max null' => ['width_min', 'width_max', 100, null],
    'width_max zero' => ['width_min', 'width_max', 100, 0],
]);

test('getRegularSearchResults() allwords matches by album title', function (): void {
    // 'Nested' matches category 2's name ("Nested Sub Album", images 4
    // and 5) -- exercises searchAllwords()'s category-name matching
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

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([4, 5]);
});

test('getRegularSearchResults() allwords matches by tag name', function (): void {
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

    $results = searchServiceTestService()
        ->getRegularSearchResults($search);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() finds a tag-named match', function (): void {
    // "family" only tags image 1 -- exercises qsearchGetTags() ->
    // qsearchEval() -> permission-filtered final query end to end,
    // including the quote()-based FULLTEXT/REGEXP clauses.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('family', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() widens a match via the default-language Inflector\'s real word variants', function (): void {
    // Every OTHER test in this file exercises the Inflector-loading
    // machinery only incidentally (via its own default 'en' language)
    // without ever observing that a generated variant actually reaches
    // the query -- confirmed live: InflectorEn::getVariants('nature')
    // really does return ['natures']. A throwaway 'natures' tag (only
    // reachable via the variant, not the literal search term 'nature')
    // proves qsearchGetTextTokenSearchSql()'s own `array_merge([term],
    // variants)` step actually widens the match, not just that the
    // Inflector class loads without throwing.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: InnoDB's
    // FULLTEXT auxiliary index only ever syncs on COMMIT (confirmed via a
    // direct probe -- MATCH()/AGAINST() sees 0 rows for an uncommitted
    // INSERT in the same transaction, 1 row for the same data right after
    // COMMIT), so under the wrapper's never-committed transaction this
    // test's own 'natures' tag can never be found at all, not merely
    // delayed. A real commit is required, as it was before the wrapper
    // existed.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('natures', 'natures', NOW())");
    $tagId = (int) $conn->lastInsertId();
    $conn->executeStatement('INSERT INTO image_tag (image_id, tag_id) VALUES (4, ?)', [$tagId]);

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('nature', []);

        $items = $results['items'];
        sort($items);
        expect($items)
            ->toBe([1, 2, 3, 4]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId]);
        $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
    }
});

test('getQuickSearchResultsNoCache() returns empty for no match', function (): void {
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('nosuchtermatall', []);

    expect($results['items'])->toBe([])
        ->and($results['qs']['unmatched_terms'])->not->toBe([]);
});

test('getQuickSearchResultsNoCache() a term matching a real tag with zero currently-tagged images still qualifies, unlike a genuinely unrecognized term', function (): void {
    // qsearchEval()'s own $crtQualifies is `count($crtIds) > 0 ||
    // count($qsr->tag_ids[idx]) > 0` -- the 2nd half only differs from
    // the 1st when a term resolves to a REAL tag (tag_ids populated)
    // that currently has zero images linked to it (crtIds stays empty).
    // The sibling test above (a genuinely unrecognized word) can never
    // observe this, since it never populates tag_ids either.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // searchServiceTestFlushFulltext()'s OPTIMIZE TABLE implicitly commits
    // in MySQL, same as any other table-maintenance DDL -- under the
    // wrapper this would silently end the enclosing transaction, briefly
    // making this test's disposable tag really visible to every other
    // --parallel worker's own connection until the finally block's own
    // DELETE below runs, defeating the wrapper's whole isolation guarantee
    // for exactly the window it exists to close.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('zqualifiesonly', 'zqualifiesonly', NOW())");
    $tagId = (int) $conn->lastInsertId();
    searchServiceTestFlushFulltext($conn, 'tags');

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('zqualifiesonly', []);

        expect($results['items'])->toBe([])
            ->and($results['qs']['unmatched_terms'])->toBe([]);
    } finally {
        $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
    }
});

test('getQuickSearchResultsNoCache() a term matching a real category with zero currently-categorized images still qualifies, unlike a genuinely unrecognized term', function (): void {
    // Same reasoning as the tag-based sibling test above, for
    // qsearchEval()'s own $crtQualifies category-side term
    // (`count($qsr->cat_ids[idx]) > 0`) -- a real, empty category is
    // the only way to populate cat_ids while crtIds itself stays
    // empty.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: same
    // OPTIMIZE-TABLE-implicitly-commits reasoning as the tag-based sibling
    // test above.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $rank = $conn->getDatabasePlatform()
        ->quoteSingleIdentifier('rank');
    // An explicit, high rank -- leaving it to the schema's own NULL
    // default sorts this root category AHEAD of real fixture category 1
    // (rank 1) in updateGlobalRank()'s own ORDER BY (NULLs sort first in
    // ASC order), so another --parallel worker's own
    // createVirtualCategory()-based test running its own updateGlobalRank()
    // while this row is still live would renumber category 1's own rank
    // out from under it (reproduced live: findMaxRankForParent()
    // returned 2, not 1).
    $conn->executeStatement("INSERT INTO categories (name, {$rank}) VALUES ('zqualifiesonlycat', 999)");
    $catId = (int) $conn->lastInsertId();
    searchServiceTestFlushFulltext($conn, 'categories');

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('zqualifiesonlycat', []);

        expect($results['items'])->toBe([])
            ->and($results['qs']['unmatched_terms'])->toBe([]);
    } finally {
        $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$catId]);
    }
});

test('getQuickSearchResultsNoCache() finds a category-named match', function (): void {
    // "Nested" only matches category 2's name ("Nested Sub Album",
    // fixture) -- exercises qsearchGetCategories(), which filters
    // categories via $user['forbidden_categories'] instead of an INNER
    // JOIN against user_cache_categories, end to end. Category 2 holds
    // images 4 and 5 (image_category fixture).
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('Nested', []);

    expect($results['items'])->toBe([4, 5]);
});

test('getQuickSearchResultsNoCache() excludes a forbidden category match', function (): void {
    // Same search as above, but with category 2 marked forbidden for
    // this user -- proves the NOT IN (...) replacement actually excludes
    // it, not just that it's syntactically present.
    CurrentUserTestFactory::get()->set(User::fromUserArray(array_merge(searchServiceTestRealisticUserGlobal(), [
        'forbidden_categories' => '2',
    ])));

    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('Nested', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() binds a multi-value forbidden-categories condition correctly', function (): void {
    // Every OTHER test in this file has exactly 1 forbidden-category id
    // (the harmless default '0'), so positionalCondition()'s own
    // array-to-placeholder expansion (`implode(',',
    // array_fill(0, count($value), '?'))`) never has more than 1
    // element to expand -- forbidding 2 real ids at once is what
    // actually exercises building a real ?,? multi-placeholder clause
    // with 2 correctly-ordered bound values, not just the single-value
    // degenerate case. 'nature' only matches category-1 images, so
    // forbidding categories 2 and 3 (neither of which any fixture image
    // sits in) must still return the full match untouched.
    CurrentUserTestFactory::get()->set(User::fromUserArray(array_merge(searchServiceTestRealisticUserGlobal(), [
        'forbidden_categories' => '2,3',
    ])));

    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('nature', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('getQuickSearchResults() caches across calls', function (): void {
    // SearchResultsCachePool backs quick-search result caching --
    // proven by mutating the underlying data (tag image 2 "family",
    // which the fixture doesn't already do -- only image 1 is) after the
    // first (caching) call, then showing a 2nd call with the same query
    // still returns the stale (pre-mutation) result.
    $service = searchServiceTestService();

    $first = $service->getQuickSearchResults('family', []);
    expect($first['items'])->toBe([1]);

    $conn = searchServiceTestConn();
    $conn->executeStatement(
        'INSERT INTO image_tag (image_id, tag_id) VALUES (2, 3)'
    );

    try {
        $second = $service->getQuickSearchResults('family', []);
        expect($second['items'])->toBe($first['items'], 'a cache hit must not re-query the DB')
            ->and($second)
            ->not->toHaveKey('debug');
    } finally {
        $conn->executeStatement(
            'DELETE FROM image_tag WHERE image_id = 2 AND tag_id = 3'
        );
    }
});

test('qsearchGetTextTokenSearchSql() falls back to REGEXP for a leading wildcard', function (): void {
    // QST_WILDCARD_BEGIN forces useFt=false regardless of term length --
    // MySQL FULLTEXT can't do a leading-wildcard prefix match.
    $token = new QSingleToken('hoto', QSingleToken::QST_WILDCARD_BEGIN, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name'], 'images_fts');

    expect($clauses)
        ->not->toBe([]);
    $count = searchServiceTestConn()
        ->executeQuery(
            'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
            $values
        )->fetchOne();
    // every fixture image is named "Photo N" -- "hoto" (no left boundary
    // required) matches all 5.
    expect(is_numeric($count) ? (int) $count : null)
        ->toBe(5);
});

test('qsearchGetTextTokenSearchSql() falls back to REGEXP for a quoted trailing wildcard', function (): void {
    $modifier = QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END;
    $token = new QSingleToken('Phot', $modifier, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name'], 'images_fts');

    expect($clauses)
        ->not->toBe([]);
    $count = searchServiceTestConn()
        ->executeQuery(
            'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
            $values
        )->fetchOne();
    expect(is_numeric($count) ? (int) $count : null)
        ->toBe(5);
});

test('qsearchGetTextTokenSearchSql() falls back to REGEXP when every split part is short', function (): void {
    // "ab-cd" splits (on the punctuation class) into ["ab","cd"], both
    // shorter than 4 chars -- forces useFt=false even though the whole
    // term itself is longer than 3 chars.
    $token = new QSingleToken('ab-cd', 0, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name'], 'images_fts');

    expect($clauses)
        ->not->toBe([]);
    $count = searchServiceTestConn()
        ->executeQuery(
            'SELECT COUNT(*) FROM images WHERE (' . implode(' OR ', $clauses) . ')',
            $values
        )->fetchOne();
    expect(is_numeric($count) ? (int) $count : null)
        ->toBe(0);
});

test('qsearchGetTextTokenSearchSql() stays on FULLTEXT when the longest split part is exactly 4 chars', function (): void {
    // The `$max < 4` boundary itself, not just "short vs long" --
    // "abc-defg" splits into ["abc"(3), "defg"(4)]; max=4 must NOT
    // trip the too-short fallback (only max < 4 does), unlike the
    // sibling "every split part is short" test above, whose own max=2
    // never gets near this exact boundary.
    $token = new QSingleToken('abc-defg', 0, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'images_fts');

    if (DbCredentials::fromEnv()->driver === 'pgsql') {
        expect($clauses)->toBe(["tsv_search @@ to_tsquery('simple', ?)"]);
    } else {
        // $ft is the whole original variant, hyphen included -- the
        // split-into-parts step above is only ever used for the
        // eligibility check itself, never to transform the bound value.
        // The trailing "AND (name LIKE ? OR comment LIKE ?)" is a
        // literal-substring confirmation ANDed onto the FULLTEXT clause
        // -- see this method's own docblock on the ngram-parser false-
        // positive class it closes.
        expect($clauses)
            ->toBe(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE) AND (name LIKE ? OR comment LIKE ?)'])
            ->and($values)
            ->toBe(['abc-defg', '%abc-defg%', '%abc-defg%']);
    }
});

test('qsearchGetTextTokenSearchSql() wraps a quoted term in double quotes for FULLTEXT', function (): void {
    $token = new QSingleToken('nature', QSingleToken::QST_QUOTED, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'images_fts');

    if (DbCredentials::fromEnv()->driver === 'pgsql') {
        expect($clauses)->toBe(["tsv_search @@ to_tsquery('simple', ?)"])
            ->and($values)
            ->toBe(['nature']);
    } else {
        expect($clauses)->toBe(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE) AND (name LIKE ? OR comment LIKE ?)'])
            ->and($values)
            ->toBe(['"nature"', '%nature%', '%nature%']);
    }
});

test('qsearchGetTextTokenSearchSql() appends a star for a trailing wildcard FULLTEXT term', function (): void {
    $token = new QSingleToken('travel', QSingleToken::QST_WILDCARD_END, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'images_fts');

    if (DbCredentials::fromEnv()->driver === 'pgsql') {
        expect($clauses)->toBe(["tsv_search @@ to_tsquery('simple', ?)"])
            ->and($values)
            ->toBe(['travel:*']);
    } else {
        expect($clauses)->toBe(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE) AND (name LIKE ? OR comment LIKE ?)'])
            ->and($values)
            ->toBe(['travel*', 'travel%', 'travel%']);
    }
});

test('qsearchGetTextTokenSearchSql() throws when preg_split() hits the backtrack limit', function (): void {
    // 'hello-world' (>3 chars, unquoted, no wildcard) forces $useFt=true
    // so the preg_split() branch actually runs. Must contain at least
    // one of the pattern's own delimiter characters (confirmed live: a
    // plain 'helloworld' with none of them never makes preg_split()
    // attempt any real matching work at all, so it never backtracks and
    // backtrack_limit=0 has nothing to bite -- only a string the split
    // pattern actually has to work on, like the real '-' here,
    // reproduces PREG_BACKTRACK_LIMIT_ERROR).
    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        expect(fn (): array => searchServiceTestService()->qsearchGetTextTokenSearchSql(new QSingleToken('hello-world', 0, null), ['name'], 'images_fts'))
            ->toThrow(Exception::class, 'qsearchGetTextTokenSearchSql(): preg_split() failed');
    } finally {
        ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
    }
});

test('getQuickSearchResultsNoCache() matches the author scope when populated', function (): void {
    // Every fixture image has a NULL author -- a non-empty author: term
    // never matches, but proves the 'author' scope's non-empty branch
    // runs end to end.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('author:someone', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() wildcarded empty author matches authored images', function (): void {
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('author:*', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() plain empty author matches unauthored images', function (): void {
    // Filtered down to known-real ids rather than an unfiltered toBe(),
    // since `author` defaults to NULL -- another --parallel worker's own
    // FULLTEXT-deadlock-exempted disposable image (author never set
    // explicitly) can transiently match this same empty-author scope.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('author:', []);

    $itemIds = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $results['items']);
    $items = array_values(array_intersect($itemIds, [1, 2, 3, 4, 5]));
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by width and height scopes', function (): void {
    // every fixture image is 200x150.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('width:200 height:150', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by ratio scope', function (): void {
    // 200/150 = 1.3333... -- comfortably inside the explicit range.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('ratio:1.3..1.4', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by size scope', function (): void {
    // width*height = 200*150 = 30000 for every fixture image.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('size:30000', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by hits scope', function (): void {
    // every fixture image's hit counter is 0.
    //
    // Filtered down to known-real ids rather than an unfiltered toBe(),
    // since `hit` defaults to 0 (not NULL) -- another --parallel
    // worker's own FULLTEXT-deadlock-exempted disposable image (hit
    // counter never set explicitly) can transiently match this same
    // hits:0 scope too.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('hits:0', []);

    $itemIds = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $results['items']);
    $items = array_values(array_intersect($itemIds, [1, 2, 3, 4, 5]));
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by score scope excluding unrated', function (): void {
    // rating_score: 4.50/3.00/5.00/2.00/NULL for images 1-5 -- image5's
    // NULL never satisfies a numeric BETWEEN-style clause.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('score:2..5', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4]);
});

test('getQuickSearchResultsNoCache() filters by filesize scope', function (): void {
    // 1024*filesize = 1024*1 = 1024 for every fixture image.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('filesize:1000..2000', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by created scope with no match', function (): void {
    // date_creation is NULL for every fixture image.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('created:2024..2027', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() filters by posted scope', function (): void {
    // date_available is '2026-08-01 00:00:00' for every fixture image.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('posted:2024..2027', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by id scope', function (): void {
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('id:1..3', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('getQuickSearchResultsNoCache() filters by file scope', function (): void {
    // only image 1's filename ('fixture-photo-1.jpg') contains
    // "photo-1" -- image 10 doesn't exist, so there's no accidental
    // substring collision.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('file:photo-1', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() escapes a literal percent sign in the file scope, not treating it as a SQL LIKE wildcard', function (): void {
    // No fixture filename contains a literal '%' -- if str_replace()'s
    // own '%' => '\%' escaping pair were dropped, the raw '%' would
    // instead be interpreted as SQL's own "match anything" wildcard,
    // and 'fixture%1' would incorrectly match 'fixture-photo-1.jpg'
    // (starts with 'fixture', has a '1' later).
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('file:fixture%1', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() escapes a literal underscore in the file scope, not treating it as a SQL LIKE single-char wildcard', function (): void {
    // Real fixture filenames use a hyphen ('fixture-photo-N.jpg'), not
    // an underscore -- if str_replace()'s own '_' => '\_' escaping pair
    // were dropped, the raw '_' would match any single character
    // (including that real hyphen) instead of a literal underscore.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('file:photo_1', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() unhandled scope falls through the default hook branch', function (): void {
    // 'tag' has no dedicated case in qsearchGetImages()'s own switch (it
    // has its own dedicated qsearchGetTags() path instead) -- a
    // tag-scoped token falls to the default/plugin-hook branch there,
    // contributing nothing to images_iids; the final match comes
    // entirely from qsearchGetTags()'s own tag_iids.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('tag:nature', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('qsearchGetTags() direct call with a nullable wildcarded tag scope matches every tagged image', function (): void {
    // The real quick-search scope list registers 'tag' as non-nullable,
    // so an empty-term tag-scoped token can never survive
    // QMultiToken::push() through the normal getQuickSearchResultsNoCache()
    // path -- calling qsearchGetTags() directly with a hand-built
    // nullable 'tag' scope is the only way to reach this branch.
    //
    // Filtered down to the fixture's own 5 ids before comparing, not a
    // bare match -- these 4 sibling tests query "every tagged/untagged/
    // categorized/uncategorized image" with no id bound at all, so a
    // disposable image another Unit-suite file inserts (tagged or not,
    // categorized or not) for the span of its own test could land in
    // any one of them under --parallel.
    $scopes = [new QSearchScope('tag', [], true)];
    $expr = new QExpression('tag:*', $scopes);
    $qsr = new QResults();

    searchServiceTestService()
        ->qsearchGetTags($expr, $qsr);

    $imageIds = array_values(array_intersect($qsr->tag_iids[0], [1, 2, 3, 4, 5]));
    sort($imageIds);
    expect($imageIds)
        ->toBe([1, 2, 3]);
});

test('qsearchGetTags() direct call with a nullable empty tag scope matches untagged images', function (): void {
    $scopes = [new QSearchScope('tag', [], true)];
    $expr = new QExpression('tag:', $scopes);
    $qsr = new QResults();

    searchServiceTestService()
        ->qsearchGetTags($expr, $qsr);

    $imageIds = array_values(array_intersect($qsr->tag_iids[0], [1, 2, 3, 4, 5]));
    sort($imageIds);
    expect($imageIds)
        ->toBe([4, 5]);
});

test('qsearchGetCategories() direct call with a nullable wildcarded category scope matches every categorized image', function (): void {
    // No real quick-search scope ever has id 'category' (the registered
    // scope list only has tag/photo/file/author/numeric/date scopes) --
    // same direct-call rationale as the tag tests above.
    $scopes = [new QSearchScope('category', [], true)];
    $expr = new QExpression('category:*', $scopes);
    $qsr = new QResults();

    searchServiceTestService()
        ->qsearchGetCategories($expr, $qsr);

    $imageIds = array_values(array_intersect($qsr->cat_iids[0], [1, 2, 3, 4, 5]));
    sort($imageIds);
    expect($imageIds)
        ->toBe([1, 2, 3, 4, 5]);
});

test('qsearchGetCategories() direct call with a nullable empty category scope matches uncategorized images', function (): void {
    $scopes = [new QSearchScope('category', [], true)];
    $expr = new QExpression('category:', $scopes);
    $qsr = new QResults();

    searchServiceTestService()
        ->qsearchGetCategories($expr, $qsr);

    expect(array_intersect($qsr->cat_iids[0], [1, 2, 3, 4, 5]))->toBe([]);
});

test('qsearchGetImages() dispatches the hook for an unrecognized scope and applies the returned clause', function (): void {
    // No real quick-search scope ever has id 'custom_field' -- reaches
    // qsearchGetImages()'s own default/plugin-hook branch, same
    // direct-call rationale as the tag/category tests above.
    $handler = static function (QsearchGetImagesSqlScopes $event): void {
        $event->clauses = [new QsearchClause('i.id = ?', [1])];
    };
    EventDispatcherTestFactory::get()->addTypedHandler(QsearchGetImagesSqlScopes::class, $handler);

    try {
        $scopes = [new QSearchScope('custom_field', [], true)];
        $expr = new QExpression('custom_field:*', $scopes);
        $qsr = new QResults();

        searchServiceTestService()
            ->qsearchGetImages($expr, $qsr);

        expect($qsr->images_iids[0])->toBe([1]);
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(QsearchGetImagesSqlScopes::class, $handler);
    }
});

test('qsearchGetImages() merges params from multiple hook clauses', function (): void {
    $handler = static function (QsearchGetImagesSqlScopes $event): void {
        $event->clauses = [
            new QsearchClause('i.id = ?', [1]),
            new QsearchClause('i.id = ?', [2]),
        ];
    };
    EventDispatcherTestFactory::get()->addTypedHandler(QsearchGetImagesSqlScopes::class, $handler);

    try {
        $scopes = [new QSearchScope('custom_field', [], true)];
        $expr = new QExpression('custom_field:*', $scopes);
        $qsr = new QResults();

        searchServiceTestService()
            ->qsearchGetImages($expr, $qsr);

        $imageIds = $qsr->images_iids[0];
        sort($imageIds);
        expect($imageIds)
            ->toBe([1, 2]);
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(QsearchGetImagesSqlScopes::class, $handler);
    }
});

test('qsearchGetImages() returns no matches for an unrecognized scope with no listener', function (): void {
    $scopes = [new QSearchScope('custom_field', [], true)];
    $expr = new QExpression('custom_field:*', $scopes);
    $qsr = new QResults();

    searchServiceTestService()
        ->qsearchGetImages($expr, $qsr);

    expect($qsr->images_iids[0])->toBe([]);
});

test('getQuickSearchResultsNoCache() a lone NOT-prefixed tag match produces no results', function (): void {
    // NOT alone (no positive criterion) can never qualify a single
    // top-level token -- exercises both qsearchGetTags()'s own NOT-ids
    // accumulation and qsearchEval()'s own NOT branch.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('-family', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() a lone NOT-prefixed category match produces no results', function (): void {
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('-Sample', []);

    expect($results['items'])->toBe([]);
});

test('qsearchGetTags() narrows 2 adjacent short, non-wildcarded, non-quoted terms to their shared tag ids', function (): void {
    // Distinguishes this intersection from the top-level AND every
    // OTHER multi-term test in this file already exercises: needs a
    // single token to match MULTIPLE tag ids on its own (word-boundary
    // REGEXP against a multi-word tag name does this -- 'zna' matches
    // both a tag literally named 'zna' AND one named 'zna znb', since
    // both contain 'zna' as a whole word), so that requiring the
    // adjacent 'znb' token's own match set to agree narrows it down to
    // just the shared 'zna znb' tag. 'zna'/'znb' alone are each tagged
    // to image 5 (noise); 'zna znb' alone is tagged to image 4.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: `tags`
    // carries a FULLTEXT index (tags_ft_name), and InnoDB's FULLTEXT
    // auxiliary-index maintenance on INSERT can deadlock against another
    // --parallel worker's own concurrent tags INSERT when held open for
    // a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's own 'getTagIds() creates a new tag for a
    // plain name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('zna', 'zna', NOW())");
    $tagA = (int) $conn->lastInsertId();
    $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('znb', 'znb', NOW())");
    $tagB = (int) $conn->lastInsertId();
    $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('zna znb', 'zna-znb', NOW())");
    $tagAB = (int) $conn->lastInsertId();
    $conn->executeStatement(
        'INSERT INTO image_tag (image_id, tag_id) VALUES (5, ?), (5, ?), (4, ?)',
        [$tagA, $tagB, $tagAB]
    );

    try {
        $expr = new QExpression('zna znb', []);
        $qsr = new QResults();

        searchServiceTestService()
            ->qsearchGetTags($expr, $qsr);

        expect($qsr->tag_iids[0])->toBe([4])
            ->and($qsr->tag_iids[1])->toBe([4]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE tag_id IN (?, ?, ?)', [$tagA, $tagB, $tagAB]);
        $conn->executeStatement('DELETE FROM tags WHERE id IN (?, ?, ?)', [$tagA, $tagB, $tagAB]);
    }
});

test('getQuickSearchResultsNoCache() excludes a matched tag from the display list when its own term is too short in a multi-term search', function (): void {
    // qsearchGetTags()'s own $positiveIds accumulation (which
    // ultimately narrows $qsr->all_tags, exposed as
    // $results['qs']['matching_tags']) needs strlen(term) > 2 OR the
    // search is single-token OR the token is scoped/wildcarded/quoted
    // -- every OTHER multi-term test in this file only ever combines
    // already-long terms, so this 4-term OR's own first branch was
    // never independently provable. A 2-char exact tag name ('ab') in
    // a 2-token search satisfies none of the 4 conditions, so it must
    // be found (a real, valid tag match) yet excluded from the display
    // list -- unlike 'family', long enough to qualify on its own.
    //
    // Exempt from the blanket per-test transaction -- same
    // FULLTEXT-deadlock reason as qsearchGetTags()'s own sibling test
    // above.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('ab', 'ab', NOW())");
    $tagId = (int) $conn->lastInsertId();
    $conn->executeStatement('INSERT INTO image_tag (image_id, tag_id) VALUES (4, ?)', [$tagId]);

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('ab family', []);

        $matchingTags = $results['qs']['matching_tags'] ?? null;
        if (! is_array($matchingTags)) {
            throw new LogicException('expected matching_tags to be an array, got ' . get_debug_type($matchingTags));
        }

        expect(array_column($matchingTags, 'name'))
            ->toBe(['family']);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId]);
        $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
    }
});

test('getQuickSearchResultsNoCache() narrows two adjacent short terms to a shared tag match', function (): void {
    // "dog" (<=3 chars) is too short for a real fixture tag -- insert a
    // temporary one so 2 adjacent short terms genuinely share a match,
    // exercising qsearchGetTags()'s own short-token intersection.
    //
    // Exempt from the blanket per-test transaction -- same
    // FULLTEXT-deadlock reason as qsearchGetTags()'s own sibling test
    // far above.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement(
        "INSERT INTO tags (name, url_name, lastmodified) VALUES ('dog', 'dog', NOW())"
    );
    $tagId = (int) $conn->lastInsertId();
    $conn->executeStatement(
        'INSERT INTO image_tag (image_id, tag_id) VALUES (2, ?)',
        [$tagId]
    );

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('dog dog', []);

        expect($results['items'])->toBe([2]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId]);
        $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
    }
});

test('qsearchGetCategories() narrows 2 adjacent short, non-wildcarded, non-quoted terms to their shared category ids', function (): void {
    // Same rationale/technique as qsearchGetTags()'s own analogous
    // test above: a single token matching MULTIPLE category ids on its
    // own (word-boundary REGEXP against a multi-word category name)
    // is required to make the adjacent-token intersection observably
    // narrow anything, since the top-level AND across sibling tokens
    // would otherwise coincidentally reach the same final image set
    // regardless of whether this sub-intersection ran.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // `categories` carries a FULLTEXT index (categories_ft_name_comment),
    // and InnoDB's FULLTEXT auxiliary-index maintenance on INSERT can
    // deadlock against another --parallel worker's own concurrent
    // categories INSERT when held open for a whole test's duration --
    // same mechanism, same fix, as qsearchGetTags()'s own analogous test
    // far above.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement("INSERT INTO categories (name) VALUES ('zca')");
    $catA = (int) $conn->lastInsertId();
    $conn->executeStatement("INSERT INTO categories (name) VALUES ('zcb')");
    $catB = (int) $conn->lastInsertId();
    $conn->executeStatement("INSERT INTO categories (name) VALUES ('zca zcb')");
    $catAB = (int) $conn->lastInsertId();
    $conn->executeStatement(
        'INSERT INTO image_category (image_id, category_id) VALUES (5, ?), (5, ?), (4, ?)',
        [$catA, $catB, $catAB]
    );

    try {
        $expr = new QExpression('zca zcb', []);
        $qsr = new QResults();

        searchServiceTestService()
            ->qsearchGetCategories($expr, $qsr);

        expect($qsr->cat_iids[0])->toBe([4])
            ->and($qsr->cat_iids[1])->toBe([4]);
    } finally {
        $conn->executeStatement('DELETE FROM image_category WHERE category_id IN (?, ?, ?)', [$catA, $catB, $catAB]);
        $conn->executeStatement('DELETE FROM categories WHERE id IN (?, ?, ?)', [$catA, $catB, $catAB]);
    }
});

test('getQuickSearchResultsNoCache() excludes a matched category from the display list when its own term is too short in a multi-term search', function (): void {
    // Same rationale as qsearchGetTags()'s own analogous test above --
    // qsearchGetCategories()'s own $positiveIds accumulation
    // (narrowing $qsr->all_cats / $results['qs']['matching_cats']) has
    // the identical 4-term OR gate. A 2-char exact category name ('ab')
    // in a 2-token search satisfies none of the 4 conditions.
    //
    // Exempt from the blanket per-test transaction -- same
    // FULLTEXT-deadlock reason as qsearchGetCategories()'s own sibling
    // test above.
    DbTransactionTestOverride::rollback();
    $conn = searchServiceTestConn();
    $conn->executeStatement("INSERT INTO categories (name) VALUES ('ab')");
    $catId = (int) $conn->lastInsertId();

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('ab Sample', []);

        $matchingCats = $results['qs']['matching_cats'] ?? null;
        if (! is_array($matchingCats)) {
            throw new LogicException('expected matching_cats to be an array, got ' . get_debug_type($matchingCats));
        }

        expect(array_column($matchingCats, 'name'))
            ->toBe(['Sample Album']);
    } finally {
        $conn->executeStatement('DELETE FROM categories WHERE id = ?', [$catId]);
    }
});

test('getQuickSearchResultsNoCache() narrows two adjacent short terms to a shared category match', function (): void {
    // "Sub" (<=3 chars) whole-word-matches category 2's name ("Nested
    // Sub Album") -- exercises qsearchGetCategories()'s own analogous
    // short-token intersection.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('Sub Sub', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([4, 5]);
});

test('getQuickSearchResultsNoCache() expands to subalbums when enabled', function (): void {
    CurrentConfigTestFactory::get()->quickSearchIncludeSubAlbums = true;

    // "Sample" matches category 1 ("Sample Album") only, by name -- with
    // sub-album inclusion enabled this expands to include category 2
    // (its child, per the fixture's uppercats), pulling in images 4 and
    // 5 too.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('Sample', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() finds no subalbums for a leaf category match with subalbums enabled', function (): void {
    // findSubcategoryIds() matches on `uppercats`, which always contains
    // a category's own id -- so with real, uncorrupted data
    // getSubcatIds() can never return [] for a category that itself just
    // matched (it always matches at least itself). Temporarily
    // corrupting category 2's own `uppercats` (simulating the same kind
    // of stale/broken hierarchy row Admin\CategoryRepairService exists
    // to fix) is the only way to make findSubcategoryIds([2]) genuinely
    // return [], exercising qsearchGetCategories()'s own "$subcatIds ===
    // []" ternary branch -- as opposed to the sibling test above, whose
    // category 1 always DOES have a real child.
    CurrentConfigTestFactory::get()->quickSearchIncludeSubAlbums = true;
    $conn = searchServiceTestConn();
    $originalUppercats = $conn->fetchOne('SELECT uppercats FROM categories WHERE id = 2');
    expect($originalUppercats)
        ->toBeString();
    $conn->executeStatement("UPDATE categories SET uppercats = '999' WHERE id = 2");

    try {
        // "Nested" matches category 2 ("Nested Sub Album") by name only.
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('Nested', []);

        expect($results['items'])->toBe([]);
    } finally {
        $conn->executeStatement('UPDATE categories SET uppercats = ? WHERE id = 2', [$originalUppercats]);
    }
});

test('getQuickSearchResultsNoCache() OR keyword unions two tag matches', function (): void {
    // "family" tags image 1 only; "nature" tags images 1,2,3. The
    // literal "OR" keyword sets QST_OR on the following token
    // (QMultiToken::parse()), exercising qsearchEval()'s own OR-modifier
    // union branch -- every other multi-term search test in this file
    // exercises the implicit AND/intersection instead.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('family OR nature', []);

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 2, 3]);
});

test('getQuickSearchResultsNoCache() evaluates a parenthesized sub-group', function (): void {
    // "(nature)" is a nested QMultiToken sub-expression -- exercises
    // qsearchEval()'s own recursive branch (a non-QSingleToken child).
    // "nature" tags images 1,2,3; "family" tags image 1 only -- the
    // implicit AND between the group and the trailing word intersects
    // down to image 1.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('(nature) family', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() falls back when a hook returns non-array items and qs', function (): void {
    $handler = static function (QsearchResults $event): void {
        $event->searchResults['items'] = 'not-an-array';
        $event->searchResults['qs'] = 'not-an-array-either';
    };
    EventDispatcherTestFactory::get()->addTypedHandler(QsearchResults::class, $handler);

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('family', []);
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(QsearchResults::class, $handler);
    }

    // The hook only corrupts $searchResults['items']/['qs'] -- both
    // safely fall back ([] / the reconstructed default qs) -- but the
    // real tag/category match computed *before* the hook ran (the
    // fixture's own 'family' tag, id 3, linked to image 1) still reaches
    // the final result via $ids, independently of whatever the hook
    // did. Confirmed live: this method never discards that real match
    // just because the hook's own extra items were unusable.
    expect($results['items'])->toBe([1])
        ->and($results['qs'])->toBe([
            'q' => 'family',
            'unmatched_terms' => [],
        ]);
});

test('getQuickSearchResultsNoCache() merges extra numeric ids from a plugin hook', function (): void {
    $handler = static function (QsearchResults $event): void {
        $event->searchResults['items'] = ['4', 'not-numeric'];
    };
    EventDispatcherTestFactory::get()->addTypedHandler(QsearchResults::class, $handler);

    try {
        $results = searchServiceTestService()
            ->getQuickSearchResultsNoCache('family', []);
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(QsearchResults::class, $handler);
    }

    $items = $results['items'];
    sort($items);
    expect($items)
        ->toBe([1, 4]);
});

test('getQuickSearchResultsNoCache() returns early for an empty query', function (): void {
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('', []);

    expect($results['items'])->toBe([])
        ->and($results['qs'])->toBe([
            'q' => '',
            'unmatched_terms' => [],
        ]);
});

test('getQuickSearchResultsNoCache() works with a non-default calendar datefield', function (): void {
    // calendarDatefield() !== 'date_creation' takes the else branch,
    // appending 'date' to $postedDateAliases instead of
    // $createdDateAliases -- proves the scope list still builds and the
    // search still functions correctly either way.
    CurrentConfigTestFactory::get()->calendarDatefield = 'date_available';

    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('family', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() applies a custom images_where clause', function (): void {
    // "nature" alone matches images 1,2,3; a custom images_where narrows
    // that down further, proving the clause is genuinely applied (not
    // coincidentally the same result).
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('nature', [
            'images_where' => SqlCondition::fromRawSql('id = :onlyId', [
                'onlyId' => 2,
            ]),
        ]);

    expect($results['items'])->toBe([2]);
});

test('getQuickSearchResultsNoCache() keeps images_where values bound rather than inlining them', function (): void {
    // A value carrying a quote survives only because it stays a bound
    // parameter all the way down. The caller that produces this option used
    // to flatten the condition back into literal SQL by hand, wrapping
    // strings in bare single quotes -- which this value would have closed
    // early, producing a syntax error instead of an empty result.
    $results = searchServiceTestService()
        ->getQuickSearchResultsNoCache('nature', [
            'images_where' => SqlCondition::fromRawSql('file = :quoted', [
                'quoted' => "o'brien' OR '1'='1",
            ]),
        ]);

    expect($results['items'])->toBe([]);
});

test('getValidatedSearchInfo() calls fatalError() for an invalid identifier', function (): void {
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    expect(fn (): ?Search => $service->getValidatedSearchInfo('not-a-valid-identifier', null))
        ->toThrow(RuntimeException::class, 'fatalError: Invalid search identifier');
});

test('getValidatedSearchInfo() calls fatalError() when a uuid search is looked up by bare id', function (): void {
    $id = searchServiceTestRepo()
        ->insertSavedSearch([
            'q' => 'nature',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-fatalidtst', null);

    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    expect(fn (): ?Search => $service->getValidatedSearchInfo((string) $id, null))
        ->toThrow(RuntimeException::class, 'fatalError: this search is not reachable with its id, need the search_uuid instead');
});

test('getValidatedSearchInfo() looking up by uuid never triggers the id-vs-uuid mismatch gate, even when the row has a real uuid', function (): void {
    // The mismatch gate's own 2-term `and` chain
    // (`$clausePattern === 'id = ?' and $search->searchUuid !== null`)
    // needs its own 1st term false here -- a uuid-pattern candidate can
    // never make $clausePattern equal 'id = ?' -- to prove it's a real
    // AND, not an accidentally widened OR that would fire on any single
    // true term.
    searchServiceTestRepo()
        ->insertSavedSearch([
            'q' => 'nature',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-anduuidts1', null);
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    $search = $service->getValidatedSearchInfo('psk-20260712-anduuidts1', null);

    expect($search)
        ->not->toBeNull();
});

test('getValidatedSearchInfo() looking up a bare id never triggers the mismatch gate when the row has no uuid at all', function (): void {
    // Same 3-term `and` chain, this time with its own 3rd term false
    // ($search->searchUuid === null) -- together with the sibling test
    // above (2nd term false), these pin down both `and` operators.
    $id = searchServiceTestRepo()
        ->insertSavedSearch([
            'q' => 'nature',
        ], '2026-07-12 00:00:00', 1, null, null);
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    $search = $service->getValidatedSearchInfo((string) $id, null);

    expect($search)
        ->not->toBeNull();
});

test('getValidatedSearchArray() calls badRequest() when the search is not found', function (): void {
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    // getSearchIdPattern()'s own search_uuid regex requires exactly 10
    // alphanumeric chars after the date ('doesnotexist' is 12) --
    // confirmed live, a too-long suffix doesn't match *any* recognised
    // pattern at all, so getValidatedSearchInfo()'s earlier "Invalid
    // search identifier" fatalError() fires first instead of ever
    // reaching the not-found badRequest() this test means to exercise.
    expect(fn (): array|false => $service->getValidatedSearchArray('psk-20260712-doesnotexi', null))
        ->toThrow(RuntimeException::class, 'badRequest: this search identifier does not exist');
});

test('getSearchResults() calls badRequest() when the search identifier does not exist', function (): void {
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    expect(fn (): array => $service->getSearchResults('psk-20260712-doesnotexist', null))
        ->toThrow(RuntimeException::class, 'badRequest: this search identifier does not exist');
});

test('getSearchResults() resolves a saved quick search query', function (): void {
    $id = searchServiceTestRepo()
        ->insertSavedSearch([
            'q' => 'family',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-quicksrch1', null);

    $results = searchServiceTestService()
        ->getSearchResults((string) $id, true, '');

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() writes the default-user lookup through the real container-shared ProcessCache, not a throwaway instance', function (): void {
    // SearchService's own private processCache() helper resolves
    // Kernel::container()->get(ProcessCache::class) when booted --
    // proven here by checking the SAME container-shared instance
    // (searchServiceTestProcessCache(), same resolution path every
    // other TestFactory in this project relies on) actually observes
    // the 'default_user' key UserService::getDefaultUserInfo() writes
    // internally. A silently-fresh `new ProcessCache()` per call would
    // never be externally observable this way.
    searchServiceTestProcessCache()
        ->forget('default_user');
    expect(searchServiceTestProcessCache()->has('default_user'))
        ->toBeFalse();

    try {
        searchServiceTestService()->getQuickSearchResultsNoCache('nature', []);

        expect(searchServiceTestProcessCache()->has('default_user'))
            ->toBeTrue();
    } finally {
        searchServiceTestProcessCache()->forget('default_user');
    }
});

test('getQuickSearchResultsNoCache() throws when the default user language resolves to an Inflector class that does not implement the interface', function (): void {
    // See SearchServiceTestNotAnInflector's own docblock above for why
    // class_alias() is the only real way in.
    if (! class_exists('Piwigo\\Search\\Inflector\\InflectorZz', false)) {
        class_alias(SearchServiceTestNotAnInflector::class, 'Piwigo\\Search\\Inflector\\InflectorZz');
    }

    $conn = searchServiceTestConn();
    $originalLanguage = $conn->fetchOne('SELECT language FROM user_infos WHERE user_id = 2');
    expect($originalLanguage)
        ->toBeString();
    // user_id=2 is CurrentConfig::defaultUserId()'s own default (the
    // guest account) -- getDefaultLanguage() reads *this* row, entirely
    // independent of CurrentUser (id=1 in this file's own beforeEach()).
    $conn->executeStatement("UPDATE user_infos SET language = 'zz_ZZ' WHERE user_id = 2");
    searchServiceTestProcessCache()
        ->forget('default_user');

    try {
        expect(fn (): array => searchServiceTestService()->getQuickSearchResultsNoCache('nature', []))
            ->toThrow(LogicException::class, 'qsearch: \Piwigo\Search\Inflector\InflectorZz does not implement InflectorInterface');
    } finally {
        $conn->executeStatement('UPDATE user_infos SET language = ? WHERE user_id = 2', [$originalLanguage]);
        searchServiceTestProcessCache()
            ->forget('default_user');
    }
});

// A test forcing getAvailableSearchUuid()'s internal retry-on-collision
// branch to fire deterministically on the very first candidate would
// need to substitute SearchRepository::countSavedSearchByUuid()'s
// behavior -- SearchRepository is `final` (a real architectural choice,
// matching this codebase's other repository classes) and SearchService's
// constructor takes the concrete class directly, not an interface, so
// there is no injectable seam here. The two tests above already cover
// the real, user-observable contract (matches the expected uuid shape; a
// real DB collision from a prior call's uuid produces a different next
// uuid) -- forcing the exact internal retry-recursion path would require
// either reflection or loosening the final class, neither of which is
// worth it for an implementation detail this deeply internal.
