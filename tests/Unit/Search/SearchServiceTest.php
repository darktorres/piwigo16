<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\CachePools;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Event\Search\QsearchGetScopes;
use Piwigo\Group\GroupEntity;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Event\QsearchGetImagesSqlScopes;
use Piwigo\Search\Event\QsearchResults;
use Piwigo\Search\QExpression;
use Piwigo\Search\QsearchClause;
use Piwigo\Search\QResults;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
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

/**
 * Test-only HtmlRenderingInterface: turns the `never`-typed
 * badRequest()/fatalError() calls into a catchable exception instead of a
 * real header()+exit() redirect, so the "invalid identifier"/"not found"
 * gates on SearchService's own $htmlRenderer can be observed from a test.
 * Every other method throws too -- none of the scenarios exercised through
 * this fake ever reach tag/category matching (which is the only other
 * HtmlRenderingInterface method SearchService itself calls,
 * tagAlphaCompare()). Named with a SearchServiceTest-specific prefix (not
 * bare FatalSignalHtmlRenderer, matching tests/Integration/SearchServiceTest.php's
 * own name) since this file has no namespace, unlike the Integration
 * original -- every other test class this campaign has ever defined
 * inline lives in the shared global namespace across all of tests/Unit.
 */
final class SearchServiceTestFatalSignalHtmlRenderer implements HtmlRenderingInterface
{
    /**
     * @param array<int, array<string, mixed>> $catInformations
     */
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }

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
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not implemented in this fake');
    }

    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new RuntimeException('accessDenied called');
    }

    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('badRequest: ' . $msg);
    }

    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('pageNotFound: ' . ($msg ?? ''));
    }

    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new RuntimeException('fatalError: ' . $msg);
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not implemented in this fake');
    }

    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    public function renderElementName(array $info): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }
}

/**
 * A real class that deliberately does NOT implement InflectorInterface --
 * class_alias()'d onto a fake 'Piwigo\Search\Inflector\Inflector_zz' FQCN
 * by the Inflector-guard test below, standing in for exactly the real-world
 * scenario that guard defends against (a 3rd-party language pack shipping
 * a broken Inflector_xx.php for its own 2-letter code).
 */
final class SearchServiceTestNotAnInflector
{
}

/**
 * Piwigo\Search\SearchService -- has its own dedicated
 * tests/Integration/SearchServiceTest.php (~100 tests); this ports the
 * same scenarios down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern for the repository, plus a real
 * Kernel::boot() (PermissionServiceTest.php's/PermalinkServiceTest.php's
 * own established beforeEach()/afterEach() precedent) for the rest of
 * this service's 17-dependency constructor -- CategoryService,
 * PermissionService, MailService, UserService and RedirectService all
 * need real, container-wired collaborators the way the Integration
 * original builds them, not a bare Kernel-free construction.
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

function searchServiceTestMailService(): MailService
{
    $mailer = Kernel::container()->get(MailService::class);
    if (! $mailer instanceof MailService) {
        throw new LogicException('Container returned an unexpected type for ' . MailService::class);
    }

    return $mailer;
}

/**
 * Same dependency graph as makeService()/beforeEach()'s own default
 * service below, but with a caller-supplied $repo (for forcing an
 * internal collision retry) and/or HtmlRenderingInterface (for observing
 * the fatalError()/badRequest() gates without a real header()+exit()
 * redirect).
 */
function searchServiceTestMakeService(SearchRepository $repo, HtmlRenderingInterface $htmlRenderer): SearchService
{
    $conn = searchServiceTestConn();
    $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());

    return new SearchService(
        $accessLevelChecker,
        $repo,
        new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), searchServiceTestFilterState(), $accessLevelChecker),
        new CategoryService(
            LangTestFactory::get(),
            new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()),
            new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), searchServiceTestFilterState(), $accessLevelChecker),
            CurrentConfigTestFactory::get(),
            new \Piwigo\PluginConfig\EventDispatcher(),
            TranslatorTestFactory::get(),
            $accessLevelChecker
        ),
        searchServiceTestMailService(),
        $htmlRenderer,
        new RedirectService(LangTestFactory::get(), searchServiceTestUserService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
        EventDispatcherTestFactory::get(),
        CurrentUserTestFactory::get(),
        LangTestFactory::get(),
        CurrentConfigTestFactory::get(),
        new CurrentLogger(),
        new DeploymentPolicy(),
        CurrentPathsTestFactory::get(),
    );
}

function searchServiceTestService(): SearchService
{
    return searchServiceTestMakeService(searchServiceTestRepo(), HtmlServiceTestFactory::build());
}

