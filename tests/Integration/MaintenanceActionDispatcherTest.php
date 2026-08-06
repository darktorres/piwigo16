<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

use Piwigo\Image\ImageService;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Activity\ActivityService;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Rate\RateService;
use Piwigo\Auth\AccessControl;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Rate\RateEntity;
use Piwigo\Auth\CookieService;
use Piwigo\Users\UserService;
use Piwigo\Mail\MailService;
use LogicException;
use Piwigo\Users\UserRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\ProcessCache;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\FilterState;
use Piwigo\Category\CategoryService;
use Piwigo\Tag\TagService;
use Piwigo\Users\User;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserStatus;
use Piwigo\Tag\TagEntity;
use Piwigo\Core\CurrentLogger;
use Override;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Core\PageState;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Db\DbCredentials;
use Piwigo\Validation\InputValidator;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Cache\PersistentFileCache;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Lang\Translator;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;

/**
 * MaintenanceActionDispatcher::dispatch() -- the consolidated action
 * switch built during P23 batch 6h to replace the two independently-drifted
 * copies previously in admin/maintenance_actions.php and
 * admin/maintenance_env.php (see that class's own docblock for the 2 real
 * drift bugs found and fixed).
 *
 * Scoped to the actions reachable via pure Doctrine DBAL (already tested
 * at the repository layer by DbMaintenanceRepositoryTest -- this suite
 * covers the dispatcher's own routing, $page['infos'] messaging, and
 * pwg_activity() logging, not re-testing the repository's own SQL).
 * `empty_lounge`/`sessions`/`categories`/`images`/`database`/`c13y` all
 * need a legacy `$mysqli` (or `$logger`) global bootstrap this suite
 * doesn't set up -- no existing Integration test in this codebase does
 * either, so those are verified live instead (see the batch's own
 * completion notes for the curl round trip).
 */
final class MaintenanceActionDispatcherTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private MaintenanceActionDispatcher $dispatcher;

    /**
     * Never actually invoked -- this suite's own docblock already documents
     * that the `categories`/`images` dispatch cases (the only 2 that reach
     * FilesystemIntegrityChecker::imagesIntegrity(), and by extension this
     * ImageService) need a legacy $mysqli bootstrap this suite doesn't set
     * up, so they're verified live instead. A type-satisfying instance is
     * enough here, same lazy-DBAL-repository reasoning as this file's own
     * other throwaway constructor args.
     */
    private function maintenanceActionDispatcherTestImageService(): ImageService
    {
        return new ImageService(
            Lang::current(),
            EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class),
            $this->maintenanceActionDispatcherTestActivityService(),
            new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class),CurrentConfig::current()),
            new EventDispatcher(),
            CurrentConfig::current(),
            Translator::get(),
            CurrentPaths::get(),
        );
    }

    /**
     * Never actually invoked -- same reasoning as
     * maintenanceActionDispatcherTestImageService(): the dispatch cases that
     * reach it (`lock_gallery`/`unlock_gallery`/`categories`/`images` and
     * the final `record()` call) are all verified live, not via this suite.
     */
    private function maintenanceActionDispatcherTestActivityService(): ActivityService
    {
        return new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class));
    }

    /**
     * Never actually invoked -- same reasoning as
     * maintenanceActionDispatcherTestImageService() (only the `images` case
     * reaches it, verified live).
     */
    private function maintenanceActionDispatcherTestRateService(): RateService
    {
        return new RateService(
            new AccessControl(
                new AccessControlTestFakeHtmlRendererDeniesAccess(),
                new AccessControlTestFakeRedirectServiceNeverCalled(),
                new CurrentUser(new CurrentConfig()),
                new CurrentConfig(),
            ),
            EntityManagerFactory::build(DbConnection::build())->getRepository(RateEntity::class),
            new CookieService(),
            new EventDispatcher(),
            new CurrentUser(new CurrentConfig()),
            new CurrentConfig(),
        );
    }

    /**
     * Never actually invoked -- same reasoning as
     * maintenanceActionDispatcherTestImageService(). Singleton/
     * service-locator elimination campaign, Phase 6: RedirectService now
     * takes UserService via constructor injection.
     */
    private function maintenanceActionDispatcherTestUserService(): UserService
    {
        $conn = DbConnection::build();
        $mailer = Kernel::container()->get(MailService::class);
        if (! $mailer instanceof MailService) {
            throw new LogicException('Container returned an unexpected type for ' . MailService::class);
        }

        return new UserService(
            Lang::current(),
            new UserRepository(EntityManagerFactory::build($conn), EventDispatcher::get(), CurrentConfig::current()),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            $mailer,
            $this->maintenanceActionDispatcherTestActivityService(),
            HtmlServiceTestFactory::build(),
            $conn,
            new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class),CurrentConfig::current()),
            new EventDispatcher(),
            new DeploymentPolicy(),
            new CurrentUser(new CurrentConfig()),
            new CurrentConfig(),
            new InstallationFlag(),
            new ProcessCache(),
            CurrentPaths::get(),
        );
    }

    private function maintenanceActionDispatcherTestPermissionService(): PermissionService
    {
        $conn = DbConnection::build();

        return new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfig::current()),
            CurrentUser::current(),
            new FilterState(),
        );
    }

    /**
     * Never actually invoked -- same reasoning as
     * maintenanceActionDispatcherTestImageService() (only the
     * `categories`/`images` cases reach it, verified live).
     */
    private function maintenanceActionDispatcherTestCategoryService(): CategoryService
    {
        return new CategoryService(
            Lang::current(),
            new CategoryRepository(EntityManagerFactory::build(DbConnection::build()), CurrentConfig::current()),
            $this->maintenanceActionDispatcherTestPermissionService(),
            CurrentConfig::current(),
            new EventDispatcher(),
            Translator::get(),
        );
    }

    /**
     * `delete_orphan_tags` really does reach this (test_delete_orphan_tags_
     * removes_a_tag_with_no_image_links() below), unlike this file's other
     * "never actually invoked" throwaways -- TagService::deleteTags() reads
     * (then rewrites) $this->currentUser->get(), so the CurrentUser passed
     * here must be pre-seeded with a real User, not a bare, never-set
     * instance (caught live: a bare `new CurrentUser()` throws "CurrentUser
     * not initialised" the first time deleteTags() runs).
     */
    private function maintenanceActionDispatcherTestTagService(): TagService
    {
        $currentUser = new CurrentUser(new CurrentConfig());
        $currentUser->set(new User(
            id: UserId::from(2),
            username: 'maintenance-action-dispatcher-test-tag-service-user',
            email: '',
            language: '',
            theme: '',
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        return new TagService(
            Lang::current(),
            EntityManagerFactory::build(DbConnection::build())->getRepository(TagEntity::class),
            $this->maintenanceActionDispatcherTestPermissionService(),
            $this->maintenanceActionDispatcherTestActivityService(),
            new EventDispatcher(),
            $currentUser,
            CurrentConfig::current(),
            new CurrentLogger(),
            new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfig::current()),
        );
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

        // DbMaintenanceRepository is now constructor-injected directly (no
        // more Bootstrap\AdminAccessor indirection), but this isolated
        // Integration test (no full RequestBootstrap) still needs a booted
        // Kernel for CurrentConfigService/CurrentTemplate/DbCredentials::
        // current() below.
        Kernel::boot();
        // Every Lang::t() assertion in this file expects the real en_UK
        // admin.po wording (which sometimes differs from the raw English
        // literal passed to Lang::t() -- e.g. "Update albums informations"
        // vs the po's "Update albums' information"). Without this,
        // whether admin.lang happens to already be loaded depends on
        // which other Integration test file ran earlier in this shared
        // process -- confirmed live. Loading it explicitly here makes
        // every assertion deterministic regardless of run order.
        Lang::current()->load('admin.lang');

        $this->conn = DbConnection::build();
        CurrentConfigService::current()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current()));
        $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current());
        $this->dispatcher = new MaintenanceActionDispatcher(new RedirectService(Lang::current(), $this->maintenanceActionDispatcherTestUserService()), UrlServiceTestFactory::build(), $configService, new FilesystemIntegrityChecker(Lang::current(), CurrentTemplate::current(), CurrentConfigService::current(), $this->maintenanceActionDispatcherTestImageService(), CurrentConfig::current(), CurrentPaths::get()), new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class),CurrentConfig::current()), new Translator(CurrentConfig::current()), new EventDispatcher(), PageState::current(), CurrentTemplate::current(), new DbMaintenanceRepository(EntityManagerFactory::build($this->conn), DbCredentials::current()), $this->maintenanceActionDispatcherTestActivityService(), $this->maintenanceActionDispatcherTestRateService(), $this->maintenanceActionDispatcherTestCategoryService(), $this->maintenanceActionDispatcherTestTagService(), HtmlServiceTestFactory::build(), Lang::current(), CurrentConfig::current(), new InputValidator(), CurrentPaths::get());
    }

    #[Override]
    protected function tearDown(): void
    {
        // Harmless for every other test in this file, which never sets it
        // -- same nullable-safe reset() shape as CurrentConfigService's own
        // base-class reset (see IntegrationTestCase's own docblock).
        CurrentTemplate::current()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    public function test_search_purges_history_and_assigns_the_real_info_message(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::search())
            ->values(['created_by' => ':createdBy'])
            ->setParameter('createdBy', 1)
            ->executeStatement();

        $this->dispatcher->dispatch('search');

        self::assertSame(0, $this->countRows(Tables::search()));
        // Regression guard for the pre-existing, already-fixed bug this
        // batch carried forward unchanged (see MaintenanceSubController's
        // own docblock): this message must be "Purge search history", not
        // a copy-paste of the 'c13y' case's "Reinitialize check integrity".
        self::assertContains(
            'Purge search history : action successfully performed.',
            PageState::current()->infos
        );
    }

    public function test_history_detail_and_summary_both_purge_via_one_dispatch_each(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::history())
            ->values(['user_id' => ':userId'])
            ->setParameter('userId', 1)
            ->executeStatement();
        $this->conn->createQueryBuilder()
            ->insert(Tables::historySummary())
            ->values(['year' => ':year'])
            ->setParameter('year', 2026)
            ->executeStatement();

        $this->dispatcher->dispatch('history_detail');
        $this->dispatcher->dispatch('history_summary');

        self::assertSame(0, $this->countRows(Tables::history()));
        self::assertSame(0, $this->countRows(Tables::historySummary()));
    }

    public function test_a_real_action_is_logged_to_pwg_activity(): void
    {
        // Drift bug #1 fixed by this batch's consolidation: the original
        // admin/maintenance_env.php's own copy of this switch never called
        // pwg_activity() for any action -- the dispatcher always does now,
        // regardless of which tab reaches it.
        $before = $this->countRows(Tables::activity());

        $this->dispatcher->dispatch('search');

        self::assertSame($before + 1, $this->countRows(Tables::activity()));
        $lastAction = $this->conn->createQueryBuilder()
            ->select('action', 'object_id')
            ->from(Tables::activity())
            ->orderBy('activity_id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($lastAction);
        self::assertSame('maintenance', $lastAction['action']);
    }

    public function test_an_unregistered_action_is_not_logged_to_pwg_activity(): void
    {
        $before = $this->countRows(Tables::activity());

        $this->dispatcher->dispatch('this_action_does_not_exist');

        self::assertSame($before, $this->countRows(Tables::activity()));
    }

    private function countRows(string $table): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    // 'phpinfo' isn't covered here: its own body is a plain `echo ... ;
    // exit();` -- a real, uncatchable process termination (unlike
    // RedirectServiceInterface::redirect()/HtmlRenderingInterface::
    // fatalError(), both `never`-typed but implemented via a catchable
    // ResponseReadyException, see the 2 tests below). Calling it directly
    // here would kill this entire shared PHPUnit/Pest process mid-suite,
    // exactly the same hazard ExtensionLifecycleTest's own die()-guard
    // documents. ServerInfoService::renderHtml() itself (the real
    // curated-output logic) is already Unit-tested directly in
    // ServerInfoServiceTest.php. This dispatcher's own call site (the
    // `echo`/`exit()` lines themselves) IS exercised, over a real HTTP
    // request against the live Apache/php-fpm process instead -- a
    // completely separate process from this shared CLI one, where exit()
    // just ends that one request normally -- see
    // tests/Browser/MaintenanceEnvPageRendererTest.php's own
    // "runs the real phpinfo maintenance action..." test, and
    // Piwigo\Core\CoverageCollector's own docblock for how that Browser
    // request's coverage gets measured at all (composer test:coverage:web).

    public function test_lock_gallery_persists_gallery_locked_and_redirects(): void
    {
        try {
            $this->dispatcher->dispatch('lock_gallery');
            self::fail('Expected RedirectServiceInterface::redirect() to throw ResponseReadyException');
        } catch (ResponseReadyException) {
            // redirect() is `never`-typed -- this catchable exception is
            // its real non-exit() implementation, see that class's own
            // docblock.
        }

        $raw = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'gallery_locked'");
        self::assertSame(true, json_decode(is_scalar($raw) ? (string) $raw : ''));

        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'gallery_locked'");
    }

    public function test_unlock_gallery_persists_gallery_unlocked_and_redirects(): void
    {
        try {
            $this->dispatcher->dispatch('unlock_gallery');
            self::fail('Expected RedirectServiceInterface::redirect() to throw ResponseReadyException');
        } catch (ResponseReadyException) {
        }

        $raw = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'gallery_locked'");
        self::assertSame(false, json_decode(is_scalar($raw) ? (string) $raw : ''));
        // $_SESSION['page_infos'] here has no reader anywhere in src/Piwigo
        // (confirmed via full-repo grep) -- exercised for coverage credit
        // without asserting on a visible effect that doesn't exist.
        self::assertSame(['Gallery unlocked'], $_SESSION['page_infos'] ?? null);

        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'gallery_locked'");
        unset($_SESSION['page_infos']);
    }

    public function test_categories_updates_album_information_and_adds_the_success_note(): void
    {
        $this->dispatcher->dispatch('categories');

        self::assertContains(
            "Update albums' information : action successfully performed.",
            PageState::current()->infos
        );
    }

    public function test_images_updates_photo_information_and_adds_the_success_note(): void
    {
        $this->dispatcher->dispatch('images');

        self::assertContains(
            "Update photos' information : action successfully performed.",
            PageState::current()->infos
        );
    }

    public function test_delete_orphan_tags_removes_a_tag_with_no_image_links(): void
    {
        // findOrphanTags() only considers a tag orphaned once its
        // lastmodified is more than 1 day old (a grace period against
        // deleting a tag the instant it's created, before it's had a
        // chance to be associated with an image yet) -- confirmed live,
        // a freshly-inserted tag (lastmodified defaulting to NOW()) is
        // never picked up regardless of having no image_tag rows.
        $this->conn->createQueryBuilder()
            ->insert(Tables::tags())
            ->values(['name' => ':name', 'url_name' => ':url', 'lastmodified' => ':lastmodified'])
            ->setParameter('name', 'orphan-tag-' . uniqid())
            ->setParameter('url', 'orphan-tag-' . uniqid())
            ->setParameter('lastmodified', '2020-01-01 00:00:00')
            ->executeStatement();
        $before = $this->countRows(Tables::tags());

        $this->dispatcher->dispatch('delete_orphan_tags');

        self::assertLessThan($before, $this->countRows(Tables::tags()));
        self::assertContains(
            'Delete orphan tags : action successfully performed.',
            PageState::current()->infos
        );
    }

    public function test_user_cache_adds_the_success_note(): void
    {
        $this->dispatcher->dispatch('user_cache');

        self::assertContains(
            'Purge user cache : action successfully performed.',
            PageState::current()->infos
        );
    }

    public function test_sessions_purges_stale_sessions_and_adds_the_success_note(): void
    {
        $this->dispatcher->dispatch('sessions');

        self::assertContains(
            'Purge sessions : action successfully performed.',
            PageState::current()->infos
        );
    }

    public function test_feeds_purges_unused_feeds_and_adds_the_success_note(): void
    {
        $this->dispatcher->dispatch('feeds');

        self::assertContains(
            'Purge never used notification feeds : action successfully performed.',
            PageState::current()->infos
        );
    }

    public function test_database_repairs_and_optimizes_all_tables(): void
    {
        $this->dispatcher->dispatch('database');

        self::assertContains(
            'All optimizations have been successfully completed.',
            PageState::current()->infos
        );
    }

    public function test_c13y_delegates_to_check_integritys_maintenance(): void
    {
        $this->dispatcher->dispatch('c13y');

        self::assertContains(
            'Reinitialize integrity check : action successfully performed.',
            PageState::current()->infos
        );
    }

    public function test_empty_lounge_reports_the_number_of_photos_moved(): void
    {
        $this->dispatcher->dispatch('empty_lounge');

        // The fixture's lounge is empty, so emptyLounge() genuinely moves 0
        // photos -- this still exercises the real ImageService call and the
        // exact message format (count() of an empty/null result), distinct
        // from a real non-zero count this suite has no lounge fixture for.
        self::assertContains(
            '0 photos were moved from the upload lounge to their albums',
            PageState::current()->infos
        );
    }

    public function test_derivatives_with_type_all_clears_the_whole_cache(): void
    {
        $_GET['type'] = 'all';
        // DerivativeCacheService::clearDerivativeCache()'s own @opendir()
        // (the real derivative-image cache dir, _data/i/, may not exist
        // yet in this shared process) is already suppressed at the source
        // and handled gracefully (returns early on false) -- @ alone
        // doesn't stop PHPUnit's own ErrorHandler from surfacing it,
        // matching this file's own test_compiled_templates_fatal_errors_*
        // set_error_handler() precedent for the identical reason.
        set_error_handler(static fn (): bool => true);
        try {
            $this->dispatcher->dispatch('derivatives');
        } finally {
            restore_error_handler();
            unset($_GET['type']);
        }

        self::assertContains('action successfully performed.', PageState::current()->infos);
    }

    public function test_derivatives_with_underscore_joined_types_clears_each_one(): void
    {
        $_GET['type'] = 'thumb_2small';
        // Same real _data/i/-may-not-exist-yet reasoning as the test above.
        set_error_handler(static fn (): bool => true);
        try {
            $this->dispatcher->dispatch('derivatives');
        } finally {
            restore_error_handler();
            unset($_GET['type']);
        }

        self::assertContains('action successfully performed.', PageState::current()->infos);
    }

    public function test_compiled_templates_fatal_errors_when_the_persistent_cache_is_not_initialized(): void
    {
        // $this->dispatcher (built in setUp()) already has no persistent
        // cache -- its constructor's $persistentCache param defaults to
        // null, and setUp() never passes one.
        // delete_compiled_templates() runs before the persistent-cache
        // guard -- needs a real Template instance, unset by default since
        // this test never boots a full RequestBootstrap.
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPaths::get()->root . 'themes/admin', 'default'));

        // HtmlService::fatalError() always throws ResponseReadyException
        // regardless of ErrorCollector::isActive() (see its own docblock)
        // -- this handler is now just belt-and-suspenders against any
        // incidental warning along the way, not load-bearing for the
        // throw itself. Left local rather than a real
        // Piwigo\Core\ErrorCollector::install() (a real
        // set_error_handler()/register_shutdown_function() pair would
        // leak into every later test in this shared process, same
        // reasoning as TemplateDefineDerivativeTest's own identical local
        // handler).
        set_error_handler(static fn (): bool => true);
        try {
            $this->dispatcher->dispatch('compiled-templates');
            self::fail('Expected HtmlRenderingInterface::fatalError() to throw ResponseReadyException');
        } catch (ResponseReadyException) {
        } finally {
            restore_error_handler();
        }
    }

    public function test_check_upgrade_reports_a_real_error_when_the_forks_permanently_unreachable_domain_cannot_be_fetched(): void
    {
        // AppInfo::URL points at 'upstream.example.invalid' (RFC 2606 --
        // guaranteed to never resolve, see AppInfo::DOMAIN's own docblock),
        // so HttpClientService::fetch() fails fast and deterministically
        // here (a real DNS/transport failure caught by guardedFetch()'s own
        // ClientExceptionInterface catch, not a flaky live piwigo.org round
        // trip) -- the same "fork-safe domain never resolves" property this
        // project's own HttpClientServiceTest.php / ExtensionUpdateCheckerTest.php /
        // CoreUpdateServiceTest.php / IntroSubControllerGetLatestNewsTest.php
        // already rely on for the identical class of "talks to piwigo.org
        // via the static, non-injectable HttpClientService::fetch()" code.
        // Real exercise of this dispatcher's own `$result === false`
        // branch, not a mock of HttpClientService or of
        // MaintenanceActionDispatcher itself.
        $this->dispatcher->dispatch('check_upgrade');

        self::assertContains('Unable to check for upgrade.', PageState::current()->errors);
    }

    // The entire `else` branch of the 'check_upgrade' case above (the
    // real-fetch-succeeded path: BSF-vs-stable version-line selection,
    // BSF date.time splitting, and the final version_compare()-driven
    // info/error message) has NO reachable path in this fork at all, for
    // the exact same reason the test above exercises the `$result ===
    // false` branch instead of the success branch: HttpClientService::fetch()
    // is a bare static call with no injectable client seam (confirmed via
    // HttpClientServiceTest.php's own "guardedFetch() ... deliberately NOT
    // chased here" note -- every caller of fetch()/fetchToFile() always
    // constructs `new self(...)` internally against the hardcoded real
    // defaultClient(), with no parameter anywhere in the static call chain
    // to substitute a MockHttpClient), and the one real network target this
    // dispatcher can ever reach (AppInfo::URL) is deliberately, permanently
    // non-resolving (see AppInfo::DOMAIN's own docblock -- this fork
    // intentionally never calls back to the real piwigo.org). Left
    // uncovered rather than faked, same choice this project's own
    // IntroSubControllerGetLatestNewsTest.php/ExtensionUpdateCheckerTest.php/
    // CoreUpdateServiceTest.php already document for the identical class of
    // code.

    public function test_compiled_templates_purges_a_real_initialized_persistent_cache(): void
    {
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPaths::get()->root . 'themes/admin', 'default'));

        // A dedicated local instance, not $this->dispatcher (built in
        // setUp() with no persistent cache) -- constructor injection means
        // this test just passes the fixture it wants directly, no static
        // set()/reset() ceremony needed.
        $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current());
        $dispatcher = new MaintenanceActionDispatcher(
            new RedirectService(Lang::current(), $this->maintenanceActionDispatcherTestUserService()),
            UrlServiceTestFactory::build(),
            $configService,
            new FilesystemIntegrityChecker(Lang::current(), CurrentTemplate::current(), CurrentConfigService::current(), $this->maintenanceActionDispatcherTestImageService(), CurrentConfig::current(), CurrentPaths::get()),
            new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class),CurrentConfig::current()),
            new Translator(CurrentConfig::current()),
            new EventDispatcher(),
            PageState::current(),
            CurrentTemplate::current(),
            new DbMaintenanceRepository(EntityManagerFactory::build($this->conn), DbCredentials::current()),
            $this->maintenanceActionDispatcherTestActivityService(),
            $this->maintenanceActionDispatcherTestRateService(),
            $this->maintenanceActionDispatcherTestCategoryService(),
            $this->maintenanceActionDispatcherTestTagService(),
            HtmlServiceTestFactory::build(),
            Lang::current(),
            CurrentConfig::current(),
            new InputValidator(),
            CurrentPaths::get(),
            new PersistentFileCache(CurrentConfig::current(), CurrentPaths::get()),
        );

        // FileCombiner::clear_combined_files()'s own opendir() (the real
        // combined-CSS/JS cache dir, _data/combined/, may not exist yet in
        // this shared process -- another test may have just deleted it) is
        // unsuppressed but already handled gracefully in production
        // (returns early on false); PHPUnit's own ErrorHandler still
        // surfaces the warning, matching this file's own
        // test_derivatives_* tests' identical reasoning above.
        set_error_handler(static fn (): bool => true);
        try {
            $dispatcher->dispatch('compiled-templates');

            self::assertContains(
                'Purge compiled templates : action successfully performed.',
                PageState::current()->infos
            );
        } finally {
            restore_error_handler();
        }
    }
}

}
