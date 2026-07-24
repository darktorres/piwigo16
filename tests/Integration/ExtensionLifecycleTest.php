<?php

declare(strict_types=1);

// ExtensionLifecycle calls the real l10n() (unqualified, resolves to the
// global namespace) for theme/language error messages -- needs full
// Translator/LangService bootstrap this isolated integration test
// deliberately doesn't load. Same "minimal stub to load standalone" pattern
// as PermalinkServiceTest.php.
namespace {
    if (! function_exists('l10n')) {
        function l10n(string $key, mixed ...$args): string
        {
            return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
        }
    }

    // buildPluginMaintain() now reads Piwigo\Admin\PluginLoader::pluginsPath()
    // (P23 batch 8f-4; the PHPWG_PLUGINS_PATH define is gone), which reads
    // Piwigo\Core\CurrentPaths (Legacy Coupling Retirement gap-closure,
    // entry-shell define()/include round) -- IntegrationTestCase's own
    // setUp() already seeds it against this repo's real root, no per-file
    // guard needed any more. Every plugin id used in this suite is
    // synthetic and never on disk, so it only needs to resolve to a real
    // (if empty-of-that-id) directory for file_exists() checks to return
    // false safely, which the real repo root satisfies the same way the
    // old CWD-relative '.' guard did.

    // script_basename() stub removed -- ActivityService::record() (what
    // pwg_activity() delegates to) now calls Piwigo\Core\PageFilterHelper::
    // scriptBasename() directly (P23 batch 8d), a real class method a
    // same-named bare-function stub can no longer intercept.

    // ExtensionScanner's real-disk theme scan (missingParentTheme()/
    // getChildrenThemes() scan the actual themes/ directory, which
    // contains a real 'default' theme) now calls Piwigo\Core\Lang::load()
    // directly (P23 batch 8d) -- a real static method call, which a
    // bare-function stub in this namespace can no longer intercept (unlike
    // the old free function, method calls always resolve to the real
    // class). No stub needed: this Integration test's real DB/filesystem
    // exercises Lang::load()'s genuine "no description.txt found" fallback
    // for themes without one, which ExtensionScanner::scanTheme() already
    // handles correctly via its own preg_match() branch.

    // pwg_activity() -- ExtensionLifecycle now calls Piwigo\Activity\
    // ActivityService::record() directly (P23 batch 8d), so no stub is
    // needed; this Integration test's real DB connection exercises
    // genuine activity-logging behavior (the whole point of
    // test_language_actions_never_log_activity()/
    // test_plugin_actions_do_log_activity() below) without a stub.

    // get_default_theme()/get_default_language()/userprefs_get_param() are
    // now real Piwigo\Users\UserService/PreferencesService calls (P23 batch
    // 8d) -- ExtensionLifecycle/ExtensionScanner call them directly, so no
    // stub is needed for THOSE; this Integration test's real DB connection
    // exercises the exact same fixture-backed behavior these stubs used to
    // approximate. getDefaultLanguage()'s comparisons below now use the
    // fixture's real active 'en_UK' row directly instead of a synthetic
    // 'en' id.
    //
    // check_theme_installed() (P23 batch 8f-4): the function stub is gone
    // -- UserService now calls Piwigo\Core\ThemeCatalog::checkThemeInstalled()
    // directly, a real static method a bare-function stub can no longer
    // intercept. No stub replacement is needed: setUp() below establishes
    // the real MysqliDb connection to the test database, so the genuine
    // chain runs -- checkThemeInstalled() (false for the fixture's 'modus'
    // default: $conf['themes_dir'] is unset in this suite's minimal $conf)
    // falls through to ThemeCatalog::getPwgThemes()'s real SQL against the
    // fixture's themes table, and getDefaultTheme() resolves to a real
    // value ('default' when no installed theme matches). Every test below
    // that reaches getDefaultTheme() only checks that its result does NOT
    // equal a synthetic themeId() (random per call, never a real theme
    // name), so the exact resolved value doesn't matter, only that
    // resolving it doesn't crash.
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Admin\Extensions\ExtensionLifecycle;
    use Piwigo\Admin\Extensions\ExtensionRepository;
    use Piwigo\Admin\Extensions\ExtensionType;
    use Piwigo\Admin\Extensions\PemCatalog;
    use Piwigo\Admin\Extensions\ZipExtractor;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Html\HtmlService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

    /**
     * Adversarial coverage for ExtensionLifecycle's real state-machine
     * divergence across the 3 extension types (confirmed by direct read of
     * plugins.class.php/themes.class.php/languages.class.php before writing
     * this class -- see ExtensionLifecycle's own docblock). Every test id
     * used for plugin/theme actions is a synthetic, never-installed-on-disk
     * id with no 'parent' key set, so buildPluginMaintain()/
     * buildThemeMaintain() always fall back to the Dummy*_maintain no-op
     * classes and deltree() always receives a non-existent path (a real,
     * safe no-op -- confirmed via direct read of deltree()) -- this suite
     * never touches the real plugins/themes/language directories.
     */
    final class ExtensionLifecycleTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private ExtensionRepository $repo;