function searchServiceTestServiceWithRenderer(HtmlRenderingInterface $htmlRenderer): SearchService
{
    return searchServiceTestMakeService(searchServiceTestRepo(), $htmlRenderer);
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
});

afterEach(function (): void {
    CachePools::searchResults()->clear();
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
    searchServiceTestRepo()->insertSavedSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-infotest01', null);

    $info = searchServiceTestService()->getSearchInfo('psk-20260712-infotest01');

    if ($info === null) {
        throw new LogicException('expected a Search projection, got null');
    }

    expect($info->searchUuid)->toBe('psk-20260712-infotest01');
});

test('getSearchInfo() returns null for an invalid identifier', function (): void {
    expect(searchServiceTestService()->getSearchInfo('garbage'))->toBeNull();
});

test('getSearchArray() round-trips the json-encoded rules', function (): void {
    $rules = ['q' => 'nature', 'fields' => ['allwords' => ['words' => ['nature']]]];
    searchServiceTestRepo()->insertSavedSearch($rules, '2026-07-12 00:00:00', 1, 'psk-20260712-arraytest0', null);

    $decoded = searchServiceTestService()->getSearchArray('psk-20260712-arraytest0');

    expect($decoded)->toBe($rules);
});

test('getSearchArray() returns false for a missing search', function (): void {
    expect(searchServiceTestService()->getSearchArray('psk-20260712-nosuchuid0'))->toBeFalse();
});

test('getAvailableSearchUuid() matches the expected shape', function (): void {
    $uuid = searchServiceTestService()->getAvailableSearchUuid();

    // Case-insensitive, matching SearchService::getSearchIdPattern()'s
    // own regex -- generate_key()'s base64-derived charset includes
    // uppercase letters.
    expect($uuid)->toMatch('/^psk-\d{8}-[a-z0-9]{10}$/i');
});

test('getAvailableSearchUuid() skips a colliding uuid', function (): void {
    $service = searchServiceTestService();
    $uuid = $service->getAvailableSearchUuid();
    searchServiceTestRepo()->insertSavedSearch(['q' => 'x'], '2026-07-12 00:00:00', null, $uuid, null);

    $next = $service->getAvailableSearchUuid();

    expect($next)->not->toBe($uuid)
        ->and(searchServiceTestRepo()->countSavedSearchByUuid($next))->toBe(0);
});

test('splitAllwords() splits on whitespace', function (): void {
    expect(SearchService::splitAllwords('nature travel'))->toBe(['nature', 'travel']);
});

test('splitAllwords() returns null for blank input', function (): void {
    expect(SearchService::splitAllwords('   '))->toBeNull();
});

test('splitAllwords() throws when preg_split() hits the backtrack limit', function (): void {
    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        SearchService::splitAllwords('nature travel');
        expect(false)->toBeTrue('expected an exception');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('splitAllwords(): preg_split() failed');
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

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

    expect($clauses)->not->toBe([]);

    $count = searchServiceTestConn()->executeQuery(
        'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')',
        $values
    )->fetchOne();

    expect(is_numeric($count) ? (int) $count : null)->toBe(0);
});

test('getRegularSearchResults() filters by width and height', function (): void {
    $search = ['fields' => [
        'width_min' => 100, 'width_max' => 300,
        'height_min' => 100, 'height_max' => 300,
    ]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by ratio', function (): void {
    // every fixture image is 200x150 -- ratio 1.333, the "Landscape"
    // bucket (1.05 < ratio < 2).
    $search = ['fields' => ['ratios' => ['Landscape']]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getRegularSearchResults() filters by category', function (): void {
    $search = ['fields' => ['cat' => ['words' => [1], 'sub_inc' => false]]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3]);
});

test('getRegularSearchResults() filters by tags', function (): void {
    $search = ['fields' => ['tags' => ['words' => [1], 'mode' => 'AND']]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3]);
});

test('getRegularSearchResults() combines two filters via intersection', function (): void {
    // cat=1 -> {1,2,3}; tags=1 -> {1,2,3} -- intersection is still
    // {1,2,3}, proving the multi-filter array_intersect() path (not just
    // the single-filter reset() shortcut) produces a valid list<int>.
    $search = ['fields' => [
        'cat' => ['words' => [1], 'sub_inc' => false],
        'tags' => ['words' => [1], 'mode' => 'AND'],
    ]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3]);
});

test('getRegularSearchResults() custom search clause', function (): void {
    $results = searchServiceTestService()->getRegularSearchResults([], new SqlCondition('i.id = 1'));

    expect($results['items'])->toBe([1]);
});

