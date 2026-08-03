<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\PageState;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionPopulator;
use Piwigo\Section\SectionRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserInfoEntity;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Section\SectionPopulator::populate() -- had zero dedicated
 * coverage (the file's own docblock only extracted computeMetaRobots()/
 * needsPermalinkRedirect() as pure-Unit-testable helpers; populate()
 * itself, the bulk of the class, was never called from any Unit/
 * Integration/Browser test). Fixture (tests/Fixtures/piwigo-17.0.sql):
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

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher()));
        ScriptLoader::setUrlService(new UrlService(new HtmlService()));
        Lang::setLangInfo(['code' => 'en_UK', 'direction' => 'ltr']);
        CurrentConfig::setSendPiwigoInfos(false);
        CurrentConfig::setQuestionMarkInUrls(false);

        $this->conn = DbConnection::build();
        $em = \Piwigo\Db\EntityManagerFactory::build($this->conn);
        $categoryRepo = $em->getRepository(CategoryEntity::class);
        $this->permissionService = new PermissionService(new PermissionRepository($em), $em->getRepository(GroupEntity::class), $categoryRepo);
        $this->categoryService = new CategoryService($categoryRepo, $this->permissionService);
        $this->tagService = new TagService($em->getRepository(TagEntity::class), $this->permissionService, new ActivityService($em->getRepository(ActivityEntity::class)), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Users\CurrentUser::current());
        $this->sessionService = new SessionService($em->getRepository(SessionEntity::class));
        $this->searchService = new SearchService(new SearchRepository($this->conn), $this->permissionService, $this->categoryService, new MailService(), new HtmlService(), new RedirectService(), $this->sessionService, new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Users\CurrentUser::current(), $this->tagService);
        $this->userService = new UserService($em->getRepository(UserInfoEntity::class), $em->getRepository(GroupEntity::class), new MailService(), new ActivityService($em->getRepository(ActivityEntity::class)), new HtmlService(), $this->conn, $this->sessionService, new \Piwigo\PluginConfig\EventDispatcher(), new \Piwigo\Config\DeploymentPolicy(), \Piwigo\Users\CurrentUser::current());
        $this->sectionRepo = new SectionRepository($this->conn);
        $this->filterState = new FilterState();
        $this->currentLogger = new CurrentLogger();
        $this->currentLogger->set(new Logger(['severity' => Logger::OFF]));
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
            throw new \LogicException('Container returned an unexpected type for ' . SectionContextRegistry::class);
        }
        $this->sectionContextRegistry = $sectionContextRegistry;

        $this->setRegularUser();
        CurrentTemplate::current()->set($this->makeTemplate());
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO'], $_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
        unset($_SESSION['pwg_image_order'], $_GET['action']);
        CurrentUser::current()->reset();
        CurrentTemplate::current()->reset();
        PageState::current()->reset();
        CurrentConfig::reset();
        parent::tearDown();
    }

    private function makeTemplate(): Template
    {
        return new Template(CurrentPaths::get()->root . 'themes', 'default');
    }

    private function makePopulator(): SectionPopulator
    {
        return new SectionPopulator(
            new HtmlService(),
            CurrentTemplate::current()->get(),
            $this->sectionRepo,
            $this->categoryService,
            $this->permissionService,
            $this->tagService,
            $this->searchService,
            $this->userService,
            new RedirectService(),
            new UrlService(new HtmlService()),
            $this->filterState,
            $this->currentLogger,
            $this->sectionContextRegistry,
            new RequestMountDepth(),
            $this->sessionService,
            new \Piwigo\PluginConfig\EventDispatcher(),
            \Piwigo\Core\PageState::current(),
            \Piwigo\Users\CurrentUser::current(),
        );
    }

    private function setRegularUser(): void
    {
        CurrentUser::current()->set(new User(
            id: UserId::from(3),
            username: 'regular_user',
            email: 'regular@example.test',
            language: 'en_UK',
            theme: 'default',
            status: UserStatus::Normal,
            enabledHigh: true,
        ));
    }

    private function setAdminUser(): void
    {
        CurrentUser::current()->set(new User(
            id: UserId::from(1),
            username: 'fixture_admin',
            email: 'fixture_admin@example.test',
            language: 'en_UK',
            theme: 'default',
            status: UserStatus::Admin,
            enabledHigh: true,
        ));
    }

    public function test_populate_sets_flat_mode_for_a_bare_picture_id_with_no_category(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/1';

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('categories', $ctx->section);
        self::assertTrue($ctx->flat);
        self::assertNull($ctx->category);
    }

    public function test_populate_redirects_when_index_has_a_matching_random_redirect_candidate(): void
    {
        CurrentConfig::setRandomIndexRedirect(['random.php' => '']);
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/';

        try {
            $this->makePopulator()->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('random.php', $response->getHeaderLine('Location'));
        }
    }

    public function test_populate_triggers_a_warning_for_an_unknown_script_basename(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/somethingelse.php';
        $_SERVER['PATH_INFO'] = '/';

        $caughtMessage = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caughtMessage): bool {
            $caughtMessage = $errstr;
            return true;
        }, E_USER_WARNING);
        try {
            $this->makePopulator()->populate();
        } finally {
            restore_error_handler();
        }

        self::assertSame('script_basename "somethingelse" unknown', $caughtMessage);
        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('categories', $ctx->section);
    }

    public function test_populate_clears_an_incompatible_session_image_order(): void
    {
        // Order index 11 ("Permissions", 'level DESC') is only visible
        // when AccessControl::isAdmin() -- false for regular_user, so
        // getPreferredImageOrders()[11][2] is false: incompatible. (Index
        // 10 is "Visits, low -> high", always visible -- confirmed live,
        // that index alone doesn't exercise the incompatible-clear branch.)
        $_SESSION['pwg_image_order'] = 11;
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1';

        $this->makePopulator()->populate();

        self::assertArrayNotHasKey('pwg_image_order', $_SESSION);
        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        // superOrderBy reflects isset($page['super_order_by']), not its
        // boolean value -- confirmed live, the incompatible branch still
        // explicitly sets it (to false) rather than leaving it unset, so
        // isset() is true here too.
        self::assertTrue($ctx->superOrderBy);
    }

    public function test_populate_builds_a_combined_categories_context_and_merges_their_image_ids(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1/2';

        $this->makePopulator()->populate();

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

    public function test_populate_applies_the_categorys_own_custom_image_order(): void
    {
        $this->conn->executeStatement("UPDATE piwigo_categories SET image_order = 'name ASC' WHERE id = 1");
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/category/1';

        try {
            $this->makePopulator()->populate();
        } finally {
            $this->conn->executeStatement('UPDATE piwigo_categories SET image_order = NULL WHERE id = 1');
        }

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertCount(3, $ctx->items);
    }

    public function test_populate_denies_access_when_a_tag_has_zero_linked_images(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO piwigo_tags (id, name, url_name, lastmodified) VALUES (4, 'empty-tag', 'empty-tag', NOW())"
        );
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/tags/4';

        try {
            $this->makePopulator()->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            // regular_user is a real, non-guest CurrentUser -- accessDenied()
            // throws its own 401 HTML page directly rather than redirecting.
            self::assertSame(401, $e->response()->getStatusCode());
        } finally {
            $this->conn->executeStatement('DELETE FROM piwigo_tags WHERE id = 4');
        }
    }

    public function test_populate_stores_qsearch_details_for_a_quick_search(): void
    {
        $searchRepo = new SearchRepository($this->conn);
        $searchId = $searchRepo->insertSearch(['q' => 'nature'], '2026-07-12 00:00:00', 3, 'psk-20260712-abcdefghij', null);
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/search/' . $searchId;

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('search', $ctx->section);
        // getQuickSearchResultsNoCache()'s own 'qs' shape always adds
        // matching_tags/matching_cats/unmatched_terms alongside 'q' --
        // confirmed live, not just the bare ['q' => ...] this test
        // originally assumed.
        self::assertSame([
            'q' => 'nature',
            'unmatched_terms' => [],
            'matching_tags' => [
                ['id' => 1, 'name' => 'nature', 'url_name' => 'nature', 'lastmodified' => '2026-08-01 00:00:00'],
            ],
            'matching_cats' => [],
        ], $ctx->qsearchDetails);
        // 'nature' matches images 1, 2, 3 (piwigo_image_tag fixture rows).
        self::assertCount(3, $ctx->items);
    }

    public function test_populate_lists_favorites_and_assigns_the_remove_all_template_var(): void
    {
        $this->setAdminUser();
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/favorites';

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('favorites', $ctx->section);
        // user 1's own 3 favorited images (1, 3, 5).
        self::assertCount(3, $ctx->items);
        $favoriteVar = CurrentTemplate::current()->get()->get_template_vars('favorite');
        self::assertIsArray($favoriteVar);
        self::assertArrayHasKey('U_FAVORITE', $favoriteVar);
    }

    public function test_populate_deletes_all_favorites_and_redirects(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO piwigo_favorites (user_id, image_id) VALUES (3, 2)'
        );
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/favorites';
        $_GET['action'] = 'remove_all_from_favorites';

        try {
            $this->makePopulator()->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(302, $response->getStatusCode());
            // makeIndexUrl() builds a path-style URL by default in this
            // environment ('index.php/favorites'), not a raw
            // 'section=favorites' query string.
            self::assertStringContainsString('index.php/favorites', $response->getHeaderLine('Location'));
        }

        $remaining = $this->conn->fetchOne('SELECT COUNT(*) FROM piwigo_favorites WHERE user_id = 3');
        self::assertSame(0, is_numeric($remaining) ? (int) $remaining : -1);
    }

    public function test_populate_builds_the_recent_pics_section(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/recent_pics';

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('recent_pics', $ctx->section);
        self::assertStringContainsString('Recent photos', strip_tags($ctx->title));
    }

    public function test_populate_builds_the_most_visited_section(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/most_visited';

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('most_visited', $ctx->section);
        self::assertTrue($ctx->superOrderBy);
        self::assertStringContainsString('Most visited', strip_tags($ctx->title));
    }

    public function test_populate_builds_the_best_rated_section(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/best_rated';

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('best_rated', $ctx->section);
        self::assertTrue($ctx->superOrderBy);
        self::assertStringContainsString('Best rated', strip_tags($ctx->title));
    }

    public function test_populate_builds_the_list_section(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        $_SERVER['PATH_INFO'] = '/list/1,2,3';

        $this->makePopulator()->populate();

        $ctx = $this->sectionContextRegistry->current();
        self::assertNotNull($ctx);
        self::assertSame('list', $ctx->section);
        // parseWellKnownParamsUrl()'s own list-token parsing explode()s
        // the raw URL segment -- these stay strings, never cast to int.
        self::assertSame(['1', '2', '3'], $ctx->list);
        self::assertCount(3, $ctx->items);
        self::assertStringContainsString('Random photos', strip_tags($ctx->title));
    }

    public function test_populate_redirects_permanently_on_a_permalink_mismatch(): void
    {
        CurrentConfig::setCategoryUrlStyle('id-name');
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/index.php';
        // Category 1 has no real permalink row -- categoryUrlStyle
        // 'id-name' + a URL name that doesn't match str2url('Sample Album')
        // ('sample_album' -- str2url() replaces spaces with underscores,
        // not hyphens, confirmed live) triggers needsPermalinkRedirect().
        $_SERVER['PATH_INFO'] = '/category/1-wrong-name';

        try {
            $this->makePopulator()->populate();
            self::fail('populate() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            // Real, adversarially-confirmed bug fixed here: this branch's
            // own "this is a permanent redirection" comment and its
            // setStatusHeader(301) call were both already correct intent,
            // but redirectHttp() had no way to carry that status into the
            // Response object it throws -- ResponseFactory::redirect()'s
            // own 302 default silently won every time, regardless of the
            // real header() call already sent. redirectHttp()/
            // RedirectServiceInterface now take an explicit $status.
            self::assertSame(301, $response->getStatusCode());
            // Also a real, adversarially-confirmed bug: duplicateIndexUrl()
            // reads the current section's params from SectionContextRegistry,
            // which populate() only otherwise populates at its very end --
            // too late for this early-exit redirect, so the rebuilt URL
            // silently lost its category entirely (bare root path). Fixed
            // by registering the context (already-safe defensive fallbacks
            // for every not-yet-computed field) right before this redirect.
            self::assertStringContainsString('category/1-sample_album', $response->getHeaderLine('Location'));
        }
    }
}
