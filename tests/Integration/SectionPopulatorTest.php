<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
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
use Piwigo\Group\GroupEntity;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionPopulator;
use Piwigo\Section\SectionRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
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

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
        LangTestFactory::get()->setLangInfo([
            'code' => 'en_UK',
            'direction' => 'ltr',
        ]);
        CurrentConfigTestFactory::get()->sendPiwigoInfos = false;
        CurrentConfigTestFactory::get()->questionMarkInUrls = false;

        $this->conn = DbConnection::build();
        $em = EntityManagerFactory::build($this->conn);
        $categoryRepo = new CategoryRepository($em, CurrentConfigTestFactory::get());
        $this->filterState = new FilterState();
        $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        $this->permissionService = new PermissionService(new PermissionRepository($em), $em->getRepository(GroupEntity::class), $categoryRepo, CurrentUserTestFactory::get(), $this->filterState, $accessLevelChecker);
        $this->categoryService = new CategoryService(LangTestFactory::get(), $categoryRepo, $this->permissionService, CurrentConfigTestFactory::get(), new EventDispatcher(), TranslatorTestFactory::get(), $accessLevelChecker);
        $this->sessionService = new SessionService($em->getRepository(SessionEntity::class), CurrentConfigTestFactory::get());
        $this->tagService = new TagService(LangTestFactory::get(), $em->getRepository(TagEntity::class), $this->permissionService, new ActivityService($em->getRepository(ActivityEntity::class)), new EventDispatcher(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new CurrentLogger(), $this->sessionService);
        $this->userService = new UserService(LangTestFactory::get(), new UserRepository($em, new EventDispatcher(), CurrentConfigTestFactory::get()), $em->getRepository(GroupEntity::class), new ActivityService($em->getRepository(ActivityEntity::class)), HtmlServiceTestFactory::build(), $this->conn, $this->sessionService, new EventDispatcher(), new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new InstallationFlag(), new ProcessCache(), CurrentPathsTestFactory::get());
        $this->searchService = new SearchService(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), new SearchRepository($em), $this->permissionService, $this->categoryService, HtmlServiceTestFactory::build(), new RedirectService(LangTestFactory::get(), $this->userService, EventDispatcherTestFactory::get(), PageStateTestFactory::get()), $this->sessionService, new EventDispatcher(), CurrentUserTestFactory::get(), LangTestFactory::get(), CurrentConfigTestFactory::get(), new CurrentLogger(), new DeploymentPolicy(), CurrentPathsTestFactory::get(), $this->tagService);
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
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        parent::tearDown();
    }

    private function makeTemplate(): Template
    {
        return TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default');
    }

    private function makePopulator(): SectionPopulator
    {
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
            new RedirectService(LangTestFactory::get(), $this->userService, EventDispatcherTestFactory::get(), PageStateTestFactory::get()),
            UrlServiceTestFactory::build(),
            $this->filterState,
            $this->currentLogger,
            $this->sectionContextRegistry,
            new RequestMountDepth(),
            $this->sessionService,
            new EventDispatcher(),
            PageStateTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            TranslatorTestFactory::get(),
            ImageStdParamsTestFactory::get(),
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
        // getPreferredImageOrders()[11][2] is false: incompatible. (Index
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
        self::assertSame(1, $ctx->category['id']);
        self::assertNotNull($ctx->combinedCategories);
        self::assertCount(1, $ctx->combinedCategories);
        self::assertSame(2, $ctx->combinedCategories[0]['id']);
        // getImageIdsForCategories([1, 2]) defaults to $mode='AND' -- an
        // intersection, not a union: category 1's images (1,2,3) and
        // category 2's images (4,5) are disjoint sets, so "in both at
        // once" is genuinely empty. Confirmed live against the real
        // fixture, not assumed.
        self::assertCount(0, $ctx->items);
    }

    public function testPopulateAppliesTheCategorysOwnCustomImageOrder(): void
    {
        $this->conn->executeStatement("UPDATE categories SET image_order = 'name ASC' WHERE id = 1");
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1';

        try {
            $this->makePopulator()
                ->populate();
        } finally {
            $this->conn->executeStatement('UPDATE categories SET image_order = NULL WHERE id = 1');
        }

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertCount(3, $ctx->items);
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
}