test('getRegularSearchResults() returns empty for no filters', function (): void {
    $results = searchServiceTestService()->getRegularSearchResults([]);

    expect($results['items'])->toBe([])
        ->and($results['search_details']['has_filters_filled'])->toBeFalse();
});

test('getRegularSearchResults() filters by expert string', function (): void {
    // The 'expert' criterion delegates to getQuickSearchResults() itself
    // -- "family" resolves via the tag-name quick-search path to image 1.
    $search = ['fields' => ['expert' => ['string' => 'family']]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    expect($results['items'])->toBe([1])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by author field with no match', function (): void {
    // Every fixture image has a NULL author -- proves the criterion
    // executes end to end (well-formed empty result), not that it
    // matches anything.
    $search = ['fields' => ['author' => ['words' => ['Someone']]]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    expect($results['items'])->toBe([])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by filetypes', function (): void {
    // every fixture image's path ends in .jpg.
    $search = ['fields' => ['filetypes' => ['jpg']]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by added_by', function (): void {
    // every fixture image has added_by = 1.
    $search = ['fields' => ['added_by' => [1]]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by date_posted preset', function (): void {
    // NOW()-relative rather than a hardcoded literal, so this stays
    // correct regardless of the real wall-clock date the suite runs on.
    $conn = searchServiceTestConn();
    $conn->executeStatement(
        'UPDATE ' . Tables::images() . ' SET date_available = ' . searchServiceTestNowMinusInterval(1, 'HOUR') . ' WHERE id IN (1, 2)'
    );
    $conn->executeStatement(
        'UPDATE ' . Tables::images() . ' SET date_available = ' . searchServiceTestNowMinusInterval(30, 'HOUR') . ' WHERE id IN (3, 4, 5)'
    );

    try {
        $search = ['fields' => ['date_posted' => ['preset' => '24h']]];
        $results = searchServiceTestService()->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        expect($items)->toBe([1, 2])
            ->and($results['search_details']['has_filters_filled'])->toBeTrue();
    } finally {
        $conn->executeStatement(
            "UPDATE " . Tables::images() . " SET date_available = '2026-08-01 00:00:00' WHERE id IN (1,2,3,4,5)"
        );
    }
});

test('getRegularSearchResults() filters by date_created preset', function (): void {
    $conn = searchServiceTestConn();
    $conn->executeStatement(
        'UPDATE ' . Tables::images() . ' SET date_creation = ' . searchServiceTestNowMinusInterval(1, 'DAY') . ' WHERE id IN (1, 2, 3)'
    );
    $conn->executeStatement(
        'UPDATE ' . Tables::images() . ' SET date_creation = ' . searchServiceTestNowMinusInterval(60, 'DAY') . ' WHERE id IN (4, 5)'
    );

    try {
        $search = ['fields' => ['date_created' => ['preset' => '7d']]];
        $results = searchServiceTestService()->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        expect($items)->toBe([1, 2, 3]);
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
    }
});

test('getRegularSearchResults() date_created custom range', function (): void {
    $conn = searchServiceTestConn();
    $conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2024-03-15 12:00:00' WHERE id = 1");
    $conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '2025-01-01 00:00:00' WHERE id = 2");

    try {
        // Mixes a 'y'/'m'/'d'-prefixed string entry of each shape plus a
        // non-string (int) entry that matches none of them -- exercises
        // dateFilterClause()'s custom-range subclause building for all 3
        // prefix shapes plus the mixed-type $custom array-building loop.
        $search = ['fields' => ['date_created' => [
            'preset' => 'custom',
            'custom' => ['y2024', 'm2023-06', 'd2022-05-15', 20250101],
        ]]];
        $results = searchServiceTestService()->getRegularSearchResults($search);

        $items = $results['items'];
        sort($items);
        expect($items)->toBe([1]);
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id IN (1,2,3,4,5)');
    }
});

test('getRegularSearchResults() date_posted with unrecognized preset matches everything', function (): void {
    // dateFilterClause() falls back to a permissive '1=1' clause for a
    // preset that's neither a recognized threshold nor 'custom'.
    $search = ['fields' => ['date_posted' => ['preset' => 'not-a-real-preset']]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getRegularSearchResults() filters by ratings null and numeric bucket', function (): void {
    // image5's rating_score is NULL (the '0' bucket); image1 is 4.50
    // (falls in the '5' bucket's [4,5) range).
    $search = ['fields' => ['ratings' => ['0', '5']]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() filters by filesize range', function (): void {
    // every fixture image's filesize is 1 (KB) -- comfortably inside a
    // [1-100, 2+100] BETWEEN range.
    $search = ['fields' => ['filesize_min' => 1, 'filesize_max' => 2]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5])
        ->and($results['search_details']['has_filters_filled'])->toBeTrue();
});

test('getRegularSearchResults() allwords matches by album title', function (): void {
    // 'Nested' matches category 2's name ("Nested Sub Album", images 4
    // and 5) -- exercises searchAllwords()'s category-name matching
    // sub-branch (image ids folded into the word's own field clauses),
    // and 'mode' omitted exercises the "default to AND" fallback.
    $search = ['fields' => ['allwords' => [
        'words' => ['Nested'],
        'fields' => ['cat-title'],
    ]]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([4, 5]);
});

test('getRegularSearchResults() allwords matches by tag name', function (): void {
    // 'travel' (tag 2) only tags image 1 -- exercises searchAllwords()'s
    // tag-name matching sub-branch.
    $search = ['fields' => ['allwords' => [
        'words' => ['travel'],
        'fields' => ['tags'],
        'mode' => 'OR',
    ]]];

    $results = searchServiceTestService()->getRegularSearchResults($search);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() finds a tag-named match', function (): void {
    // "family" only tags image 1 -- exercises qsearchGetTags() ->
    // qsearchEval() -> permission-filtered final query end to end,
    // including the quote()-based FULLTEXT/REGEXP clauses.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('family', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() returns empty for no match', function (): void {
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('nosuchtermatall', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() finds a category-named match', function (): void {
    // "Nested" only matches category 2's name ("Nested Sub Album",
    // fixture) -- exercises qsearchGetCategories(), which filters
    // categories via $user['forbidden_categories'] instead of an INNER
    // JOIN against user_cache_categories, end to end. Category 2 holds
    // images 4 and 5 (piwigo_image_category fixture).
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('Nested', []);

    expect($results['items'])->toBe([4, 5]);
});

test('getQuickSearchResultsNoCache() excludes a forbidden category match', function (): void {
    // Same search as above, but with category 2 marked forbidden for
    // this user -- proves the NOT IN (...) replacement actually excludes
    // it, not just that it's syntactically present.
    CurrentUserTestFactory::get()->set(User::fromUserArray(array_merge(searchServiceTestRealisticUserGlobal(), ['forbidden_categories' => '2'])));

    $results = searchServiceTestService()->getQuickSearchResultsNoCache('Nested', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResults() caches across calls', function (): void {
    // CachePools::searchResults() backs quick-search result caching --
    // proven by mutating the underlying data (tag image 2 "family",
    // which the fixture doesn't already do -- only image 1 is) after the
    // first (caching) call, then showing a 2nd call with the same query
    // still returns the stale (pre-mutation) result.
    $service = searchServiceTestService();

    $first = $service->getQuickSearchResults('family', []);
    expect($first['items'])->toBe([1]);

    $conn = searchServiceTestConn();
    $conn->executeStatement(
        'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (2, 3)'
    );

    try {
        $second = $service->getQuickSearchResults('family', []);
        expect($second['items'])->toBe($first['items'], 'a cache hit must not re-query the DB')
            ->and($second)->not->toHaveKey('debug');
    } finally {
        $conn->executeStatement(
            'DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 2 AND tag_id = 3'
        );
    }
});

test('qsearchGetTextTokenSearchSql() falls back to REGEXP for a leading wildcard', function (): void {
    // QST_WILDCARD_BEGIN forces useFt=false regardless of term length --
    // MySQL FULLTEXT can't do a leading-wildcard prefix match.
    $token = new QSingleToken('hoto', QSingleToken::QST_WILDCARD_BEGIN, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name']);

    expect($clauses)->not->toBe([]);
    $count = searchServiceTestConn()->executeQuery(
        'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')',
        $values
    )->fetchOne();
    // every fixture image is named "Photo N" -- "hoto" (no left boundary
    // required) matches all 5.
    expect(is_numeric($count) ? (int) $count : null)->toBe(5);
});

test('qsearchGetTextTokenSearchSql() falls back to REGEXP for a quoted trailing wildcard', function (): void {
    $modifier = QSingleToken::QST_QUOTED | QSingleToken::QST_WILDCARD_END;
    $token = new QSingleToken('Phot', $modifier, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name']);

    expect($clauses)->not->toBe([]);
    $count = searchServiceTestConn()->executeQuery(
        'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')',
        $values
    )->fetchOne();
    expect(is_numeric($count) ? (int) $count : null)->toBe(5);
});

test('qsearchGetTextTokenSearchSql() falls back to REGEXP when every split part is short', function (): void {
    // "ab-cd" splits (on the punctuation class) into ["ab","cd"], both
    // shorter than 4 chars -- forces useFt=false even though the whole
    // term itself is longer than 3 chars.
    $token = new QSingleToken('ab-cd', 0, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name']);

    expect($clauses)->not->toBe([]);
    $count = searchServiceTestConn()->executeQuery(
        'SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE (' . implode(' OR ', $clauses) . ')',
        $values
    )->fetchOne();
    expect(is_numeric($count) ? (int) $count : null)->toBe(0);
});

test('qsearchGetTextTokenSearchSql() wraps a quoted term in double quotes for FULLTEXT', function (): void {
    $token = new QSingleToken('nature', QSingleToken::QST_QUOTED, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

    if (DbCredentials::fromEnv()->driver === 'pgsql') {
        expect($clauses)->toBe(["tsv_search @@ to_tsquery('simple', ?)"])
            ->and($values)->toBe(['nature']);
    } else {
        expect($clauses)->toBe(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE)'])
            ->and($values)->toBe(['"nature"']);
    }
});

test('qsearchGetTextTokenSearchSql() appends a star for a trailing wildcard FULLTEXT term', function (): void {
    $token = new QSingleToken('travel', QSingleToken::QST_WILDCARD_END, null);

    [$clauses, $values] = searchServiceTestService()->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);

    if (DbCredentials::fromEnv()->driver === 'pgsql') {
        expect($clauses)->toBe(["tsv_search @@ to_tsquery('simple', ?)"])
            ->and($values)->toBe(['travel:*']);
    } else {
        expect($clauses)->toBe(['MATCH(name, comment) AGAINST(? IN BOOLEAN MODE)'])
            ->and($values)->toBe(['travel*']);
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
        searchServiceTestService()->qsearchGetTextTokenSearchSql(new QSingleToken('hello-world', 0, null), ['name']);
        expect(false)->toBeTrue('expected an exception');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('qsearchGetTextTokenSearchSql(): preg_split() failed');
    } finally {
        ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
    }
});

test('getQuickSearchResultsNoCache() matches the author scope when populated', function (): void {
    // Every fixture image has a NULL author -- a non-empty author: term
    // never matches, but proves the 'author' scope's non-empty branch
    // runs end to end.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('author:someone', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() wildcarded empty author matches authored images', function (): void {
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('author:*', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() plain empty author matches unauthored images', function (): void {
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('author:', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by width and height scopes', function (): void {
    // every fixture image is 200x150.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('width:200 height:150', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by ratio scope', function (): void {
    // 200/150 = 1.3333... -- comfortably inside the explicit range.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('ratio:1.3..1.4', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by size scope', function (): void {
    // width*height = 200*150 = 30000 for every fixture image.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('size:30000', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by hits scope', function (): void {
    // every fixture image's hit counter is 0.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('hits:0', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by score scope excluding unrated', function (): void {
    // rating_score: 4.50/3.00/5.00/2.00/NULL for images 1-5 -- image5's
    // NULL never satisfies a numeric BETWEEN-style clause.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('score:2..5', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4]);
});

test('getQuickSearchResultsNoCache() filters by filesize scope', function (): void {
    // 1024*filesize = 1024*1 = 1024 for every fixture image.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('filesize:1000..2000', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by created scope with no match', function (): void {
    // date_creation is NULL for every fixture image.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('created:2024..2027', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() filters by posted scope', function (): void {
    // date_available is '2026-08-01 00:00:00' for every fixture image.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('posted:2024..2027', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
});

test('getQuickSearchResultsNoCache() filters by id scope', function (): void {
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('id:1..3', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3]);
});

test('getQuickSearchResultsNoCache() filters by file scope', function (): void {
    // only image 1's filename ('fixture-photo-1.jpg') contains
    // "photo-1" -- image 10 doesn't exist, so there's no accidental
    // substring collision.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('file:photo-1', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() unhandled scope falls through the default hook branch', function (): void {
    // 'tag' has no dedicated case in qsearchGetImages()'s own switch (it
    // has its own dedicated qsearchGetTags() path instead) -- a
    // tag-scoped token falls to the default/plugin-hook branch there,
    // contributing nothing to images_iids; the final match comes
    // entirely from qsearchGetTags()'s own tag_iids.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('tag:nature', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3]);
});

test('qsearchGetTags() direct call with a nullable wildcarded tag scope matches every tagged image', function (): void {
    // The real quick-search scope list registers 'tag' as non-nullable,
    // so an empty-term tag-scoped token can never survive
    // QMultiToken::push() through the normal getQuickSearchResultsNoCache()
    // path -- calling qsearchGetTags() directly with a hand-built
    // nullable 'tag' scope is the only way to reach this branch.
    $scopes = [new QSearchScope('tag', [], true)];
    $expr = new QExpression('tag:*', $scopes);
    $qsr = new QResults();

    searchServiceTestService()->qsearchGetTags($expr, $qsr);

    $imageIds = $qsr->tag_iids[0];
    sort($imageIds);
    expect($imageIds)->toBe([1, 2, 3]);
});

test('qsearchGetTags() direct call with a nullable empty tag scope matches untagged images', function (): void {
    $scopes = [new QSearchScope('tag', [], true)];
    $expr = new QExpression('tag:', $scopes);
    $qsr = new QResults();

    searchServiceTestService()->qsearchGetTags($expr, $qsr);

    $imageIds = $qsr->tag_iids[0];
    sort($imageIds);
    expect($imageIds)->toBe([4, 5]);
});

test('qsearchGetCategories() direct call with a nullable wildcarded category scope matches every categorized image', function (): void {
    // No real quick-search scope ever has id 'category' (the registered
    // scope list only has tag/photo/file/author/numeric/date scopes) --
    // same direct-call rationale as the tag tests above.
    $scopes = [new QSearchScope('category', [], true)];
    $expr = new QExpression('category:*', $scopes);
    $qsr = new QResults();

    searchServiceTestService()->qsearchGetCategories($expr, $qsr);

    $imageIds = $qsr->cat_iids[0];
    sort($imageIds);
    expect($imageIds)->toBe([1, 2, 3, 4, 5]);
});

test('qsearchGetCategories() direct call with a nullable empty category scope matches uncategorized images', function (): void {
    $scopes = [new QSearchScope('category', [], true)];
    $expr = new QExpression('category:', $scopes);
    $qsr = new QResults();

    searchServiceTestService()->qsearchGetCategories($expr, $qsr);

    expect($qsr->cat_iids[0])->toBe([]);
});

test('qsearchGetImages() dispatches the hook for an unrecognized scope and applies the returned clause', function (): void {
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

        searchServiceTestService()->qsearchGetImages($expr, $qsr);

        expect($qsr->images_iids[0])->toBe([1]);
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(QsearchGetImagesSqlScopes::class, $handler);
    }
});

test('qsearchGetImages() merges params from multiple hook clauses', function (): void {
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

        searchServiceTestService()->qsearchGetImages($expr, $qsr);

        $imageIds = $qsr->images_iids[0];
        sort($imageIds);
        expect($imageIds)->toBe([1, 2]);
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(QsearchGetImagesSqlScopes::class, $handler);
    }
});

test('qsearchGetImages() returns no matches for an unrecognized scope with no listener', function (): void {
    $scopes = [new QSearchScope('custom_field', [], true)];
    $expr = new QExpression('custom_field:*', $scopes);
    $qsr = new QResults();

    searchServiceTestService()->qsearchGetImages($expr, $qsr);

    expect($qsr->images_iids[0])->toBe([]);
});

test('getQuickSearchResultsNoCache() a lone NOT-prefixed tag match produces no results', function (): void {
    // NOT alone (no positive criterion) can never qualify a single
    // top-level token -- exercises both qsearchGetTags()'s own NOT-ids
    // accumulation and qsearchEval()'s own NOT branch.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('-family', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() a lone NOT-prefixed category match produces no results', function (): void {
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('-Sample', []);

    expect($results['items'])->toBe([]);
});

test('getQuickSearchResultsNoCache() narrows two adjacent short terms to a shared tag match', function (): void {
    // "dog" (<=3 chars) is too short for a real fixture tag -- insert a
    // temporary one so 2 adjacent short terms genuinely share a match,
    // exercising qsearchGetTags()'s own short-token intersection.
    $conn = searchServiceTestConn();
    $conn->executeStatement(
        "INSERT INTO " . Tables::tags() . " (name, url_name, lastmodified) VALUES ('dog', 'dog', NOW())"
    );
    $tagId = (int) $conn->lastInsertId();
    $conn->executeStatement(
        'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (2, ?)',
        [$tagId]
    );

    try {
        $results = searchServiceTestService()->getQuickSearchResultsNoCache('dog dog', []);

        expect($results['items'])->toBe([2]);
    } finally {
        $conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE tag_id = ?', [$tagId]);
        $conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE id = ?', [$tagId]);
    }
});

test('getQuickSearchResultsNoCache() narrows two adjacent short terms to a shared category match', function (): void {
    // "Sub" (<=3 chars) whole-word-matches category 2's name ("Nested
    // Sub Album") -- exercises qsearchGetCategories()'s own analogous
    // short-token intersection.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('Sub Sub', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([4, 5]);
});

test('getQuickSearchResultsNoCache() expands to subalbums when enabled', function (): void {
    CurrentConfigTestFactory::get()->setQuickSearchIncludeSubAlbums(true);

    // "Sample" matches category 1 ("Sample Album") only, by name -- with
    // sub-album inclusion enabled this expands to include category 2
    // (its child, per the fixture's uppercats), pulling in images 4 and
    // 5 too.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('Sample', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3, 4, 5]);
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
    CurrentConfigTestFactory::get()->setQuickSearchIncludeSubAlbums(true);
    $conn = searchServiceTestConn();
    $originalUppercats = $conn->fetchOne('SELECT uppercats FROM ' . Tables::categories() . ' WHERE id = 2');
    expect($originalUppercats)->toBeString();
    $conn->executeStatement("UPDATE " . Tables::categories() . " SET uppercats = '999' WHERE id = 2");

    try {
        // "Nested" matches category 2 ("Nested Sub Album") by name only.
        $results = searchServiceTestService()->getQuickSearchResultsNoCache('Nested', []);

        expect($results['items'])->toBe([]);
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::categories() . ' SET uppercats = ? WHERE id = 2', [$originalUppercats]);
    }
});

test('getQuickSearchResultsNoCache() OR keyword unions two tag matches', function (): void {
    // "family" tags image 1 only; "nature" tags images 1,2,3. The
    // literal "OR" keyword sets QST_OR on the following token
    // (QMultiToken::parse()), exercising qsearchEval()'s own OR-modifier
    // union branch -- every other multi-term search test in this file
    // exercises the implicit AND/intersection instead.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('family OR nature', []);

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 2, 3]);
});

test('getQuickSearchResultsNoCache() evaluates a parenthesized sub-group', function (): void {
    // "(nature)" is a nested QMultiToken sub-expression -- exercises
    // qsearchEval()'s own recursive branch (a non-QSingleToken child).
    // "nature" tags images 1,2,3; "family" tags image 1 only -- the
    // implicit AND between the group and the trailing word intersects
    // down to image 1.
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('(nature) family', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() throws when a QsearchGetScopes handler returns something other than a QsearchGetScopes instance', function (): void {
    // addEventHandler(), not addTypedHandler() -- a real plugin handler
    // is untyped from PHPStan's perspective, and this test exercises
    // dispatchChange()'s own runtime enforcement, not a static one.
    $handler = static fn (): mixed => null;
    EventDispatcherTestFactory::get()->addEventHandler(QsearchGetScopes::class, $handler);

    try {
        searchServiceTestService()->getQuickSearchResultsNoCache('family', []);
        expect(false)->toBeTrue('expected an exception');
    } catch (Error $e) {
        expect($e->getMessage())->toContain('must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(QsearchGetScopes::class, $handler);
    }
});

test('getQuickSearchResultsNoCache() falls back when a hook returns non-array items and qs', function (): void {
    $handler = static function (QsearchResults $event): QsearchResults {
        $searchResults = $event->searchResults;
        $searchResults['items'] = 'not-an-array';
        $searchResults['qs'] = 'not-an-array-either';

        return new QsearchResults($searchResults, $event->expression, $event->qsr);
    };
    EventDispatcherTestFactory::get()->addTypedHandler(QsearchResults::class, $handler);

    try {
        $results = searchServiceTestService()->getQuickSearchResultsNoCache('family', []);
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(QsearchResults::class, $handler);
    }

    // The hook only corrupts $searchResults['items']/['qs'] -- both
    // safely fall back ([] / the reconstructed default qs) -- but the
    // real tag/category match computed *before* the hook ran (the
    // fixture's own 'family' tag, id 3, linked to image 1) still reaches
    // the final result via $ids, independently of whatever the hook
    // did. Confirmed live: this method never discards that real match
    // just because the hook's own extra items were unusable.
    expect($results['items'])->toBe([1])
        ->and($results['qs'])->toBe(['q' => 'family', 'unmatched_terms' => []]);
});

test('getQuickSearchResultsNoCache() merges extra numeric ids from a plugin hook', function (): void {
    $handler = static function (QsearchResults $event): QsearchResults {
        $searchResults = $event->searchResults;
        $searchResults['items'] = ['4', 'not-numeric'];

        return new QsearchResults($searchResults, $event->expression, $event->qsr);
    };
    EventDispatcherTestFactory::get()->addTypedHandler(QsearchResults::class, $handler);

    try {
        $results = searchServiceTestService()->getQuickSearchResultsNoCache('family', []);
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(QsearchResults::class, $handler);
    }

    $items = $results['items'];
    sort($items);
    expect($items)->toBe([1, 4]);
});

test('getQuickSearchResultsNoCache() returns early for an empty query', function (): void {
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('', []);

    expect($results['items'])->toBe([])
        ->and($results['qs'])->toBe(['q' => '', 'unmatched_terms' => []]);
});

test('getQuickSearchResultsNoCache() works with a non-default calendar datefield', function (): void {
    // calendarDatefield() !== 'date_creation' takes the else branch,
    // appending 'date' to $postedDateAliases instead of
    // $createdDateAliases -- proves the scope list still builds and the
    // search still functions correctly either way.
    CurrentConfigTestFactory::get()->setCalendarDatefield('date_available');

    $results = searchServiceTestService()->getQuickSearchResultsNoCache('family', []);

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() applies a custom images_where clause', function (): void {
    // "nature" alone matches images 1,2,3; a custom images_where narrows
    // that down further, proving the clause is genuinely applied (not
    // coincidentally the same result).
    $results = searchServiceTestService()->getQuickSearchResultsNoCache('nature', ['images_where' => 'id = 2']);

    expect($results['items'])->toBe([2]);
});

test('getValidatedSearchInfo() calls fatalError() for an invalid identifier', function (): void {
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    try {
        $service->getValidatedSearchInfo('not-a-valid-identifier', null);
        expect(false)->toBeTrue('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('fatalError: Invalid search identifier');
    }
});

test('getValidatedSearchInfo() calls fatalError() when a uuid search is looked up by bare id', function (): void {
    $id = searchServiceTestRepo()->insertSavedSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-fatalidtst', null);

    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    try {
        $service->getValidatedSearchInfo((string) $id, null);
        expect(false)->toBeTrue('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('fatalError: this search is not reachable with its id, need the search_uuid instead');
    }
});

test('getValidatedSearchArray() calls badRequest() when the search is not found', function (): void {
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    try {
        // getSearchIdPattern()'s own search_uuid regex requires exactly
        // 10 alphanumeric chars after the date ('doesnotexist' is 12) --
        // confirmed live, a too-long suffix doesn't match *any*
        // recognised pattern at all, so getValidatedSearchInfo()'s
        // earlier "Invalid search identifier" fatalError() fires first
        // instead of ever reaching the not-found badRequest() this test
        // means to exercise.
        $service->getValidatedSearchArray('psk-20260712-doesnotexi', null);
        expect(false)->toBeTrue('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('badRequest: this search identifier does not exist');
    }
});

test('getSearchResults() calls badRequest() when the search identifier does not exist', function (): void {
    $service = searchServiceTestServiceWithRenderer(new SearchServiceTestFatalSignalHtmlRenderer());

    try {
        $service->getSearchResults('psk-20260712-doesnotexist', null);
        expect(false)->toBeTrue('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('badRequest: this search identifier does not exist');
    }
});

test('getSearchResults() resolves a saved quick search query', function (): void {
    $id = searchServiceTestRepo()->insertSavedSearch(['q' => 'family'], '2026-07-12 00:00:00', 1, 'psk-20260712-quicksrch1', null);

    $results = searchServiceTestService()->getSearchResults((string) $id, true, '');

    expect($results['items'])->toBe([1]);
});

test('getQuickSearchResultsNoCache() throws when the default user language resolves to an Inflector class that does not implement the interface', function (): void {
    // See SearchServiceTestNotAnInflector's own docblock above for why
    // class_alias() is the only real way in.
    if (! class_exists('Piwigo\\Search\\Inflector\\Inflector_zz', false)) {
        class_alias(SearchServiceTestNotAnInflector::class, 'Piwigo\\Search\\Inflector\\Inflector_zz');
    }

    $conn = searchServiceTestConn();
    $originalLanguage = $conn->fetchOne('SELECT language FROM ' . Tables::userInfos() . ' WHERE user_id = 2');
    expect($originalLanguage)->toBeString();
    // user_id=2 is CurrentConfig::defaultUserId()'s own default (the
    // guest account) -- getDefaultLanguage() reads *this* row, entirely
    // independent of CurrentUser (id=1 in this file's own beforeEach()).
    $conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'zz_ZZ' WHERE user_id = 2");
    searchServiceTestProcessCache()->forget('default_user');

    try {
        searchServiceTestService()->getQuickSearchResultsNoCache('nature', []);
        expect(false)->toBeTrue('expected an exception');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe('qsearch: \Piwigo\Search\Inflector\Inflector_zz does not implement InflectorInterface');
    } finally {
        $conn->executeStatement('UPDATE ' . Tables::userInfos() . ' SET language = ? WHERE user_id = 2', [$originalLanguage]);
        searchServiceTestProcessCache()->forget('default_user');
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