        private ExtensionLifecycle $lifecycle;

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
            $this->repo = new ExtensionRepository($this->conn);
            $this->lifecycle = new ExtensionLifecycle($this->repo, new PemCatalog(new ZipExtractor()), new UrlService(new HtmlService()), new ConfigService($this->buildConfigRepository()));

            CurrentConfig::setEnableExtensionsInstall(true);
            CurrentConfig::setPhpExtensionInUrls(false);
            // P23 batch 8f-4: ThemeCatalog::checkThemeInstalled() (called
            // for real here) reads CurrentConfig::themesDir() -- provide the
            // production value so the real filesystem check runs against
            // the real themes/ dir.
            CurrentConfig::setThemesDir(\Piwigo\Core\CurrentPaths::get()->root . 'themes');
            CurrentUser::set(User::fromUserArray(['id' => 1]));
            CurrentUser::markRealUserResolved();
            unset($_REQUEST['method'], $_REQUEST['action']);
            $_SERVER['SCRIPT_NAME'] = '/admin.php';
        }

        #[\Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement('DELETE FROM ' . Tables::plugins());
            $this->conn->executeStatement('DELETE FROM ' . Tables::themes());
            $this->conn->executeStatement('DELETE FROM ' . Tables::languages() . " WHERE id != 'en_UK'");
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET theme = 'modus' WHERE user_id IN (1, 2)");
            $this->conn->executeStatement('DELETE FROM ' . Tables::activity());
            parent::tearDown();
        }

        #[\Override]
        public static function tearDownAfterClass(): void
        {
            // Close the legacy global mysqli handle setUp() opened: the rest
            // of the Integration suite shares this PHP process and is written
            // to the invariant that no legacy connection exists (see e.g.
            // SectionInitializerTest's header) -- leaking it flips later
            // files' MysqliDb-reachable branches from dead to live.
            if (($GLOBALS['mysqli'] ?? null) instanceof \mysqli) {
                $GLOBALS['mysqli']->close();
            }
            unset($GLOBALS['mysqli']);
            parent::tearDownAfterClass();
        }

        // ---------------------------------------------------------- plugin

        public function test_plugin_install_creates_an_inactive_row(): void
        {
            $id = $this->pluginId();
            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('inactive', $row['state']);
            self::assertSame('1.0', $row['version']);
        }

        public function test_plugin_install_when_already_installed_is_a_noop(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, ['version' => '2.0']);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            // version stays '1.0' -- the second install() call broke early
            // (dbRow !== null), never reaching the INSERT.
            self::assertSame('1.0', $row['version']);
        }

        public function test_plugin_activate_when_not_installed_implicitly_installs_first(): void
        {
            $id = $this->pluginId();

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('active', $row['state']);
        }

        public function test_plugin_activate_when_already_active_is_a_noop(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
        }

        public function test_plugin_deactivate_flips_state_back_to_inactive(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('inactive', $row['state']);
        }

        public function test_plugin_deactivate_when_not_installed_returns_no_errors_despite_failing(): void
        {
            // Matches plugins.class.php::perform_action()'s exact quirk:
            // the 'deactivate' case never pushes to $errors itself (only
            // activity_details['result']='error'), so a "failed" deactivate
            // still returns an empty list.
            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $this->pluginId(), null);

            self::assertSame([], $errors);
        }

        public function test_plugin_uninstall_removes_the_row(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'uninstall', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
        }

        public function test_plugin_uninstall_when_not_installed_returns_no_errors(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'uninstall', $this->pluginId(), null);

            self::assertSame([], $errors);
        }

        public function test_plugin_restore_uninstalls_then_reactivates(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'restore', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('active', $row['state']);
        }

        public function test_plugin_delete_with_no_filesystem_entry_only_uninstalls(): void
        {
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'delete', $id, null);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
        }

        // The enable_extensions_install=false guard (performAction()'s own
        // top-level check) calls die() directly, matching
        // plugins.class.php::perform_action()'s exact original behavior --
        // confirmed by direct read, not something this batch changed. Not
        // covered by an automated test here: die() terminates the whole PHP
        // process (this test runner included), so it isn't a catchable
        // \Throwable PHPUnit's expectException() can assert against without
        // separate-process isolation this suite doesn't use elsewhere.

        // ----------------------------------------------------------- theme

        public function test_theme_activate_default_is_a_silent_noop(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'activate', 'default', ['version' => '1.0', 'name' => 'Default']);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Theme, 'default'));
        }

        public function test_theme_activate_rejects_a_missing_parent_theme(): void
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

        public function test_theme_activate_allows_default_as_parent(): void
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

        public function test_theme_activate_rejects_a_second_mobile_theme(): void
        {
            // ConfigService::confUpdateParam('mobile_theme', $id) (called by
            // a successful mobile-theme activate, Legacy Coupling
            // Retirement Phase 5 -- formerly ConfigDb::confUpdateParam(),
            // same call shape preserved exactly) is deliberately called
            // WITHOUT updateGlobal=true, matching themes.class.php's own
            // original call shape -- it persists to the DB but never
            // updates CurrentConfig::$data (and structurally can't touch the
            // legacy $conf global at all, unlike ConfigDb). This is a real,
            // faithfully-preserved legacy quirk: the mobile-theme-uniqueness
            // guard only actually takes effect on the NEXT request, once
            // both get freshly reloaded from the DB at bootstrap -- never
            // within the same request/process that just activated the
            // first mobile theme. Simulate that "next request" state
            // directly rather than asserting a same-process guard that
            // legacy code itself never provides.
            $first = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $first, [
                'version' => '1.0',
                'name' => 'Mobile One',
                'mobile' => true,
            ]);
            CurrentConfig::setEnableExtensionsInstall(true);
            CurrentConfig::setPhpExtensionInUrls(false);
            CurrentConfig::setMobilTheme($first);

            $second = $this->themeId();
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $second, [
                'version' => '1.0',
                'name' => 'Mobile Two',
                'mobile' => true,
            ]);

            self::assertNotSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Theme, $second));
        }

        public function test_theme_deactivate_refuses_to_remove_the_last_theme(): void
        {
            $id = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $id, ['version' => '1.0', 'name' => 'Only Theme']);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $id, ['version' => '1.0', 'name' => 'Only Theme']);

            self::assertNotSame([], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Theme, $id));
        }

        public function test_theme_deactivate_of_a_non_default_theme_succeeds_when_another_theme_exists(): void
        {
            $keep = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $keep, ['version' => '1.0', 'name' => 'Keep']);
            $remove = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $remove, ['version' => '1.0', 'name' => 'Remove']);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $remove, ['version' => '1.0', 'name' => 'Remove']);

            self::assertSame([], $errors);
            self::assertNull($this->repo->find(ExtensionType::Theme, $remove));
            self::assertNotNull($this->repo->find(ExtensionType::Theme, $keep));
        }

        public function test_theme_delete_is_blocked_while_installed(): void
        {
            $id = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $id, ['version' => '1.0', 'name' => 'Installed']);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'delete', $id, ['version' => '1.0', 'name' => 'Installed']);

            self::assertSame(['CANNOT DELETE - THEME IS INSTALLED'], $errors);
        }

        // -------------------------------------------------------- language

        public function test_language_activate_creates_a_row(): void
        {
            $id = 'xx_YY';
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'activate', $id, ['version' => '1.0', 'name' => 'Test Lang']);

            self::assertSame([], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Language, $id));

            $this->repo->delete(ExtensionType::Language, $id);
        }

        public function test_language_activate_when_already_active_returns_the_exact_legacy_message(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'activate', 'en_UK', ['version' => 'auto', 'name' => 'English [UK]']);

            self::assertSame(['CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED'], $errors);
        }

        public function test_language_deactivate_of_the_default_language_is_rejected(): void
        {
            // Piwigo\Users\UserService::getDefaultLanguage() now reads the
            // real fixture default user's language column ('en_UK', the
            // only row this class's setUp() keeps active -- see the
            // DELETE ... WHERE id != 'en_UK' above) instead of a fixed
            // 'en' stub, so this exercises the real guard condition
            // ($id === getDefaultLanguage()) directly against 'en_UK'
            // rather than a synthetic language id.
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'en_UK', null);

            self::assertSame(['CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE'], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Language, 'en_UK'));
        }

        public function test_language_deactivate_when_not_active_returns_the_exact_legacy_message(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'never-activated-xyz', null);

            self::assertSame(['CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED'], $errors);
        }

        public function test_language_delete_while_active_is_rejected(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'delete', 'en_UK', ['version' => 'auto', 'name' => 'English [UK]']);

            self::assertSame(['CANNOT DELETE - LANGUAGE IS ACTIVATED'], $errors);
        }

        public function test_language_delete_of_a_nonexistent_language_is_rejected(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'delete', 'never-existed-xyz', null);

            self::assertSame(['CANNOT DELETE - LANGUAGE DOES NOT EXIST'], $errors);
        }

        public function test_language_actions_never_log_activity(): void
        {
            $before = $this->countActivityRows();

            $this->lifecycle->performAction(ExtensionType::Language, 'activate', 'xx_ZZ', ['version' => '1.0', 'name' => 'Test']);
            $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'xx_ZZ', null);

            self::assertSame($before, $this->countActivityRows());
        }

        public function test_plugin_actions_do_log_activity(): void
        {
            $before = $this->countActivityRows();

            $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $this->pluginId(), ['version' => '1.0']);

            self::assertGreaterThan($before, $this->countActivityRows());
        }

        private function countActivityRows(): int
        {
            $value = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::activity())
                ->executeQuery()
                ->fetchOne();

            return is_numeric($value) ? (int) $value : 0;
        }

        private function pluginId(): string
        {
            return 'p17-test-plugin-' . bin2hex(random_bytes(4));
        }

        private function themeId(): string
        {
            return 'p17-test-theme-' . bin2hex(random_bytes(4));
        }
    }
}
