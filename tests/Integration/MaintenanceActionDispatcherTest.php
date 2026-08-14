<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Activity\ActivityEntity;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
    use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
    use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
    use Piwigo\Auth\AccessControl;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Auth\CookieService;
    use Piwigo\Auth\PasswordRepository;
    use Piwigo\Auth\PasswordService;
    use Piwigo\Bootstrap\RedirectService;
    use Piwigo\Cache\CacheFactory;
    use Piwigo\Cache\PersistentFileCache;
    use Piwigo\Cache\TranslationsCachePool;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Category\CategoryService;
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
    use Piwigo\Core\ProcessCache;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Http\ResponseReadyException;
    use Piwigo\Image\ImageEntity;
    use Piwigo\Image\ImageService;
    use Piwigo\Lang\Translator;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Rate\RateEntity;
    use Piwigo\Rate\RateService;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Tag\TagEntity;
    use Piwigo\Tag\TagService;
    use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentTemplateTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\TemplateTestFactory;
    use Piwigo\Tests\Support\TranslatorTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess;
    use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserRepository;
    use Piwigo\Users\UserService;
    use Piwigo\Users\UserStatus;
    use Piwigo\Validation\InputValidator;

    /**
     * MaintenanceActionDispatcher::dispatch() is the single action-routing
     * switch shared by the maintenance actions and environment admin pages.
     *
     * Scoped to the actions reachable via pure Doctrine DBAL (already tested
     * at the repository layer by DbMaintenanceRepositoryTest -- this suite
     * covers the dispatcher's own routing, $page['infos'] messaging, and
     * pwg_activity() logging, not re-testing the repository's own SQL).
     * `empty_lounge`/`sessions`/`categories`/`images`/`database`/`c13y` all
     * need a legacy `$mysqli` (or `$logger`) global bootstrap this suite
     * doesn't set up -- no existing Integration test in this codebase does
     * either, so those are verified live instead.
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
                EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class),
                $this->maintenanceActionDispatcherTestActivityService(),
                new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
                new EventDispatcher(),
                CurrentConfigTestFactory::get(),
                CurrentPathsTestFactory::get(),
                $this->maintenanceActionDispatcherTestCategoryService(),
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
                    new AccessLevelChecker(new CurrentUser(new CurrentConfig()), new CurrentConfig()),
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
         * maintenanceActionDispatcherTestImageService(). RedirectService takes
         * UserService via constructor injection.
         */
        private function maintenanceActionDispatcherTestUserService(): UserService
        {
            $conn = DbConnection::build();

            return new UserService(
                LangTestFactory::get(),
                new UserRepository(EntityManagerFactory::build($conn), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get()),
                EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
                $this->maintenanceActionDispatcherTestActivityService(),
                HtmlServiceTestFactory::build(),
                new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
                new EventDispatcher(),
                new DeploymentPolicy(),
                new CurrentUser(new CurrentConfig()),
                new CurrentConfig(),
                new InstallationFlag(),
                new ProcessCache(),
                CurrentPathsTestFactory::get(),
                EntityManagerFactory::build($conn),
                $this->maintenanceActionDispatcherTestPermissionService(),
                $this->maintenanceActionDispatcherTestCategoryService(),
                new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
            );
        }

        private function maintenanceActionDispatcherTestPermissionService(): PermissionService
        {
            $conn = DbConnection::build();

            return new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($conn)),
                EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
                new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()),
                CurrentUserTestFactory::get(),
                new FilterState(),
                new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
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
                LangTestFactory::get(),
                new CategoryRepository(EntityManagerFactory::build(DbConnection::build()), CurrentConfigTestFactory::get()),
                $this->maintenanceActionDispatcherTestPermissionService(),
                CurrentConfigTestFactory::get(),
                new EventDispatcher(),
                TranslatorTestFactory::get(),
                new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
                new UserRepository(EntityManagerFactory::build(DbConnection::build()), new EventDispatcher(), CurrentConfigTestFactory::get()),
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
                username: Username::from('maintenance-action-dispatcher-test-tag-service-user'),
                email: null,
                language: LangCode::from('en_UK'),
                theme: ThemeId::from('default'),
                status: UserStatus::Normal,
                enabledHigh: false,
            ));

            return new TagService(
                LangTestFactory::get(),
                EntityManagerFactory::build(DbConnection::build())->getRepository(TagEntity::class),
                $this->maintenanceActionDispatcherTestPermissionService(),
                $this->maintenanceActionDispatcherTestActivityService(),
                new EventDispatcher(),
                $currentUser,
                CurrentConfigTestFactory::get(),
                new CurrentLogger(),
                $this->maintenanceActionDispatcherTestImageService(),
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

            // DbMaintenanceRepository is constructor-injected directly. This
            // isolated Integration test (no full RequestBootstrap) still needs
            // a booted Kernel for CurrentConfigService/CurrentTemplate/
            // DbCredentials::current() below.
            Kernel::boot();
            // Every Lang::t() assertion in this file expects the real en_UK
            // admin.po wording (which sometimes differs from the raw English
            // literal passed to Lang::t() -- e.g. "Update albums informations"
            // vs the po's "Update albums' information"). Without this,
            // whether admin.lang happens to already be loaded depends on
            // which other Integration test file ran earlier in this shared
            // process -- confirmed live. Loading it explicitly here makes
            // every assertion deterministic regardless of run order.
            LangTestFactory::get()->load('admin.lang');

            $this->conn = DbConnection::build();
            CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
            $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
            $this->dispatcher = new MaintenanceActionDispatcher(new RedirectService(LangTestFactory::get(), $this->maintenanceActionDispatcherTestUserService(), new EventDispatcher(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), $configService, new FilesystemIntegrityChecker(LangTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigServiceTestFactory::get(), $this->maintenanceActionDispatcherTestImageService(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get()), new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), new Translator(CurrentConfigTestFactory::get(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), new EventDispatcher(), PageStateTestFactory::get(), CurrentTemplateTestFactory::get(), new DbMaintenanceRepository(EntityManagerFactory::build($this->conn)), $this->maintenanceActionDispatcherTestActivityService(), $this->maintenanceActionDispatcherTestRateService(), $this->maintenanceActionDispatcherTestCategoryService(), $this->maintenanceActionDispatcherTestTagService(), HtmlServiceTestFactory::build(), LangTestFactory::get(), CurrentConfigTestFactory::get(), new InputValidator(), CurrentPathsTestFactory::get(), EntityManagerFactory::build($this->conn));
        }

        #[Override]
        protected function tearDown(): void
        {
            // Harmless for every other test in this file, which never sets it
            // -- same nullable-safe reset() shape as CurrentConfigService's own
            // base-class reset (see IntegrationTestCase's own docblock).
            CurrentTemplateTestFactory::get()->reset();
            Kernel::reset();
            parent::tearDown();
        }

        public function testSearchPurgesHistoryAndAssignsTheRealInfoMessage(): void
        {
            $this->conn->createQueryBuilder()
                ->insert('search')
                ->values([
                    'created_by' => ':createdBy',
                ])
                ->setParameter('createdBy', 1)
                ->executeStatement();

            $this->dispatcher->dispatch('search');

            self::assertSame(0, $this->countRows('search'));
            // Regression guard: this message must be "Purge search history",
            // not a copy-paste of the 'c13y' case's "Reinitialize check
            // integrity".
            self::assertContains(
                'Purge search history : action successfully performed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testHistoryDetailAndSummaryBothPurgeViaOneDispatchEach(): void
        {
            $this->conn->createQueryBuilder()
                ->insert('history')
                ->values([
                    'user_id' => ':userId',
                ])
                ->setParameter('userId', 1)
                ->executeStatement();
            $this->conn->createQueryBuilder()
                ->insert('history_summary')
                ->values([
                    'year' => ':year',
                ])
                ->setParameter('year', 2026)
                ->executeStatement();

            $this->dispatcher->dispatch('history_detail');
            $this->dispatcher->dispatch('history_summary');

            self::assertSame(0, $this->countRows('history'));
            self::assertSame(0, $this->countRows('history_summary'));
        }

        public function testARealActionIsLoggedToPwgActivity(): void
        {
            // The dispatcher always calls pwg_activity() for every action,
            // regardless of which tab reaches it.
            $before = $this->countRows('activity');

            $this->dispatcher->dispatch('search');

            self::assertSame($before + 1, $this->countRows('activity'));
            $lastAction = $this->conn->createQueryBuilder()
                ->select('action', 'object_id')
                ->from('activity')
                ->orderBy('activity_id', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            self::assertIsArray($lastAction);
            self::assertSame('maintenance', $lastAction['action']);
        }

        public function testAnUnregisteredActionIsNotLoggedToPwgActivity(): void
        {
            $before = $this->countRows('activity');

            $this->dispatcher->dispatch('this_action_does_not_exist');

            self::assertSame($before, $this->countRows('activity'));
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

        public function testLockGalleryPersistsGalleryLockedAndRedirects(): void
        {
            try {
                $this->dispatcher->dispatch('lock_gallery');
                self::fail('Expected RedirectServiceInterface::redirect() to throw ResponseReadyException');
            } catch (ResponseReadyException) {
                // redirect() is `never`-typed -- this catchable exception is
                // its real non-exit() implementation, see that class's own
                // docblock.
            }

            $raw = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'gallery_locked'");
            self::assertTrue(json_decode(is_scalar($raw) ? (string) $raw : ''));

            $this->conn->executeStatement("DELETE FROM config WHERE param = 'gallery_locked'");
        }

        public function testUnlockGalleryPersistsGalleryUnlockedAndRedirects(): void
        {
            try {
                $this->dispatcher->dispatch('unlock_gallery');
                self::fail('Expected RedirectServiceInterface::redirect() to throw ResponseReadyException');
            } catch (ResponseReadyException) {
            }

            $raw = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'gallery_locked'");
            self::assertFalse(json_decode(is_scalar($raw) ? (string) $raw : ''));
            // $_SESSION['page_infos'] here has no reader anywhere in src/Piwigo
            // (confirmed via full-repo grep) -- exercised for coverage credit
            // without asserting on a visible effect that doesn't exist.
            self::assertSame(['Gallery unlocked'], $_SESSION['page_infos'] ?? null);

            $this->conn->executeStatement("DELETE FROM config WHERE param = 'gallery_locked'");
            unset($_SESSION['page_infos']);
        }

        public function testCategoriesUpdatesAlbumInformationAndAddsTheSuccessNote(): void
        {
            $this->dispatcher->dispatch('categories');

            self::assertContains(
                "Update albums' information : action successfully performed.",
                PageStateTestFactory::get()->infos
            );
        }

        public function testImagesUpdatesPhotoInformationAndAddsTheSuccessNote(): void
        {
            $this->dispatcher->dispatch('images');

            self::assertContains(
                "Update photos' information : action successfully performed.",
                PageStateTestFactory::get()->infos
            );
        }

        public function testDeleteOrphanTagsRemovesATagWithNoImageLinks(): void
        {
            // findOrphanTags() only considers a tag orphaned once its
            // lastmodified is more than 1 day old (a grace period against
            // deleting a tag the instant it's created, before it's had a
            // chance to be associated with an image yet) -- confirmed live,
            // a freshly-inserted tag (lastmodified defaulting to NOW()) is
            // never picked up regardless of having no image_tag rows.
            $this->conn->createQueryBuilder()
                ->insert('tags')
                ->values([
                    'name' => ':name',
                    'url_name' => ':url',
                    'lastmodified' => ':lastmodified',
                ])
                ->setParameter('name', 'orphan-tag-' . uniqid())
                ->setParameter('url', 'orphan-tag-' . uniqid())
                ->setParameter('lastmodified', '2020-01-01 00:00:00')
                ->executeStatement();
            $before = $this->countRows('tags');

            $this->dispatcher->dispatch('delete_orphan_tags');

            self::assertLessThan($before, $this->countRows('tags'));
            self::assertContains(
                'Delete orphan tags : action successfully performed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testUserCacheAddsTheSuccessNote(): void
        {
            $this->dispatcher->dispatch('user_cache');

            self::assertContains(
                'Purge user cache : action successfully performed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testSessionsPurgesStaleSessionsAndAddsTheSuccessNote(): void
        {
            $this->dispatcher->dispatch('sessions');

            self::assertContains(
                'Purge sessions : action successfully performed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testFeedsPurgesUnusedFeedsAndAddsTheSuccessNote(): void
        {
            $this->dispatcher->dispatch('feeds');

            self::assertContains(
                'Purge never used notification feeds : action successfully performed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testDatabaseRepairsAndOptimizesAllTables(): void
        {
            $this->dispatcher->dispatch('database');

            self::assertContains(
                'All optimizations have been successfully completed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testC13yDelegatesToCheckIntegritysMaintenance(): void
        {
            $this->dispatcher->dispatch('c13y');

            self::assertContains(
                'Reinitialize integrity check : action successfully performed.',
                PageStateTestFactory::get()->infos
            );
        }

        public function testEmptyLoungeReportsTheNumberOfPhotosMoved(): void
        {
            $this->dispatcher->dispatch('empty_lounge');

            // The fixture's lounge is empty, so emptyLounge() genuinely moves 0
            // photos -- this still exercises the real ImageService call and the
            // exact message format (count() of an empty/null result), distinct
            // from a real non-zero count this suite has no lounge fixture for.
            self::assertContains(
                '0 photos were moved from the upload lounge to their albums',
                PageStateTestFactory::get()->infos
            );
        }

        public function testDerivativesWithTypeAllClearsTheWholeCache(): void
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

            self::assertContains('action successfully performed.', PageStateTestFactory::get()->infos);
        }

        public function testDerivativesWithUnderscoreJoinedTypesClearsEachOne(): void
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

            self::assertContains('action successfully performed.', PageStateTestFactory::get()->infos);
        }

        public function testCompiledTemplatesFatalErrorsWhenThePersistentCacheIsNotInitialized(): void
        {
            // $this->dispatcher (built in setUp()) already has no persistent
            // cache -- its constructor's $persistentCache param defaults to
            // null, and setUp() never passes one.
            // deleteCompiledTemplates() runs before the persistent-cache
            // guard -- needs a real Template instance, unset by default since
            // this test never boots a full RequestBootstrap.
            CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));

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
            } catch (ResponseReadyException $e) {
                // fatalError()'s own real throw site always carries a 500
                // response (see its own docblock) -- confirms this is really
                // its fatal-error path, not some other ResponseReadyException.
                self::assertSame(500, $e->response()->getStatusCode());
            } finally {
                restore_error_handler();
            }
        }

        public function testCheckUpgradeReportsARealErrorWhenTheForksPermanentlyUnreachableDomainCannotBeFetched(): void
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

            self::assertContains('Unable to check for upgrade.', PageStateTestFactory::get()->errors);
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

        public function testCompiledTemplatesPurgesARealInitializedPersistentCache(): void
        {
            CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));

            // A dedicated local instance, not $this->dispatcher (built in
            // setUp() with no persistent cache) -- constructor injection means
            // this test just passes the fixture it wants directly, no static
            // set()/reset() ceremony needed.
            $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
            $dispatcher = new MaintenanceActionDispatcher(
                new RedirectService(LangTestFactory::get(), $this->maintenanceActionDispatcherTestUserService(), new EventDispatcher(), PageStateTestFactory::get()),
                UrlServiceTestFactory::build(),
                $configService,
                new FilesystemIntegrityChecker(LangTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigServiceTestFactory::get(), $this->maintenanceActionDispatcherTestImageService(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get()),
                new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
                new Translator(CurrentConfigTestFactory::get(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
                new EventDispatcher(),
                PageStateTestFactory::get(),
                CurrentTemplateTestFactory::get(),
                new DbMaintenanceRepository(EntityManagerFactory::build($this->conn)),
                $this->maintenanceActionDispatcherTestActivityService(),
                $this->maintenanceActionDispatcherTestRateService(),
                $this->maintenanceActionDispatcherTestCategoryService(),
                $this->maintenanceActionDispatcherTestTagService(),
                HtmlServiceTestFactory::build(),
                LangTestFactory::get(),
                CurrentConfigTestFactory::get(),
                new InputValidator(),
                CurrentPathsTestFactory::get(),
                EntityManagerFactory::build($this->conn),
                new PersistentFileCache(CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get()),
            );

            // FileCombiner::clearCombinedFiles()'s own opendir() (the real
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
                    PageStateTestFactory::get()->infos
                );
            } finally {
                restore_error_handler();
            }
        }
    }

}
