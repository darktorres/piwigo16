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

    // buildPluginMaintain() reads PHPWG_PLUGINS_PATH directly -- normally
    // define()'d by include/functions_plugins.inc.php as part of full
    // legacy bootstrap, never loaded by this isolated integration test.
    // Every plugin id used in this suite is synthetic and never on disk, so
    // this only needs to resolve to a real (if empty-of-that-id) directory
    // for file_exists() checks to return false safely. '.' (not an
    // absolute path) matches PHPWG_ROOT_PATH's real value everywhere else
    // in this codebase (see e.g. UrlServiceTest.php's identical guard) --
    // using an absolute path here corrupted PHPWG_ROOT_PATH project-wide
    // for every other Integration test file loaded in the same Pest
    // process (confirmed: it broke SectionInitializerTest's rootPath
    // assertions, since define() guards mean whichever file's block runs
    // first wins for the whole test run).
    if (! defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', './');
    }
    if (! defined('PHPWG_PLUGINS_PATH')) {
        define('PHPWG_PLUGINS_PATH', PHPWG_ROOT_PATH . 'plugins/');
    }

    // pwg_activity() calls the real, stable, already-migrated
    // script_basename() (needs full $_SERVER-driven bootstrap this isolated
    // integration test doesn't load). Copied verbatim from
    // ActivityServiceTest.php's own identical stub -- per that file's own
    // docblock, every Integration test file that needs this function must
    // declare an identical body, since function_exists() guards mean
    // whichever loads first wins for the whole test run.
    if (! function_exists('script_basename')) {
        function script_basename(): string
        {
            /** @var array<string, mixed> $conf */
            global $conf;

            foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $key) {
                $raw = $_SERVER[$key] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $filename = strtolower($raw);
                    if ((bool) ($conf['php_extension_in_urls'] ?? false) && get_extension($filename) !== 'php') {
                        continue;
                    }

                    $basename = basename($filename, '.php');
                    if ($basename !== '') {
                        return $basename;
                    }
                }
            }

            return '';
        }
    }

    // ExtensionScanner's real-disk theme scan (missingParentTheme()/
    // getChildrenThemes() scan the actual themes/ directory, which
    // contains a real 'default' theme) calls the real, stable,
    // already-migrated load_language() (needs full i18n bootstrap this
    // isolated integration test doesn't load). Stubbed to always report
    // "no description file found" -- a legitimate real-world outcome the
    // caller (ExtensionScanner::scanTheme()) already falls back from
    // correctly via its own preg_match() branch.
    if (! function_exists('load_language')) {
        /**
         * @param array<string, mixed> $options
         */
        function load_language(string $filename, string $dirname = '', array $options = []): false
        {
            return false;
        }
    }

    // pwg_activity() itself (include/functions.inc.php) isn't loaded by
    // this isolated integration test either -- reimplemented here calling
    // the exact same real ActivityService/ActivityRepository it delegates
    // to, so this suite exercises genuine activity-logging behavior (the
    // whole point of test_language_actions_never_log_activity()/
    // test_plugin_actions_do_log_activity() below) rather than a mock.
    if (! function_exists('pwg_activity')) {
        /**
         * @param array<int, int|string>|int|string $objectId
         * @param array<string, mixed> $details
         */
        function pwg_activity(string $object, array|int|string $objectId, string $action, array $details = []): void
        {
            new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))
                ->record($object, $objectId, $action, $details);
        }
    }

    // get_default_theme() (include/functions_user.inc.php) cascades through
    // get_default_user_value()/check_theme_installed()/get_pwg_themes() --
    // real DB+filesystem logic that (verified directly against this exact
    // fixture: piwigo_themes starts empty, the guest user's theme='modus'
    // has no real on-disk 'modus' directory) always resolves to the
    // 'default' fallback here. Simplified fixed-value stand-in, same
    // precedent as SearchServiceTest.php's identical-shaped
    // get_default_language() stub.
    if (! function_exists('get_default_theme')) {
        function get_default_theme(): string
        {
            return 'default';
        }
    }

    // get_default_language() already has an established shared stub
    // (SearchServiceTest.php, fixed 'en') -- tests below that need to
    // exercise the "is this the default language" guard use language id
    // 'en' (not the fixture's real active en_UK row) to match it.
    if (! function_exists('get_default_language')) {
        function get_default_language(): string
        {
            return 'en';
        }
    }

    // ExtensionScanner's real-disk theme scan falls back to
    // userprefs_get_param('admin_theme', 'clear') when a scanned theme
    // (the real on-disk 'default' theme, here) has no screenshot.png --
    // reimplemented using the exact real underlying service, same pattern
    // as pwg_activity() above.
    if (! function_exists('userprefs_get_param')) {
        function userprefs_get_param(string $param, mixed $defaultValue = null): mixed
        {
            return new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()))
                ->getParam($param, $defaultValue);
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Admin\Extensions\ExtensionLifecycle;
    use Piwigo\Admin\Extensions\ExtensionRepository;
    use Piwigo\Admin\Extensions\ExtensionType;
    use Piwigo\Admin\Extensions\PemCatalog;
    use Piwigo\Admin\Extensions\ZipExtractor;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;

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

            Config::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $this->conn = DbConnection::build();
            $this->repo = new ExtensionRepository($this->conn);
            $this->lifecycle = new ExtensionLifecycle($this->repo, new PemCatalog(new ZipExtractor()));

            $GLOBALS['conf'] = [
                'enable_extensions_install' => true,
                'php_extension_in_urls' => false,
            ];
            $GLOBALS['user'] = ['id' => 1];
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
            // conf_update_param('mobile_theme', $id) (called by a
            // successful mobile-theme activate) is deliberately called
            // WITHOUT updateGlobal=true, matching themes.class.php's own
            // exact call shape -- it persists to the DB but never updates
            // the live $conf array. This is a real, faithfully-preserved
            // legacy quirk: the mobile-theme-uniqueness guard only actually
            // takes effect on the NEXT request, once $conf gets freshly
            // reloaded from the DB at bootstrap -- never within the same
            // request/process that just activated the first mobile theme.
            // Simulate that "next request" state directly rather than
            // asserting a same-process guard that legacy code itself never
            // provides.
            $first = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $first, [
                'version' => '1.0',
                'name' => 'Mobile One',
                'mobile' => true,
            ]);
            $GLOBALS['conf'] = [
                'enable_extensions_install' => true,
                'php_extension_in_urls' => false,
                'mobile_theme' => $first,
            ];

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
            // get_default_language() is stubbed to a fixed 'en' (see the
            // shared stub above) rather than reading the fixture's real
            // active 'en_UK' row -- activate a language literally named
            // 'en' so this test exercises the real guard condition
            // ($id === get_default_language()) instead of asserting
            // against a value the stub can never actually produce.
            $this->lifecycle->performAction(ExtensionType::Language, 'activate', 'en', ['version' => '1.0', 'name' => 'English']);

            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'deactivate', 'en', null);

            self::assertSame(['CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE'], $errors);
            self::assertNotNull($this->repo->find(ExtensionType::Language, 'en'));
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
