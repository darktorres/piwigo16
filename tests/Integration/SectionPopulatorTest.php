<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\CalendarNavCachePool;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Cache\SectionImageIdsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SortRenderer;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Section\Event\GetNbImagePage;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionItemQuery;
use Piwigo\Section\SectionPopulator;
use Piwigo\Section\SectionRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\RequestMetricsTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;

/**
 * Covers Piwigo\Section\SectionPopulator::populate() end to end.
 * Fixture (tests/Fixtures/piwigo-17.0.sql):
 * category 1 "Sample Album" (root, uppercats='1', 3 images: 1,2,3),
 * category 2 "Nested Sub Album" (child of 1, uppercats='1,2', images 4,5);
 * tags 1 "nature" (images 1,2,3), 2 "travel" (image 1), 3 "family" (image
 * 1); user 1 fixture_admin (admin, favorites: images 1,3,5), user 3
 * regular_user (normal), user 2 guest.
 *
 * Every redirect()-driven scenario below relies on PHPUnit's own per-test
 * output buffering keeping headers_sent() false throughout (confirmed via
 * this project's own established setStatusHeader()-under-Pest precedent,
 * see RedirectServiceTest.php's docblock) -- redirect() then always takes
 * the redirectHttp() branch (a bare 302, never PageTail::renderToString()),
 * so none of these scenarios need the 'check_for_updates' lock trick
 * PageTailTest.php/RedirectServiceTest.php's own *redirectHtml()*-reaching
 * scenarios do.
 *
 * The permalink-redirect branch's own `$this->redirectService->redirect($redirect_url);`
 * fallback (reached only when headers_sent() is already true) is left
 * uncovered for the identical reason: under buffered test output,
 * headers_sent() can never observably become true from within a test, so
 * that one fallback line has no reachable seam here -- the `!headers_sent()`
 * branch immediately above it (a real redirectHttp() 301) is covered
 * instead.
 */
