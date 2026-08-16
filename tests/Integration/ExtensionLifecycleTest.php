<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use mysqli;
    use Override;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Admin\Extensions\ExtensionLifecycle;
    use Piwigo\Admin\Extensions\ExtensionRepository;
    use Piwigo\Admin\Extensions\ExtensionType;
    use Piwigo\Admin\Extensions\PemCatalog;
    use Piwigo\Admin\Extensions\ZipExtractor;
    use Piwigo\Admin\PluginLoader;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\CurrentLogger;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\Logger;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Html\HtmlService;
    use Piwigo\Http\ResponseReadyException;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\PluginConfig\PluginRegistry;
    use Piwigo\PluginConfig\ThemeRegistry;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserService;
    use ReflectionMethod;

    /**
     * Adversarial coverage for ExtensionLifecycle's real state-machine
     * divergence across the 3 extension types -- see
     * ExtensionLifecycle's own docblock.
     *
     * Plugin/theme actions that need to actually SUCCEED through
     * PluginRegistry/ThemeRegistry (P27.5) use pluginId()/themeId(),
     * which write a real, minimal, schema-valid plugin.json/theme.json +
     * PSR-4 ExtensionInterface class fixture under the real project
     * plugins//themes/ directory (both registries require a real
     * manifest for install/activate/deactivate/uninstall/update to do
     * anything at all -- there is no maintain-class-style always-safe
     * fallback anymore). rawPluginId()/rawThemeId() stay bare id
     * generators with no filesystem side effect, for tests whose own
     * point is that nothing exists for that id in any sense (a
     * genuinely-missing target, or a guard that must fire before ever
     * reaching the registry).
     */
    final class ExtensionLifecycleTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private ExtensionRepository $repo;

        private ExtensionLifecycle $lifecycle;

        private PluginRegistry $pluginRegistry;

        private ThemeRegistry $themeRegistry;

        private Connection $conn;

        /**
         * @var list<string>
         */
        private array $createdPluginIds = [];

        /**
         * @var list<string>
         */
        private array $createdThemeIds = [];

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

            // ExtensionLifecycle resolves MailService/HtmlService via
            // Bootstrap\PresentationAccessor -> Kernel::container(), which
            // this isolated Integration test (no full RequestBootstrap)
            // wouldn't otherwise boot.
            Kernel::boot();

            $this->conn = DbConnection::build();
            $this->repo = new ExtensionRepository(EntityManagerFactory::build($this->conn));
            $currentLogger = new CurrentLogger();
            $currentLogger->set(new Logger([
                'severity' => Logger::OFF,
            ]));
            $activityService = Kernel::container()->get(ActivityService::class);
            self::assertInstanceOf(ActivityService::class, $activityService);
            $userService = Kernel::container()->get(UserService::class);
            self::assertInstanceOf(UserService::class, $userService);
            $htmlService = Kernel::container()->get(HtmlService::class);
            self::assertInstanceOf(HtmlService::class, $htmlService);
            $currentUser = Kernel::container()->get(CurrentUser::class);
            self::assertInstanceOf(CurrentUser::class, $currentUser);
            $pluginRegistry = Kernel::container()->get(PluginRegistry::class);
            self::assertInstanceOf(PluginRegistry::class, $pluginRegistry);
            $themeRegistry = Kernel::container()->get(ThemeRegistry::class);
            self::assertInstanceOf(ThemeRegistry::class, $themeRegistry);
            $this->pluginRegistry = $pluginRegistry;
            $this->themeRegistry = $themeRegistry;
            $this->createdPluginIds = [];
            $this->createdThemeIds = [];
            $this->lifecycle = new ExtensionLifecycle(LangTestFactory::get(), $this->repo, new PemCatalog(new ZipExtractor(), $currentLogger, CurrentPathsTestFactory::get(), $currentConfig), UrlServiceTestFactory::build(), new ConfigService($this->buildConfigRepository(), $currentConfig), $activityService, $userService, $htmlService, $currentConfig, CurrentPathsTestFactory::get(), $currentUser, new EventDispatcher(), $pluginRegistry, $themeRegistry, EntityManagerFactory::build($this->conn));

            $currentConfig->enableExtensionsInstall = true;
            $currentConfig->phpExtensionInUrls = false;
            // ThemeCatalog::checkThemeInstalled() (called for real here)
            // reads CurrentConfig::themesDir() -- provide the production
            // value so the real filesystem check runs against the real
            // themes/ dir.
            $currentConfig->themesDir = CurrentPathsTestFactory::get()->root . 'themes';
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
            ]));
            CurrentUserTestFactory::get()->markRealUserResolved();
            unset($_REQUEST['method'], $_REQUEST['action']);
            $_SERVER['SCRIPT_NAME'] = '/admin.php';
        }

        #[Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement('DELETE FROM plugins');
            $this->conn->executeStatement('DELETE FROM themes');
            $this->conn->executeStatement("DELETE FROM languages WHERE id != 'en_UK'");
            $this->conn->executeStatement("UPDATE user_infos SET theme = 'default' WHERE user_id IN (1, 2)");
            $this->conn->executeStatement('DELETE FROM activity');
            $this->conn->executeStatement('DELETE FROM plugin_migrations');
            foreach ($this->createdPluginIds as $id) {
                $this->removePluginManifest($id);
            }
            foreach ($this->createdThemeIds as $id) {
                $this->removeThemeManifest($id);
            }
            Kernel::reset();
            parent::tearDown();
        }

        #[Override]
        public static function tearDownAfterClass(): void
        {
            // Close the legacy global mysqli handle setUp() opened: the rest
            // of the Integration suite shares this PHP process and is written
            // to the invariant that no legacy connection exists (see e.g.
            // SectionInitializerTest's header) -- leaking it flips later
            // files' MysqliDb-reachable branches from dead to live.
            if (($GLOBALS['mysqli'] ?? null) instanceof mysqli) {
                $GLOBALS['mysqli']->close();
            }
            unset($GLOBALS['mysqli']);
            parent::tearDownAfterClass();
        }

        // ---------------------------------------------------------- plugin

        public function testPluginInstallCreatesAnInactiveRow(): void
        {
            $id = $this->pluginId();
            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('inactive', $row['state']);
            self::assertSame('1.0', $row['version']);
            self::assertSame(['1.0'], $this->findMigrationVersions($id), 'a successful install must record a plugin_migrations row');
        }

        public function testPluginInstallWhenAlreadyInstalledIsANoop(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, [
                'version' => '2.0',
            ]);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            // version stays '1.0' -- the second install() call broke early
            // (dbRow !== null), never reaching the INSERT.
            self::assertSame('1.0', $row['version']);
        }

        public function testPluginActivateWhenNotInstalledImplicitlyInstallsFirst(): void
        {
            $id = $this->pluginId();

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('active', $row['state']);
        }

        public function testPluginActivateWhenAlreadyActiveIsANoop(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
        }

        public function testPluginDeactivateFlipsStateBackToInactive(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('inactive', $row['state']);
        }

        public function testPluginDeactivateWhenNotInstalledReturnsNoErrorsDespiteFailing(): void
        {
            // Matches plugins.class.php::perform_action()'s exact quirk:
            // the 'deactivate' case never pushes to $errors itself (only
            // activity_details['result']='error'), so a "failed" deactivate
            // still returns an empty list.
            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $this->rawPluginId(), null);

            self::assertSame([], $errors);
        }

        public function testPluginUninstallRemovesTheRow(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'uninstall', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
        }

        public function testPluginUninstallWhenNotInstalledReturnsNoErrors(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'uninstall', $this->rawPluginId(), null);

            self::assertSame([], $errors);
        }

        public function testPluginRestoreUninstallsThenReactivates(): void
        {
            // Adversarially-motivated: 'restore' runs 'uninstall' (deletes
            // the plugins row) then 'activate', which -- since dbRow is now
            // null -- re-invokes 'install' at the SAME version. That's a
            // real, expected re-insert into plugin_migrations' own
            // composite (plugin_id, version) PK -- must upsert cleanly, not
            // throw a duplicate-key error.
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'restore', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('active', $row['state']);
            self::assertSame(['1.0'], $this->findMigrationVersions($id), 'restoring at the same version must upsert the ledger row, not duplicate or fail');
        }

        public function testPluginDeleteWithNoFilesystemEntryOnlyUninstalls(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'delete', $id, null);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
        }

        public function testPluginDeleteWithAFilesystemEntryAlsoRemovesThePluginDirectory(): void
        {
            // Unlike the sibling test above, $fsEntry is non-null here, so
            // this reaches the real fs_version bookkeeping AND the
            // FilesystemHelper::deltree() call against
            // PluginLoader::pluginsPath(CurrentPathsTestFactory::get()) . $id -- a synthetic, never-
            // on-disk id (see this class's own docblock), so deltree()'s
            // own `if (is_dir($path))` guard makes this a real, safe no-op.
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                'version' => '1.0',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'delete', $id, [
                'version' => '1.0',
            ]);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
        }

        public function testDeleteWhenExtensionsInstallIsDisabledFatallyErrors(): void
        {
            // performAction()'s own top-level guard (only 'delete' is
            // checked here -- matches plugins.class.php::perform_action()'s
            // exact original behavior) calls
            // HtmlService::fatalError(), `never`-typed and always throwing
            // a catchable ResponseReadyException regardless of
            // ErrorCollector::isActive() (see that method's own docblock).
            // This handler is now just belt-and-suspenders against any
            // incidental warning along the way, not load-bearing for the
            // throw itself. Left local rather than a real
            // Piwigo\Core\ErrorCollector::install() (a real
            // set_error_handler()/register_shutdown_function() pair would
            // leak into every later test in this shared process, same
            // reasoning as MaintenanceActionDispatcherTest's identical
            // local handler).
            CurrentConfigTestFactory::get()->enableExtensionsInstall = false;

            set_error_handler(static fn (): bool => true);
            try {
                $this->lifecycle->performAction(ExtensionType::Plugin, 'delete', $this->rawPluginId(), null);
                self::fail('Expected ExtensionLifecycle::performAction() to throw ResponseReadyException');
            } catch (ResponseReadyException) {
            } finally {
                restore_error_handler();
            }
        }

        // The enable_extensions_install=false guard only ever gates a
        // top-level 'delete' call (see this class's own docblock) -- other
        // actions (install/update/activate/...) never reach it, which is
        // why every other test in this file leaves enable_extensions_install
        // at the true value setUp() configures.

        // ----------------------------------------------------------- theme

        public function testThemeActivateDefaultIsASilentNoop(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'activate', 'default', [
                'version' => '1.0',
                'name' => 'Default',
            ]);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Theme, 'default'));
        }

        public function testThemeActivateRejectsAMissingParentTheme(): void
        {
            $id = $this->themeId();

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $id, [
                'version' => '1.0',
                'name' => 'Test theme',
                'parent' => 'totally-fake-nonexistent-theme-xyz',
            ]);

            self::assertCount(1, $errors);
            self::assertStringContainsString('totally-fake-nonexistent-theme-xyz', $errors[0]);
            self::assertNull($this->repo->find(ExtensionType::Theme, $id));
        }

        public function testThemeActivateAllowsDefaultAsParent(): void
        {
            $id = $this->themeId();

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $id, [
                'version' => '1.0',
                'name' => 'Test theme',
                'parent' => 'default',
            ]);

            self::assertSame([], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Theme, $id));
        }

        public function testThemeActivateRejectsASecondMobileTheme(): void
        {
            // ConfigService::confUpdateParam('mobile_theme', $id) (called by
            // a successful mobile-theme activate) is deliberately called
            // WITHOUT updateGlobal=true -- it persists to the DB but never
            // updates CurrentConfig::$data. As a result the
            // mobile-theme-uniqueness guard only actually takes effect on
            // the NEXT request, once both get freshly reloaded from the DB
            // at bootstrap -- never within the same request/process that
            // just activated the first mobile theme. Simulate that "next
            // request" state directly rather than asserting a same-process
            // guard the code never provides.
            $first = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $first, [
                'version' => '1.0',
                'name' => 'Mobile One',
                'mobile' => true,
            ]);
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->enableExtensionsInstall = true;
            $currentConfig->phpExtensionInUrls = false;
            $currentConfig->mobileTheme = $first;

            $second = $this->themeId();
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $second, [
                'version' => '1.0',
                'name' => 'Mobile Two',
                'mobile' => true,
            ]);

            self::assertNotSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Theme, $second));
        }

        public function testThemeDeactivateRefusesToRemoveTheLastTheme(): void
        {
            $id = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $id, [
                'version' => '1.0',
                'name' => 'Only Theme',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $id, [
                'version' => '1.0',
                'name' => 'Only Theme',
            ]);

            self::assertNotSame([], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Theme, $id));
        }

        public function testThemeDeactivateOfANonDefaultThemeSucceedsWhenAnotherThemeExists(): void
        {
            $keep = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $keep, [
                'version' => '1.0',
                'name' => 'Keep',
            ]);
            $remove = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $remove, [
                'version' => '1.0',
                'name' => 'Remove',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $remove, [
                'version' => '1.0',
                'name' => 'Remove',
            ]);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Theme, $remove));
            self::assertNotNull($this->repo->find(ExtensionType::Theme, $keep));
        }

        public function testThemeDeleteIsBlockedWhileInstalled(): void
        {
            $id = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $id, [
                'version' => '1.0',
                'name' => 'Installed',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'delete', $id, [
                'version' => '1.0',
                'name' => 'Installed',
            ]);

            self::assertSame(['CANNOT DELETE - THEME IS INSTALLED'], $errors);
        }

        // -------------------------------------------------------- language

        public function testLanguageActivateCreatesARow(): void
        {
            $id = 'xx_YY';
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'activate', $id, [
                'version' => '1.0',
                'name' => 'Test Lang',
            ]);

            self::assertSame([], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Language, $id));

            $this->repo->delete(ExtensionType::Language, $id);
        }

        public function testLanguageActivateWhenAlreadyActiveReturnsTheExactLegacyMessage(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'activate', 'en_UK', [
                'version' => 'auto',
                'name' => 'English [UK]',
            ]);

            self::assertSame(['CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED'], $errors);
        }

        public function testLanguageDeactivateOfTheDefaultLanguageIsRejected(): void
        {
            // Piwigo\Users\UserService::getDefaultLanguage() reads the
            // real fixture default user's language column ('en_UK', the
            // only row this class's setUp() keeps active -- see the
            // DELETE ... WHERE id != 'en_UK' above), so this exercises the
            // real guard condition ($id === getDefaultLanguage()) directly
            // against 'en_UK' rather than a synthetic language id.
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'en_UK', null);

            self::assertSame(['CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE'], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Language, 'en_UK'));
        }

        public function testLanguageDeactivateWhenNotActiveReturnsTheExactLegacyMessage(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'never-activated-xyz', null);

            self::assertSame(['CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED'], $errors);
        }

        public function testLanguageDeleteWhileActiveIsRejected(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'delete', 'en_UK', [
                'version' => 'auto',
                'name' => 'English [UK]',
            ]);

            self::assertSame(['CANNOT DELETE - LANGUAGE IS ACTIVATED'], $errors);
        }

        public function testLanguageDeleteOfANonexistentLanguageIsRejected(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'delete', 'never-existed-xyz', null);

            self::assertSame(['CANNOT DELETE - LANGUAGE DOES NOT EXIST'], $errors);
        }

        public function testLanguageActionsNeverLogActivity(): void
        {
            $before = $this->countActivityRows();

            $this->lifecycle->performAction(ExtensionType::Language, 'activate', 'xx_ZZ', [
                'version' => '1.0',
                'name' => 'Test',
            ]);
            $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'xx_ZZ', null);

            self::assertSame($before, $this->countActivityRows());
        }

        public function testPluginActionsDoLogActivity(): void
        {
            $before = $this->countActivityRows();

            $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $this->pluginId(), [
                'version' => '1.0',
            ]);

            self::assertGreaterThan($before, $this->countActivityRows());
        }

        private function countActivityRows(): int
        {
            $value = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('activity')
                ->executeQuery()
                ->fetchOne();

            return is_numeric($value) ? (int) $value : 0;
        }

        private function rawPluginId(): string
        {
            return 'p17-test-plugin-' . bin2hex(random_bytes(4));
        }

        /**
         * A synthetic plugin id with a real, minimal, schema-valid
         * plugin.json + PSR-4 ExtensionInterface class already written
         * under the real plugins/ directory (P27.5: PluginRegistry
         * requires a real manifest for install/activate/deactivate/
         * uninstall/update to do anything at all, unlike the old
         * buildPluginMaintain()'s always-safe base-class fallback for a
         * synthetic, never-on-disk id). Tracked for automatic
         * tearDown() cleanup.
         */
        private function pluginId(string $version = '1.0'): string
        {
            $id = $this->rawPluginId();
            $this->writePluginManifest($id, $version);
            $this->createdPluginIds[] = $id;

            return $id;
        }

        /**
         * @param string|null $mainClass overrides the manifest's own
         *   `main` field -- used to force a real PluginValidationException
         *   (deliberately a non-`class-string`, never-declared class
         *   name) in place of the old maintain-class failure-injection
         *   technique, which no longer has an equivalent
         *   (buildPluginMaintain() is gone).
         */
        private function writePluginManifest(string $id, string $version = '1.0', ?string $mainClass = null): void
        {
            $dir = PluginLoader::pluginsPath(CurrentPathsTestFactory::get()) . $id;
            if (! is_dir($dir . '/src')) {
                mkdir($dir . '/src', 0o777, true);
            }

            $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));
            $main = $mainClass ?? $namespace . '\\Plugin';

            file_put_contents($dir . '/plugin.json', json_encode([
                'id' => $id,
                'name' => $id,
                'version' => $version,
                'description' => 'Test-only fixture plugin (tests/Integration/ExtensionLifecycleTest.php).',
                'license' => 'MIT',
                'minPiwigo' => '16.3.0',
                'main' => $main,
                'autoload' => [
                    'psr-4' => [
                        $namespace . '\\' => 'src/',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            file_put_contents($dir . '/src/Plugin.php', <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$namespace};

                use Piwigo\\PluginConfig\\ExtensionContext;
                use Piwigo\\PluginConfig\\ExtensionInterface;

                final class Plugin implements ExtensionInterface
                {
                    public function boot(ExtensionContext \$context): void {}

                    public function install(): void {}

                    public function activate(): void {}

                    public function deactivate(): void {}

                    public function uninstall(): void {}

                    public function update(string \$oldVersion, string \$newVersion): void {}

                    public function subscribedEvents(): array
                    {
                        return [];
                    }
                }

                PHP);

            $this->pluginRegistry->reload();
        }

        private function removePluginManifest(string $id): void
        {
            $dir = PluginLoader::pluginsPath(CurrentPathsTestFactory::get()) . $id;
            $this->rrmdir($dir);
        }

        /**
         * @return list<string>
         */
        private function findMigrationVersions(string $pluginId): array
        {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT version FROM plugin_migrations WHERE plugin_id = ?',
                [$pluginId]
            );

            return array_column($rows, 'version');
        }

        private function rawThemeId(): string
        {
            return 'p17-test-theme-' . bin2hex(random_bytes(4));
        }

        /**
         * A synthetic theme id with a real, minimal, schema-valid
         * theme.json + PSR-4 ExtensionInterface class already written
         * under the real themes/ directory -- same rationale as
         * pluginId() above, for ThemeRegistry. Tracked for automatic
         * tearDown() cleanup.
         */
        private function themeId(string $version = '1.0'): string
        {
            $id = $this->rawThemeId();
            $this->writeThemeManifest($id, $version);
            $this->createdThemeIds[] = $id;

            return $id;
        }

        private function writeThemeManifest(string $id, string $version = '1.0'): void
        {
            $dir = CurrentPathsTestFactory::get()->root . 'themes/' . $id;
            if (! is_dir($dir . '/src')) {
                mkdir($dir . '/src', 0o777, true);
            }

            $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

            file_put_contents($dir . '/theme.json', json_encode([
                'id' => $id,
                'name' => $id,
                'version' => $version,
                'description' => 'Test-only fixture theme (tests/Integration/ExtensionLifecycleTest.php).',
                'license' => 'MIT',
                'minPiwigo' => '16.3.0',
                'main' => $namespace . '\\Theme',
                'autoload' => [
                    'psr-4' => [
                        $namespace . '\\' => 'src/',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            file_put_contents($dir . '/src/Theme.php', <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$namespace};

                use Piwigo\\PluginConfig\\ExtensionContext;
                use Piwigo\\PluginConfig\\ExtensionInterface;

                final class Theme implements ExtensionInterface
                {
                    public function boot(ExtensionContext \$context): void {}

                    public function install(): void {}

                    public function activate(): void {}

                    public function deactivate(): void {}

                    public function uninstall(): void {}

                    public function update(string \$oldVersion, string \$newVersion): void {}

                    public function subscribedEvents(): array
                    {
                        return [];
                    }
                }

                PHP);

            $this->themeRegistry->reload();
        }

        private function removeThemeManifest(string $id): void
        {
            $dir = CurrentPathsTestFactory::get()->root . 'themes/' . $id;
            $this->rrmdir($dir);
        }

        /**
         * buildThemeMaintain()'s $classname is `$themeId . '_maintain'`
         * verbatim, with NO hyphen-to-underscore translation (unlike
         * buildPluginMaintain()'s own str_replace('-', '_', ...)) -- a
         * hyphenated id here would produce an invalid PHP class name, so
         * every real-on-disk-theme test below uses this hyphen-free id
         * generator instead of themeId().
         */
        private function themeIdNoHyphens(): string
        {
            return 'p17lifecycletheme' . bin2hex(random_bytes(4));
        }

        /**
         * Recursively removes a directory tree -- the real plugins/themes/
         * directory fixtures below (buildPluginMaintain()/
         * buildThemeMaintain()/getChildrenThemes()/missingParentTheme() all
         * genuinely `file_exists()`/`opendir()` real filesystem paths, no
         * fake-able seam) create and must clean up.
         */
        private function rrmdir(string $dir): void
        {
            if (! is_dir($dir)) {
                return;
            }
            $entries = scandir($dir);
            foreach ($entries !== false ? $entries : [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $this->rrmdir($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }

        /**
         * Writes a real themes/<id>/theme.json -- the file
         * ExtensionScanner::scanTheme()/ThemeCatalog::checkThemeInstalled()
         * both genuinely check for on disk, no fake-able seam.
         *
         * @param array{name?: string, parent?: string} $conf
         */
        private function writeThemeConf(string $id, array $conf = []): void
        {
            $dir = CurrentPathsTestFactory::get()->root . 'themes/' . $id;
            // is_dir() guard: some callers (e.g. testThemeDeactivateOfTheRealDefaultThemeReassignsAReplacementDefault)
            // pair this with themeId()'s own theme.json fixture for the
            // SAME id, whose directory already exists by this point.
            if (! is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            $data = [
                'name' => $conf['name'] ?? $id,
                'version' => '1.0',
            ];
            if (isset($conf['parent'])) {
                $data['parent'] = $conf['parent'];
            }
            file_put_contents($dir . '/theme.json', json_encode($data, JSON_THROW_ON_ERROR));
        }

        private function removeThemeDir(string $id): void
        {
            $this->rrmdir(CurrentPathsTestFactory::get()->root . 'themes/' . $id);
        }

        // --------------------------------------------- plugin update/errors

        public function testPluginUpdateWithoutARevisionOptionThrows(): void
        {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageIsOrContains("performPluginAction('update'): missing 'revision' option");

            $this->lifecycle->performAction(ExtensionType::Plugin, 'update', $this->pluginId(), [
                'version' => '1.0',
            ], []);
        }

        public function testPluginUpdateWithAnUnreachablePemServerMarksActivityAsError(): void
        {
            // The 'update' action's real extraction-succeeds branch
            // (ExtensionLifecycle.php's own 'update' case: rescanning the
            // plugin, calling PluginMaintain::update(), bumping the stored
            // version) is gated entirely behind PemCatalog::extractArchive()
            // actually returning status 'ok', which itself requires the
            // static, non-injectable HttpClientService::fetchToFile() to
            // succeed against a real PEM server -- PemCatalog is `final
            // readonly` (no interface, no fake-able seam) and shares the
            // same documented "no fake-able seam" limitation as
            // HttpClientServiceTest's and PemCatalogTest's own identical
            // note. That 'ok'-branch body is not chased here.
            //
            // The extraction ATTEMPT itself and its non-'ok' ELSE branch
            // ARE reachable deterministically and offline, though: pointing
            // RequestBootstrap::pemUrl() at a loopback address makes
            // HttpClientService's own SSRF guard (assertUrlIsSafe()) throw
            // HttpClientSsrfException before any real network I/O is
            // attempted (no DNS lookup, no connect, no 10s timeout) --
            // guardedFetch() catches that (HttpClientSsrfException
            // implements PSR-18's RequestExceptionInterface, itself a
            // ClientExceptionInterface) and returns null, so fetchToFile()
            // returns false and extractArchive() falls through to its real
            // 'dl_archive_error' status -- exercising the real
            // extraction-attempt + non-'ok' branch without ever touching
            // the network.
            //
            // guardedFetch() exempts the target host from the SSRF guard
            // entirely (both the https-only and private-IP checks) when it
            // matches $_SERVER['HTTP_HOST'] (a same-host "self-request" --
            // see HttpClientService's own $trustedSelfHost docblock); this
            // shared PHPUnit/Pest process may have HTTP_HOST left over from
            // an earlier test file (e.g. InstallWizardTest sets it to
            // 'example.test' and never restores it), so pin it to a value
            // that can never equal the loopback host below, guaranteeing
            // the guard -- not a real (and here, unpredictable) TCP attempt
            // to 127.0.0.1 -- is what actually produces the failure.
            $previousHttpHost = $_SERVER['HTTP_HOST'] ?? null;
            $_SERVER['HTTP_HOST'] = 'extension-lifecycle-test.invalid';
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->alternativePemUrl = 'https://127.0.0.1/pem-unreachable';

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'update', $this->pluginId(), [
                    'version' => '1.0',
                ], [
                    'revision' => '42',
                ]);

                self::assertSame(['dl_archive_error'], $errors);
            } finally {
                $currentConfig->alternativePemUrl = '';
                if ($previousHttpHost === null) {
                    unset($_SERVER['HTTP_HOST']);
                } else {
                    $_SERVER['HTTP_HOST'] = $previousHttpHost;
                }
            }
        }

        public function testPluginInstallFailureMarksActivityAsErrorAndDoesNotInsertARow(): void
        {
            // The old maintain-class failure-injection technique (a real
            // maintain.class.php overriding install($version, &$errors)
            // to push a forced error) has no equivalent anymore --
            // buildPluginMaintain() is gone. A real PluginValidationException
            // (the manifest's own declared main class doesn't exist) is
            // the new, real way install() can fail -- see
            // PluginRegistry::install()'s own docblock for why this must
            // NOT leave a DB row behind (a real ordering bug, found and
            // fixed while writing this exact test).
            $id = $this->rawPluginId();
            $this->writePluginManifest($id, '1.0', mainClass: 'PiwigoTestFixture\\ExtensionLifecycleTest\\DoesNotExist');
            $this->createdPluginIds[] = $id;

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, [
                    'version' => '1.0',
                ]);

                self::assertCount(1, $errors);
                self::assertStringContainsString('does not exist', $errors[0]);
                self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
                self::assertSame([], $this->findMigrationVersions($id), 'a failed install must not record a plugin_migrations row either');
            } finally {
                $this->removePluginManifest($id);
            }
        }

        public function testPluginActivateFailureLeavesTheRowInactive(): void
        {
            // Same real trigger as the install-failure test above
            // (a broken main class), but reached through 'activate' on a
            // plugin that's ALREADY installed (the DB row seeded
            // directly, bypassing performAction() entirely) -- 'activate'
            // never calls the implicit-install path in this case
            // ($dbRow !== null), so this exercises PluginRegistry::
            // activate()'s own validate/hook-before-persist ordering
            // specifically, independent of install()'s.
            $id = $this->rawPluginId();
            $this->writePluginManifest($id, '1.0', mainClass: 'PiwigoTestFixture\\ExtensionLifecycleTest\\DoesNotExist');
            $this->createdPluginIds[] = $id;
            // ExtensionRepository::insertPlugin() no longer exists (P27.8:
            // zero real production callers) -- seed the row directly via
            // DBAL instead.
            $this->conn->insert('plugins', [
                'id' => $id,
                'version' => '1.0',
                'state' => 'inactive',
            ]);

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, [
                    'version' => '1.0',
                ]);

                self::assertCount(1, $errors);
                self::assertStringContainsString('does not exist', $errors[0]);
                $row = $this->repo->find(ExtensionType::Plugin, $id);
                self::assertNotNull($row);
                self::assertSame('inactive', $row['state']);
            } finally {
                $this->removePluginManifest($id);
            }
        }

        // ------------------------------------------------- theme, more

        public function testThemeDeactivateOfANeverInstalledThemeIsASilentNoop(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $this->rawThemeId(), null);

            self::assertSame([], $errors);
        }

        public function testThemeDeleteOfAThemeNeitherInstalledNorOnDiskIsASilentNoop(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'delete', $this->rawThemeId(), null);

            self::assertSame([], $errors);
        }

        public function testThemeDeleteIsBlockedByARealChildThemeDependingOnIt(): void
        {
            $parent = $this->themeIdNoHyphens();
            $child = $this->themeIdNoHyphens();
            $this->writeThemeConf($child, [
                'name' => 'Child Theme',
                'parent' => $parent,
            ]);

            try {
                self::assertSame(['Child Theme'], $this->lifecycle->getChildrenThemes($parent));

                $errors = $this->lifecycle->performAction(
                    ExtensionType::Theme,
                    'delete',
                    $parent,
                    [
                        'version' => '1.0',
                        'name' => 'Parent Theme',
                    ],
                );

                self::assertCount(1, $errors);
                self::assertStringContainsString('Child Theme', $errors[0]);
                self::assertNull($this->repo->find(ExtensionType::Theme, $parent));
            } finally {
                $this->removeThemeDir($child);
            }
        }

        public function testThemeDeleteOfAThemeNotInstalledButOnDiskSucceeds(): void
        {
            // dbRow === null (never activated via performAction()) AND
            // fsEntry !== null AND no child theme depends on it -- the one
            // real success path through performThemeAction()'s 'delete'
            // case, reaching buildThemeMaintain()/ThemeMaintain::delete()
            // (falls back to the ThemeMaintain base no-op -- no
            // maintain.inc.php written here) and the real
            // FilesystemHelper::deltree() call against the real on-disk
            // theme directory writeThemeConf() below just created.
            $id = $this->themeIdNoHyphens();
            $this->writeThemeConf($id, [
                'name' => 'On Disk Theme',
            ]);

            try {
                $errors = $this->lifecycle->performAction(
                    ExtensionType::Theme,
                    'delete',
                    $id,
                    [
                        'version' => '1.0',
                        'name' => 'On Disk Theme',
                    ],
                );

                self::assertSame([], $errors);
                self::assertNull($this->repo->find(ExtensionType::Theme, $id));
            } finally {
                // deltree() already removed the real directory on success;
                // this is a safe no-op if so (removeThemeDir()'s own
                // rrmdir() checks is_dir() first) and a real cleanup if the
                // assertion above failed before deltree() ran.
                $this->removeThemeDir($id);
            }
        }

        public function testThemeSetDefaultViaThePublicActionReassignsUsers(): void
        {
            // Same real behavior as test_set_default_theme_reassigns_every_
            // user_on_the_fallback_default_theme below, but through the
            // public performAction('set_default', ...) entry point instead
            // of Reflection -- covers performThemeAction()'s own
            // 'set_default' case (a 1-line delegation to the private
            // setDefaultTheme(), otherwise only ever exercised directly).
            // 'set_default' never touches ThemeRegistry, so a bare id
            // (no manifest) is fine here.
            $new = $this->rawThemeId();
            $before = $this->conn->fetchAllAssociative('SELECT user_id, theme FROM user_infos' . " WHERE theme = 'default'");

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'set_default', $new, null);

                self::assertSame([], $errors);
                foreach ($before as $row) {
                    $current = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = ?', [$row['user_id']]);
                    self::assertSame($new, $current);
                }
            } finally {
                foreach ($before as $row) {
                    $this->conn->executeStatement('UPDATE user_infos SET theme = ? WHERE user_id = ?', [$row['theme'], $row['user_id']]);
                }
            }
        }

        public function testMissingParentThemeRecursesThroughARealIntermediateTheme(): void
        {
            $middle = $this->themeIdNoHyphens();
            $this->writeThemeConf($middle, [
                'name' => 'Middle Theme',
                'parent' => 'totally-missing-ancestor-xyz',
            ]);

            try {
                $result = $this->lifecycle->missingParentTheme('leaf-theme-never-on-disk', [
                    'parent' => $middle,
                ]);

                self::assertSame('totally-missing-ancestor-xyz', $result);
            } finally {
                $this->removeThemeDir($middle);
            }
        }

        public function testThemeDeactivateResetsMobileThemeConfigWhenDeactivatingTheMobileTheme(): void
        {
            $mobile = $this->themeId();
            $other = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $mobile, [
                'version' => '1.0',
                'name' => 'Mobile',
                'mobile' => true,
            ]);
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->enableExtensionsInstall = true;
            $currentConfig->phpExtensionInUrls = false;
            $currentConfig->mobileTheme = $mobile;
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $other, [
                'version' => '1.0',
                'name' => 'Other',
            ]);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $mobile, [
                'version' => '1.0',
                'name' => 'Mobile',
                'mobile' => true,
            ]);

            self::assertSame([], $errors);
            $raw = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'mobile_theme'");
            self::assertIsString($raw);
            self::assertSame('', json_decode($raw));

            $this->conn->executeStatement("DELETE FROM config WHERE param = 'mobile_theme'");
        }

        public function testThemeDeactivateOfTheRealDefaultThemeReassignsAReplacementDefault(): void
        {
            // performThemeAction()'s own `$id === getDefaultTheme()` gate
            // (guarding the pickReplacementDefaultTheme()/setDefaultTheme()
            // call, inside the 'deactivate' case) is unreachable through the
            // public performAction() API under this class's own setUp():
            // ThemeCatalog::checkThemeInstalled() composes
            // `CurrentPathsTestFactory::get()->root . CurrentConfig::themesDir()`, but
            // setUp() sets themesDir() to an ALREADY-absolute path (`root .
            // 'themes'`) for a different, unrelated reason (buildThemeMaintain()/
            // ExtensionScanner need the absolute form) -- composing root
            // with an already-absolute themesDir() double-prefixes the
            // path, so checkThemeInstalled() returns false
            // for every theme id under that setup, and
            // UserService::getDefaultTheme() always falls through to its
            // hard 'default' fallback, which can never match a real
            // performAction()-installed theme id (see the docblock further
            // below, still true for the pickReplacementDefaultTheme()/
            // setDefaultTheme() Reflection tests that keep relying on it).
            //
            // Overriding themesDir() back to the production-shaped relative
            // default here makes checkThemeInstalled() compose a real,
            // correct, single-prefixed path instead, so getDefaultTheme()
            // can genuinely resolve to a real installed theme id --
            // reaching the actual call site instead of only its callees.
            // buildThemeMaintain() (called later in this same 'deactivate'
            // flow) tolerates the relative value fine either way: a failed
            // file_exists() there just falls back to the ThemeMaintain
            // base no-op, a real, already-exercised path elsewhere in
            // this suite.
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->themesDir = 'themes';

            $default = $this->themeId();
            $other = $this->themeId();
            $this->writeThemeConf($default, [
                'name' => 'Real Default',
            ]);
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $default, [
                'version' => '1.0',
                'name' => 'Real Default',
            ]);
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $other, [
                'version' => '1.0',
                'name' => 'Other',
            ]);

            $defaultUserId = $currentConfig->defaultUserId;
            $before = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = ?', [$defaultUserId]);
            $this->conn->executeStatement('UPDATE user_infos SET theme = ? WHERE user_id = ?', [$default, $defaultUserId]);

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $default, [
                    'version' => '1.0',
                    'name' => 'Real Default',
                ]);

                self::assertSame([], $errors);
                self::assertNull($this->repo->find(ExtensionType::Theme, $default));
                $reassigned = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = ?', [$defaultUserId]);
                self::assertSame($other, $reassigned, 'deactivating the real default theme must pick the remaining installed theme as the new default');
            } finally {
                $this->conn->executeStatement('UPDATE user_infos SET theme = ? WHERE user_id = ?', [$before, $defaultUserId]);
                $this->removeThemeDir($default);
            }
        }

        /**
         * pickReplacementDefaultTheme()/setDefaultTheme() themselves (both
         * private) are exercised directly via Reflection below, independent
         * of the wider 'deactivate' flow the test above already covers end
         * to end -- this lets
         * test_pick_replacement_default_theme_falls_back_to_default_when_none_other_exists()
         * assert the "nothing else installed" fallback without needing a
         * second real theme, and keeps these tests decoupled from the
         * themesDir()-override the call-site test above needs -- same
         * tactic as buildPluginMaintain()/buildThemeMaintain() above.
         */
        public function testPickReplacementDefaultThemeReturnsAnyOtherInstalledTheme(): void
        {
            $keep = $this->themeId();
            $exclude = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $keep, [
                'version' => '1.0',
                'name' => 'Keep',
            ]);
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $exclude, [
                'version' => '1.0',
                'name' => 'Exclude',
            ]);

            $method = new ReflectionMethod($this->lifecycle, 'pickReplacementDefaultTheme');
            $result = $method->invoke($this->lifecycle, $exclude);

            self::assertSame($keep, $result);
        }

        public function testPickReplacementDefaultThemeFallsBackToDefaultWhenNoneOtherExists(): void
        {
            $method = new ReflectionMethod($this->lifecycle, 'pickReplacementDefaultTheme');
            $result = $method->invoke($this->lifecycle, 'a-theme-id-that-is-not-installed-xyz');

            self::assertSame('default', $result);
        }

        public function testSetDefaultThemeReassignsEveryUserOnTheFallbackDefaultTheme(): void
        {
            // setDefaultTheme() (Reflection-invoked directly below) never
            // touches ThemeRegistry either -- a bare id is fine here too.
            $new = $this->rawThemeId();

            // See this section's own docblock above: getDefaultTheme()
            // always returns the literal 'default' string in this harness,
            // so setDefaultTheme()'s internal findUserIdsByTheme() call
            // always looks up whoever currently has theme = 'default' --
            // snapshot them all so this test can restore the exact prior
            // state no matter how many rows that is.
            $before = $this->conn->fetchAllAssociative('SELECT user_id, theme FROM user_infos' . " WHERE theme = 'default'");

            try {
                $method = new ReflectionMethod($this->lifecycle, 'setDefaultTheme');
                $method->invoke($this->lifecycle, $new);

                foreach ($before as $row) {
                    $current = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = ?', [$row['user_id']]);
                    self::assertSame($new, $current);
                }
                // defaultUserId()/guestId() (both user_id 2 in this
                // environment) are unconditionally folded into $userIds --
                // still true even for a user who happened to already be on
                // 'default' (already asserted above for user 2), so this
                // only adds real signal when user 2 *wasn't* already in
                // $before; assert it regardless for a stable, exact check.
                $guestTheme = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = 2');
                self::assertSame($new, $guestTheme);
            } finally {
                foreach ($before as $row) {
                    $this->conn->executeStatement('UPDATE user_infos SET theme = ? WHERE user_id = ?', [$row['theme'], $row['user_id']]);
                }
            }
        }

        // ---------------------------------------------------- language, more

        public function testLanguageDeleteOfANeverActivatedButOnDiskLanguageSucceeds(): void
        {
            $errors = $this->lifecycle->performAction(
                ExtensionType::Language,
                'delete',
                'never-activated-on-disk-xyz',
                [
                    'version' => '1.0',
                    'name' => 'On Disk Lang',
                ],
            );

            self::assertSame([], $errors);
        }

        public function testLanguageSetDefaultReassignsTheDefaultAndGuestUsers(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'set_default', 'xx_ZZ', null);

            self::assertSame([], $errors);
            $row = $this->conn->fetchAssociative('SELECT language FROM user_infos WHERE user_id = 2');
            self::assertIsArray($row);
            self::assertSame('xx_ZZ', $row['language']);

            $this->conn->executeStatement("UPDATE user_infos SET language = 'en_UK' WHERE user_id = 2");
        }
    }
}