final class SectionPopulatorTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private CategoryService $categoryService;

    private PermissionService $permissionService;

    private TagService $tagService;

    private SearchService $searchService;

    private UserService $userService;

    private SectionRepository $sectionRepo;

    private FilterState $filterState;

    private CurrentLogger $currentLogger;

    private SectionContextRegistry $sectionContextRegistry;

    private SessionService $sessionService;

    private EntityManagerInterface $entityManager;

    private EventDispatcher $eventDispatcher;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
        LangTestFactory::get()->setLangInfo([
            'code' => 'en_UK',
            'direction' => 'ltr',
        ]);
        CurrentConfigTestFactory::get()->sendPiwigoInfos = false;
        CurrentConfigTestFactory::get()->questionMarkInUrls = false;

        $this->conn = DbConnection::build();
        $em = EntityManagerFactory::build($this->conn);
        $this->entityManager = $em;
        $this->eventDispatcher = new EventDispatcher();
        $categoryRepo = new CategoryRepository($em, CurrentConfigTestFactory::get());
        $this->filterState = new FilterState();
        $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        $this->permissionService = new PermissionService(new PermissionRepository($em), TypedRepository::narrow($em->getRepository(GroupEntity::class), GroupRepository::class), $categoryRepo, CurrentUserTestFactory::get(), $this->filterState, $accessLevelChecker);
        $this->categoryService = new CategoryService(LangTestFactory::get(), $categoryRepo, $this->permissionService, CurrentConfigTestFactory::get(), new EventDispatcher(), TranslatorTestFactory::get(), $accessLevelChecker);
        $this->sessionService = new SessionService(TypedRepository::narrow($em->getRepository(SessionEntity::class), SessionRepository::class), CurrentConfigTestFactory::get());
        $this->tagService = new TagService(LangTestFactory::get(), TypedRepository::narrow($em->getRepository(TagEntity::class), TagRepository::class), $this->permissionService, new ActivityService(TypedRepository::narrow($em->getRepository(ActivityEntity::class), ActivityRepository::class)), new EventDispatcher(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new CurrentLogger());
        $this->userService = new UserService(LangTestFactory::get(), new UserRepository($em, new EventDispatcher(), CurrentConfigTestFactory::get()), TypedRepository::narrow($em->getRepository(GroupEntity::class), GroupRepository::class), new ActivityService(TypedRepository::narrow($em->getRepository(ActivityEntity::class), ActivityRepository::class)), HtmlServiceTestFactory::build(), $this->sessionService, new EventDispatcher(), new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new InstallationFlag(), new ProcessCache(), CurrentPathsTestFactory::get(), $em, $this->permissionService, $this->categoryService, new PasswordService(new PasswordRepository($em), new DeploymentPolicy()));
        $searchResultsCachePool = Kernel::container()->get(SearchResultsCachePool::class);
        if (! $searchResultsCachePool instanceof SearchResultsCachePool) {
            throw new LogicException('Container returned an unexpected type for ' . SearchResultsCachePool::class);
        }
        $imageService = new ImageService(TypedRepository::narrow($em->getRepository(ImageEntity::class), ImageRepository::class), new ActivityService(TypedRepository::narrow($em->getRepository(ActivityEntity::class), ActivityRepository::class)), new EventDispatcher(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), $this->categoryService);
        $this->searchService = new SearchService(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), new SearchRepository($em), $this->permissionService, $this->categoryService, HtmlServiceTestFactory::build(), new RedirectService(LangTestFactory::get(), $this->userService, EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get())), $this->sessionService, new EventDispatcher(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new SortRenderer($this->conn), $this->tagService, $imageService, $this->userService, new PreferencesService(new UserRepository($em, new EventDispatcher(), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get()), $searchResultsCachePool);
        $this->sectionRepo = new SectionRepository($em);
        $this->currentLogger = new CurrentLogger();
        $this->currentLogger->set(new Logger([
            'severity' => Logger::OFF,
        ]));
        // Must be the container-shared instance, not a fresh new
        // SectionContextRegistry() -- UrlService::duplicateIndexUrl()
        // (called by the permalink-redirect test below) reads it through
        // the currentStatic() shim, which resolves the container-shared
        // instance, not whatever's passed to SectionPopulator directly;
        // a disconnected instance here left that shim seeing an empty
        // registry, silently dropping the category from the rebuilt URL
        // (same fix MenubarRendererTest's own setUp() already established).
        $sectionContextRegistry = Kernel::container()->get(SectionContextRegistry::class);
        if (! $sectionContextRegistry instanceof SectionContextRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . SectionContextRegistry::class);
        }
        $this->sectionContextRegistry = $sectionContextRegistry;

        $this->setRegularUser();
        CurrentTemplateTestFactory::get()->set($this->makeTemplate());
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO'], $_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
        unset($_SESSION['pwg_image_order'], $_GET['action']);
        CurrentUserTestFactory::get()->reset();
        CurrentTemplateTestFactory::get()->reset();
        PageStateTestFactory::get()->reset();
        LayoutStateTestFactory::get()->reset();
        RequestMetricsTestFactory::get()->reset();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    private function makeTemplate(): Template
    {
        return TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default');
    }

    private function makePopulator(): SectionPopulator
    {
        $sectionImageIdsCachePool = Kernel::container()->get(SectionImageIdsCachePool::class);
        if (! $sectionImageIdsCachePool instanceof SectionImageIdsCachePool) {
            throw new LogicException('Container returned an unexpected type for ' . SectionImageIdsCachePool::class);
        }
        $calendarNavCachePool = Kernel::container()->get(CalendarNavCachePool::class);
        if (! $calendarNavCachePool instanceof CalendarNavCachePool) {
            throw new LogicException('Container returned an unexpected type for ' . CalendarNavCachePool::class);
        }

        return new SectionPopulator(
            LangTestFactory::get(),
            new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
            HtmlServiceTestFactory::build(),
            CurrentTemplateTestFactory::get()->get(),
            $this->sectionRepo,
            $this->categoryService,
            $this->permissionService,
            $this->tagService,
            $this->searchService,
            $this->userService,
            new RedirectService(LangTestFactory::get(), $this->userService, EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get())),
            UrlServiceTestFactory::build(),
            $this->filterState,
            $this->currentLogger,
            $this->sectionContextRegistry,
            new RequestMountDepth(),
            $this->sessionService,
            $this->eventDispatcher,
            PageStateTestFactory::get(),
            LayoutStateTestFactory::get(),
            RequestMetricsTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            TranslatorTestFactory::get(),
            ImageStdParamsTestFactory::get(),
            $this->entityManager,
            $sectionImageIdsCachePool,
            $calendarNavCachePool,
        );
    }

    private function setRegularUser(): void
    {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(3),
            username: Username::from('regular_user'),
            email: Email::from('regular@example.test'),
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: true,
        ));
    }

    private function setAdminUser(): void
    {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(1),
            username: Username::from('fixture_admin'),
            email: Email::from('fixture_admin@example.test'),
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Admin,
            enabledHigh: true,
        ));
    }

    public function testPopulateSetsFlatModeForABarePictureIdWithNoCategory(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/1';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::Categories, $ctx->section);
        self::assertTrue($ctx->flat);
        self::assertNull($ctx->category);
    }

    public function testPopulateRedirectsWhenIndexHasAMatchingRandomRedirectCandidate(): void
    {
        CurrentConfigTestFactory::get()->randomIndexRedirect = [
            'random.php' => '',
        ];
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/';

        try {
            $this->makePopulator()
                ->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('random.php', $response->getHeaderLine('Location'));
        }
    }

    public function testPopulateTriggersAWarningForAnUnknownScriptBasename(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/somethingelse.php';
        $_SERVER['PATH_INFO'] = '/';

        $caughtMessage = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caughtMessage): bool {
            $caughtMessage = $errstr;
            return true;
        }, E_USER_WARNING);
        try {
            $this->makePopulator()
                ->populate();
        } finally {
            restore_error_handler();
        }

        self::assertSame('script_basename "somethingelse" unknown', $caughtMessage);
        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::Categories, $ctx->section);
    }

    public function testPopulateClearsAnIncompatibleSessionImageOrder(): void
    {
        // Order index 11 ("Permissions", 'level DESC') is only visible
        // when AccessControl::isAdmin() -- false for regular_user, so
        // getPreferredImageOrders()[11]->visible is false: incompatible. (Index
        // 10 is "Visits, low -> high", always visible -- confirmed live,
        // that index alone doesn't exercise the incompatible-clear branch.)
        $_SESSION['pwg_image_order'] = 11;
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1';

        $this->makePopulator()
            ->populate();

        self::assertArrayNotHasKey('pwg_image_order', $_SESSION);
        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        // superOrderBy reflects isset($page['super_order_by']), not its
        // boolean value -- confirmed live, the incompatible branch still
        // explicitly sets it (to false) rather than leaving it unset, so
        // isset() is true here too.
        self::assertTrue($ctx->superOrderBy);
    }

    public function testPopulateBuildsACombinedCategoriesContextAndMergesTheirImageIds(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1/2';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertNotNull($ctx->category);
        self::assertSame(1, $ctx->category->id);
        self::assertNotNull($ctx->combinedCategories);
        self::assertCount(1, $ctx->combinedCategories);
        self::assertSame(2, $ctx->combinedCategories[0]->id);
        // getImageIdsForCategories([1, 2]) defaults to $mode='AND' -- an
        // intersection, not a union: category 1's images (1,2,3) and
        // category 2's images (4,5) are disjoint sets, so "in both at
        // once" is genuinely empty. Confirmed live against the real
        // fixture, not assumed.
        self::assertCount(0, $ctx->items);
    }

    public function testPopulateAppliesTheCategorysOwnCustomImageOrder(): void
    {
        // Fixture images 1-3 are all named 'Photo N' -- 'name ASC' alone
        // wouldn't distinguish it from the default id-ascending order, so
        // this renames them out of id order to actually prove the custom
        // order_by is wired through, not just that 3 items come back
        // (mutation-tested: without this rename, disabling the real
        // override entirely left this assertion passing).
        $this->conn->executeStatement("UPDATE images SET name = 'Zzz' WHERE id = 1");
        $this->conn->executeStatement("UPDATE images SET name = 'Mmm' WHERE id = 2");
        $this->conn->executeStatement("UPDATE images SET name = 'Aaa' WHERE id = 3");
        $this->conn->executeStatement("UPDATE categories SET image_order = 'name ASC' WHERE id = 1");
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1';

        try {
            $this->makePopulator()
                ->populate();
        } finally {
            $this->conn->executeStatement('UPDATE categories SET image_order = NULL WHERE id = 1');
            $this->conn->executeStatement(
                "UPDATE images SET name = CASE id WHEN 1 THEN 'Photo 1' WHEN 2 THEN 'Photo 2' WHEN 3 THEN 'Photo 3' END WHERE id IN (1, 2, 3)"
            );
        }

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        // name ASC: image 3 ('Aaa') < image 2 ('Mmm') < image 1 ('Zzz').
        self::assertSame(['3', '2', '1'], $ctx->items);
    }

    public function testPopulateDeniesAccessWhenATagHasZeroLinkedImages(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO tags (id, name, url_name, lastmodified) VALUES (4, 'empty-tag', 'empty-tag', NOW())"
        );
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/tags/4';

        try {
            $this->makePopulator()
                ->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            // regular_user is a real, non-guest CurrentUser -- accessDenied()
            // throws its own 401 HTML page directly rather than redirecting.
            self::assertSame(401, $e->response()->getStatusCode());
        } finally {
            $this->conn->executeStatement('DELETE FROM tags WHERE id = 4');
        }
    }

    public function testPopulateStoresQsearchDetailsForAQuickSearch(): void
    {
        $searchRepo = new SearchRepository(EntityManagerFactory::build($this->conn));
        $searchId = $searchRepo->insertSavedSearch([
            'q' => 'nature',
        ], '2026-07-12 00:00:00', 3, 'psk-20260712-abcdefghij', null);
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/search/' . $searchId;

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::Search, $ctx->section);
        // getQuickSearchResultsNoCache()'s own 'qs' shape always adds
        // matching_tags/matching_cats/unmatched_terms alongside 'q'.
        self::assertSame([
            'q' => 'nature',
            'unmatched_terms' => [],
            'matching_tags' => [
                [
                    'id' => 1,
                    'name' => 'nature',
                    'url_name' => 'nature',
                    'lastmodified' => '2026-08-01 00:00:00',
                ],
            ],
            'matching_cats' => [],
        ], $ctx->qsearchDetails);
        // 'nature' matches images 1, 2, 3 (image_tag fixture rows).
        self::assertCount(3, $ctx->items);
    }

    public function testPopulateListsFavoritesAndAssignsTheRemoveAllTemplateVar(): void
    {
        $this->setAdminUser();
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/favorites';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::Favorites, $ctx->section);
        // user 1's own 3 favorited images (1, 3, 5).
        self::assertCount(3, $ctx->items);
        $favoriteVar = CurrentTemplateTestFactory::get()->get()->getTemplateVars('favorite');
        self::assertIsArray($favoriteVar);
        self::assertArrayHasKey('U_FAVORITE', $favoriteVar);
    }

    public function testPopulateDeletesAllFavoritesAndRedirects(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO favorites (user_id, image_id) VALUES (3, 2)'
        );
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/favorites';
        $_GET['action'] = 'remove_all_from_favorites';

        try {
            $this->makePopulator()
                ->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(302, $response->getStatusCode());
            // makeIndexUrl() builds a path-style URL by default in this
            // environment ('index.php/favorites'), not a raw
            // 'section=favorites' query string.
            self::assertStringContainsString('index.php/favorites', $response->getHeaderLine('Location'));
        }

        $remaining = $this->conn->fetchOne('SELECT COUNT(*) FROM favorites WHERE user_id = 3');
        self::assertSame(0, $remaining);
    }

    public function testPopulateBuildsTheRecentPicsSection(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/recent_pics';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::RecentPics, $ctx->section);
        self::assertStringContainsString('Recent photos', strip_tags($ctx->title));
    }

    public function testPopulateBuildsTheMostVisitedSection(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/most_visited';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::MostVisited, $ctx->section);
        self::assertTrue($ctx->superOrderBy);
        self::assertStringContainsString('Most visited', strip_tags($ctx->title));
    }

    public function testPopulateBuildsTheBestRatedSection(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/best_rated';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::BestRated, $ctx->section);
        self::assertTrue($ctx->superOrderBy);
        self::assertStringContainsString('Best rated', strip_tags($ctx->title));
    }

    public function testPopulateBuildsTheListSection(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/list/1,2,3';

        $this->makePopulator()
            ->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame(Section::ListView, $ctx->section);
        // parseWellKnownParamsUrl()'s own list-token parsing explode()s
        // the raw URL segment -- these stay strings, never cast to int.
        self::assertSame(['1', '2', '3'], $ctx->list);
        self::assertCount(3, $ctx->items);
        self::assertStringContainsString('Random photos', strip_tags($ctx->title));
    }

    public function testPopulateRedirectsPermanentlyOnAPermalinkMismatch(): void
    {
        CurrentConfigTestFactory::get()->categoryUrlStyle = 'id-name';
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        // Category 1 has no real permalink row -- categoryUrlStyle
        // 'id-name' + a URL name that doesn't match str2url('Sample Album')
        // ('sample_album' -- str2url() replaces spaces with underscores,
        // not hyphens, confirmed live) triggers needsPermalinkRedirect().
        $_SERVER['PATH_INFO'] = '/category/1-wrong-name';

        try {
            $this->makePopulator()
                ->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            // This branch's own setStatusHeader(301) call requires
            // redirectHttp()/RedirectServiceInterface's own explicit
            // $status parameter -- without it, ResponseFactory::redirect()'s
            // 302 default takes precedence regardless of the real header()
            // call already sent.
            self::assertSame(301, $response->getStatusCode());
            // duplicateIndexUrl() reads the current section's params from
            // SectionContextRegistry -- populate() registers the context
            // (with defensive fallbacks for every not-yet-computed field)
            // right before this early-exit redirect so the rebuilt URL
            // keeps its category instead of losing it (bare root path).
            self::assertStringContainsString('category/1-sample_album', $response->getHeaderLine('Location'));
        }
    }

    /**
     * Covers SectionPopulator::resolveSectionItems() directly (one test per
     * SectionItemQuery named constructor) -- the extracted, side-effect-free
     * item-id-resolution dispatch behind populate()'s own per-section
     * branches above. See docs/plugin-porting/rv-tscroller-port-analysis.md
     * §3 for why this exists as its own public method rather than staying
     * inline in populate().
     */
    public function testResolveSectionItemsForAPlainCategory(): void
    {
        $category = $this->categoryService->getCategoryInfo(1);
        self::assertNotNull($category);

        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::categories($category));

        self::assertSame(['1', '2', '3'], $items);
    }

    public function testResolveSectionItemsForAFlatCategory(): void
    {
        $category = $this->categoryService->getCategoryInfo(1);
        self::assertNotNull($category);

        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::flatCategory($category));

        // flat mode also includes subcategory 2's own images (4, 5)
        // alongside category 1's own (1, 2, 3).
        sort($items);
        self::assertSame(['1', '2', '3', '4', '5'], $items);
    }

    public function testResolveSectionItemsForWholeGalleryFlatModeReusesTheCache(): void
    {
        $populator = $this->makePopulator();

        $first = $populator->resolveSectionItems(SectionItemQuery::wholeGalleryFlat());
        sort($first);
        self::assertSame(['1', '2', '3', '4', '5'], $first);

        // A second call for the same user+order must hit
        // SectionImageIdsCachePool rather than requery -- proven by adding
        // an image directly (bypassing the cache write path) and confirming
        // it's still absent from the second result.
        $this->conn->executeStatement(
            "INSERT INTO images (id, file, date_available) VALUES (6, 'cache-test.jpg', NOW())"
        );
        $this->conn->executeStatement('INSERT INTO image_category (image_id, category_id) VALUES (6, 1)');
        try {
            $second = $populator->resolveSectionItems(SectionItemQuery::wholeGalleryFlat());
            sort($second);
            self::assertSame(['1', '2', '3', '4', '5'], $second);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = 6');
            $this->conn->executeStatement('DELETE FROM images WHERE id = 6');
        }
    }

    public function testResolveSectionItemsForCombinedCategories(): void
    {
        // getImageIdsForCategories()'s default mode is 'AND' (an
        // intersection) -- category 1's images (1,2,3) and category 2's
        // images (4,5) are disjoint, so this is genuinely empty, same
        // fixture reasoning as testPopulateBuildsACombinedCategoriesContextAndMergesTheirImageIds()
        // above.
        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::combinedCategories([1, 2]));

        self::assertSame([], $items);
    }

    public function testResolveSectionItemsForTags(): void
    {
        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::tags([1]));

        sort($items);
        // TagService::getImageIdsForTags() returns list<int>, unlike every
        // other branch here (list<string|null>) -- resolveSectionItems()'s
        // own return type is the broad list<int|string|null> union to
        // accommodate this.
        self::assertSame([1, 2, 3], $items);
    }

    public function testResolveSectionItemsForFavorites(): void
    {
        $this->setAdminUser();

        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::favorites());

        sort($items);
        self::assertSame(['1', '3', '5'], $items);
    }

    public function testResolveSectionItemsForRecentPics(): void
    {
        // UserService::getRecentPhotosCondition() falls back to an
        // always-false '0=1' condition unless the user's own
        // 'last_photo_date' rawAttribute is set -- this fixture never sets
        // it for any user, so this is genuinely empty here (same real
        // behavior testPopulateBuildsTheRecentPicsSection above only
        // checks the title for, not item count).
        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::recentPics());

        self::assertSame([], $items);
    }

    public function testResolveSectionItemsForMostVisitedIgnoresTheTopNumberCap(): void
    {
        // topNumber is private(set) -- ConfigService::confUpdateParam()'s
        // own reflection-based write (updateGlobal: true) is the real,
        // non-hacky way to change it, same path production code uses.
        CurrentConfigServiceTestFactory::get()->get()->confUpdateParam('top_number', 1, updateGlobal: true);
        $this->conn->executeStatement('UPDATE images SET hit = 1 WHERE id IN (1, 2, 3, 4, 5)');

        try {
            $items = $this->makePopulator()
                ->resolveSectionItems(SectionItemQuery::mostVisited());
        } finally {
            $this->conn->executeStatement('UPDATE images SET hit = 0 WHERE id IN (1, 2, 3, 4, 5)');
        }

        // Every hit image comes back despite CurrentConfig::topNumber = 1 --
        // whole-corpus pagination, not a fixed top-N cut, is now this
        // method's real contract (rv-tscroller-port-analysis.md §3).
        self::assertCount(5, $items);
    }

    public function testResolveSectionItemsForBestRatedIgnoresTheTopNumberCap(): void
    {
        CurrentConfigServiceTestFactory::get()->get()->confUpdateParam('top_number', 1, updateGlobal: true);

        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::bestRated());

        // 4 rated images (1-4); image 5's NULL rating excludes it -- same
        // fixture shape as SectionRepositoryTest's own docblock.
        self::assertCount(4, $items);
    }

    public function testResolveSectionItemsForAnImageList(): void
    {
        $items = $this->makePopulator()
            ->resolveSectionItems(SectionItemQuery::imageList(['1', '3']));

        sort($items);
        self::assertSame(['1', '3'], $items);
    }

    public function testPopulateAppliesAGetNbImagePageHandlersOverride(): void
    {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(3),
            username: Username::from('regular_user'),
            email: Email::from('regular@example.test'),
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: true,
            rawAttributes: [
                'nb_image_page' => 30,
            ],
        ));
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/';

        $handler = static function (GetNbImagePage $event): void {
            $event->value = 5;
        };

        $this->eventDispatcher->addTypedHandler(GetNbImagePage::class, $handler);

        try {
            $this->makePopulator()
                ->populate();

            $ctx = $this->sectionContextRegistry->current();
            self::assertNotNull($ctx);
            self::assertSame(5, $ctx->nbImagePage);
        } finally {
            $this->eventDispatcher->removeTypedHandler(GetNbImagePage::class, $handler);
        }
    }
}
