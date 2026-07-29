<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Admin\Extensions\ExtensionLifecycle;
    use Piwigo\Admin\Extensions\ExtensionRepository;
    use Piwigo\Admin\Extensions\ExtensionType;
    use Piwigo\Admin\Extensions\PemCatalog;
    use Piwigo\Admin\Extensions\PluginMigrationEntity;
    use Piwigo\Admin\Extensions\PluginMigrationRepository;
    use Piwigo\Admin\Extensions\ZipExtractor;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Core\Kernel;
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

        private PluginMigrationRepository $pluginMigrationRepo;

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

            // DI-phase follow-on to gap-closure Stage 4: ExtensionLifecycle
            // now resolves MailService/HtmlService via
            // Bootstrap\PresentationAccessor -> Kernel::container(), which
            // this isolated Integration test (no full RequestBootstrap)
            // wouldn't otherwise boot.
            Kernel::boot();

            $this->conn = DbConnection::build();
            $this->repo = new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn));
            $pluginMigrationRepo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(PluginMigrationEntity::class);
            self::assertInstanceOf(PluginMigrationRepository::class, $pluginMigrationRepo);
            $this->pluginMigrationRepo = $pluginMigrationRepo;
            $this->lifecycle = new ExtensionLifecycle($this->repo, new PemCatalog(new ZipExtractor()), new UrlService(new HtmlService()), new ConfigService($this->buildConfigRepository()), $this->pluginMigrationRepo);

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
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET theme = 'default' WHERE user_id IN (1, 2)");
            $this->conn->executeStatement('DELETE FROM ' . Tables::activity());
            $this->conn->executeStatement('DELETE FROM ' . Tables::pluginMigrations());
            Kernel::reset();
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
            self::assertSame(['1.0'], $this->findMigrationVersions($id), 'a successful install must record a plugin_migrations row');
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
            // Adversarially-motivated: 'restore' runs 'uninstall' (deletes
            // the plugins row) then 'activate', which -- since dbRow is now
            // null -- re-invokes 'install' at the SAME version. That's a
            // real, expected re-insert into plugin_migrations' own
            // composite (plugin_id, version) PK -- must upsert cleanly, not
            // throw a duplicate-key error.
            $id = $this->pluginId();
            $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

            $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'restore', $id, ['version' => '1.0']);

            self::assertSame([], $errors);
            $row = $this->repo->find(ExtensionType::Plugin, $id);
            self::assertNotNull($row);
            self::assertSame('active', $row['state']);
            self::assertSame(['1.0'], $this->findMigrationVersions($id), 'restoring at the same version must upsert the ledger row, not duplicate or fail');
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

        /**
         * @return list<string>
         */
        private function findMigrationVersions(string $pluginId): array
        {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT version FROM ' . Tables::pluginMigrations() . ' WHERE plugin_id = ?',
                [$pluginId]
            );

            return array_map(
                static fn (mixed $version): string => is_string($version) ? $version : '',
                array_column($rows, 'version')
            );
        }

        private function themeId(): string
        {
            return 'p17-test-theme-' . bin2hex(random_bytes(4));
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
         * Writes a real plugins/<id>/maintain.<ext> file declaring a
         * global-namespace `<id>_maintain` class (hyphens folded to '_',
         * matching buildPluginMaintain()'s own str_replace) -- $body is
         * spliced in verbatim as the class's method overrides.
         */
        private function writePluginMaintainFile(string $id, string $ext, bool $extendsBase, string $body = ''): void
        {
            $dir = \Piwigo\Admin\PluginLoader::pluginsPath() . $id;
            mkdir($dir, 0o777, true);
            $classname = str_replace('-', '_', $id . '_maintain');
            $extends = $extendsBase ? ' extends \\Piwigo\\Admin\\PluginMaintain' : '';
            file_put_contents(
                $dir . '/maintain.' . $ext,
                "<?php\nclass {$classname}{$extends}\n{\n{$body}\n}\n"
            );
        }

        private function removePluginDir(string $id): void
        {
            $this->rrmdir(\Piwigo\Admin\PluginLoader::pluginsPath() . $id);
        }

        /**
         * Writes a real themes/<id>/admin/maintain.inc.php file declaring a
         * global-namespace `<id>_maintain` class -- buildThemeMaintain()'s
         * classname is the bare theme id with NO hyphen folding, so callers
         * must pass a themeIdNoHyphens()-shaped id.
         */
        private function writeThemeMaintainFile(string $id, bool $extendsBase, string $body = ''): void
        {
            $dir = \Piwigo\Core\CurrentPaths::get()->root . 'themes/' . $id . '/admin';
            mkdir($dir, 0o777, true);
            $classname = $id . '_maintain';
            $extends = $extendsBase ? ' extends \\Piwigo\\Admin\\ThemeMaintain' : '';
            file_put_contents(
                $dir . '/maintain.inc.php',
                "<?php\nclass {$classname}{$extends}\n{\n{$body}\n}\n"
            );
        }

        /**
         * Writes a real themes/<id>/themeconf.inc.php -- the file
         * ExtensionScanner::scanTheme()/ThemeCatalog::checkThemeInstalled()
         * both genuinely check for on disk, no fake-able seam.
         *
         * @param array{name?: string, parent?: string, mobile?: bool} $conf
         */
        private function writeThemeConf(string $id, array $conf = []): void
        {
            $dir = \Piwigo\Core\CurrentPaths::get()->root . 'themes/' . $id;
            mkdir($dir, 0o777, true);
            $name = $conf['name'] ?? $id;
            $lines = "<?php\n/*\nTheme Name: {$name}\nVersion: 1.0\n*/\n";
            if (isset($conf['parent'])) {
                $lines .= "\$theme_conf['parent'] = '{$conf['parent']}';\n";
            }
            if (isset($conf['mobile']) && $conf['mobile']) {
                $lines .= "\$theme_conf['mobile'] = true;\n";
            }
            file_put_contents($dir . '/themeconf.inc.php', $lines);
        }

        private function removeThemeDir(string $id): void
        {
            $this->rrmdir(\Piwigo\Core\CurrentPaths::get()->root . 'themes/' . $id);
        }

        // --------------------------------------------- plugin update/errors

        public function test_plugin_update_without_a_revision_option_throws(): void
        {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage("performPluginAction('update'): missing 'revision' option");

            $this->lifecycle->performAction(ExtensionType::Plugin, 'update', $this->pluginId(), ['version' => '1.0'], []);
        }

        // The 'update' action's real extraction-succeeds branch
        // (ExtensionLifecycle.php lines ~163-177: rescanning the plugin,
        // calling PluginMaintain::update(), bumping the stored version) is
        // gated entirely behind PemCatalog::extractArchive(), which itself
        // calls the static, non-injectable HttpClientService::fetchToFile()
        // against the real piwigo.org PEM server -- PemCatalog is `final
        // readonly` (no interface, no fake-able seam) and is already on this
        // effort's own documented skip list (see UploadServiceTest and
        // PemCatalogTest's siblings). In this environment extractArchive()
        // always returns a non-'ok' status, so only the ELSE branch
        // (activityDetails['result'] = 'error') is reachable -- already
        // covered indirectly by test_plugin_update_without_a_revision_option_throws's
        // sibling network-failure path in the wider suite. Not chased further.

        public function test_plugin_install_failure_marks_activity_as_error_and_does_not_insert_a_row(): void
        {
            $id = $this->pluginId();
            $this->writePluginMaintainFile($id, 'class.php', extendsBase: true, body: <<<'PHP'
    public function install($plugin_version, &$errors = [])
    {
        $errors[] = 'forced install failure';
    }
PHP);

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'install', $id, ['version' => '1.0']);

                self::assertSame(['forced install failure'], $errors);
                self::assertNull($this->repo->find(ExtensionType::Plugin, $id));
            } finally {
                $this->removePluginDir($id);
            }
        }

        public function test_plugin_activate_failure_marks_activity_as_error_after_a_successful_implicit_install(): void
        {
            $id = $this->pluginId();
            $this->writePluginMaintainFile($id, 'class.php', extendsBase: true, body: <<<'PHP'
    public function install($plugin_version, &$errors = [])
    {
    }

    public function activate($plugin_version, &$errors = [])
    {
        $errors[] = 'forced activate failure';
    }
PHP);

            try {
                $errors = $this->lifecycle->performAction(ExtensionType::Plugin, 'activate', $id, ['version' => '1.0']);

                self::assertSame(['forced activate failure'], $errors);
                // The implicit install() (called first, no errors) DID
                // insert the row -- only the subsequent activate() call
                // failed, so state stays 'inactive', never flipped to
                // 'active'.
                $row = $this->repo->find(ExtensionType::Plugin, $id);
                self::assertNotNull($row);
                self::assertSame('inactive', $row['state']);
            } finally {
                $this->removePluginDir($id);
            }
        }

        // ------------------------------------------- buildPluginMaintain()

        public function test_build_plugin_maintain_loads_a_real_class_php_file(): void
        {
            $id = $this->pluginId();
            $this->writePluginMaintainFile($id, 'class.php', extendsBase: true);

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'buildPluginMaintain');
                $maintain = $method->invoke($this->lifecycle, $id);

                self::assertInstanceOf(\Piwigo\Admin\PluginMaintain::class, $maintain);
                self::assertNotInstanceOf(\Piwigo\Admin\DummyPluginMaintain::class, $maintain);
            } finally {
                $this->removePluginDir($id);
            }
        }

        public function test_build_plugin_maintain_throws_when_the_class_php_class_does_not_extend_plugin_maintain(): void
        {
            $id = $this->pluginId();
            $this->writePluginMaintainFile($id, 'class.php', extendsBase: false);
            $classname = str_replace('-', '_', $id . '_maintain');

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'buildPluginMaintain');

                $this->expectException(\LogicException::class);
                $this->expectExceptionMessage("buildPluginMaintain(): {$classname} does not extend PluginMaintain");

                $method->invoke($this->lifecycle, $id);
            } finally {
                $this->removePluginDir($id);
            }
        }

        public function test_build_plugin_maintain_loads_a_real_inc_php_file(): void
        {
            $id = $this->pluginId();
            $this->writePluginMaintainFile($id, 'inc.php', extendsBase: true);

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'buildPluginMaintain');
                $maintain = $method->invoke($this->lifecycle, $id);

                self::assertInstanceOf(\Piwigo\Admin\PluginMaintain::class, $maintain);
                self::assertNotInstanceOf(\Piwigo\Admin\DummyPluginMaintain::class, $maintain);
            } finally {
                $this->removePluginDir($id);
            }
        }

        public function test_build_plugin_maintain_throws_when_the_inc_php_class_does_not_extend_plugin_maintain(): void
        {
            $id = $this->pluginId();
            $this->writePluginMaintainFile($id, 'inc.php', extendsBase: false);
            $classname = str_replace('-', '_', $id . '_maintain');

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'buildPluginMaintain');

                $this->expectException(\LogicException::class);
                $this->expectExceptionMessage("buildPluginMaintain(): {$classname} does not extend PluginMaintain");

                $method->invoke($this->lifecycle, $id);
            } finally {
                $this->removePluginDir($id);
            }
        }

        // -------------------------------------------- buildThemeMaintain()

        public function test_build_theme_maintain_loads_a_real_maintain_inc_php_file(): void
        {
            $id = $this->themeIdNoHyphens();
            $this->writeThemeMaintainFile($id, extendsBase: true);

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'buildThemeMaintain');
                $maintain = $method->invoke($this->lifecycle, $id);

                self::assertInstanceOf(\Piwigo\Admin\ThemeMaintain::class, $maintain);
                self::assertNotInstanceOf(\Piwigo\Admin\DummyThemeMaintain::class, $maintain);
            } finally {
                $this->removeThemeDir($id);
            }
        }

        public function test_build_theme_maintain_throws_when_the_class_does_not_extend_theme_maintain(): void
        {
            $id = $this->themeIdNoHyphens();
            $this->writeThemeMaintainFile($id, extendsBase: false);

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'buildThemeMaintain');

                $this->expectException(\LogicException::class);
                $this->expectExceptionMessage("buildThemeMaintain(): {$id}_maintain does not extend ThemeMaintain");

                $method->invoke($this->lifecycle, $id);
            } finally {
                $this->removeThemeDir($id);
            }
        }

        // ------------------------------------------------- theme, more

        public function test_theme_deactivate_of_a_never_installed_theme_is_a_silent_noop(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $this->themeId(), null);

            self::assertSame([], $errors);
        }

        public function test_theme_delete_of_a_theme_neither_installed_nor_on_disk_is_a_silent_noop(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'delete', $this->themeId(), null);

            self::assertSame([], $errors);
        }

        public function test_theme_delete_is_blocked_by_a_real_child_theme_depending_on_it(): void
        {
            $parent = $this->themeIdNoHyphens();
            $child = $this->themeIdNoHyphens();
            $this->writeThemeConf($child, ['name' => 'Child Theme', 'parent' => $parent]);

            try {
                self::assertSame(['Child Theme'], $this->lifecycle->getChildrenThemes($parent));

                $errors = $this->lifecycle->performAction(
                    ExtensionType::Theme,
                    'delete',
                    $parent,
                    ['version' => '1.0', 'name' => 'Parent Theme'],
                );

                self::assertCount(1, $errors);
                self::assertStringContainsString('Child Theme', $errors[0]);
                self::assertNull($this->repo->find(ExtensionType::Theme, $parent));
            } finally {
                $this->removeThemeDir($child);
            }
        }

        public function test_missing_parent_theme_recurses_through_a_real_intermediate_theme(): void
        {
            $middle = $this->themeIdNoHyphens();
            $this->writeThemeConf($middle, ['name' => 'Middle Theme', 'parent' => 'totally-missing-ancestor-xyz']);

            try {
                $result = $this->lifecycle->missingParentTheme('leaf-theme-never-on-disk', ['parent' => $middle]);

                self::assertSame('totally-missing-ancestor-xyz', $result);
            } finally {
                $this->removeThemeDir($middle);
            }
        }

        public function test_theme_deactivate_resets_mobile_theme_config_when_deactivating_the_mobile_theme(): void
        {
            $mobile = $this->themeId();
            $other = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $mobile, ['version' => '1.0', 'name' => 'Mobile', 'mobile' => true]);
            CurrentConfig::setEnableExtensionsInstall(true);
            CurrentConfig::setPhpExtensionInUrls(false);
            CurrentConfig::setMobilTheme($mobile);
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $other, ['version' => '1.0', 'name' => 'Other']);

            $errors = $this->lifecycle->performAction(ExtensionType::Theme, 'deactivate', $mobile, ['version' => '1.0', 'name' => 'Mobile', 'mobile' => true]);

            self::assertSame([], $errors);
            $raw = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'mobile_theme'");
            self::assertIsString($raw);
            self::assertSame('', json_decode($raw));

            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'mobile_theme'");
        }

        /**
         * performThemeAction()'s own `$id === getDefaultTheme()` gate
         * (guarding the pickReplacementDefaultTheme()/setDefaultTheme()
         * call) is unreachable through the public performAction() API in
         * this specific Integration harness: ThemeCatalog::
         * checkThemeInstalled() composes `CurrentPaths::get()->root .
         * CurrentConfig::themesDir()`, but this class's own setUp() (line
         * ~79) sets themesDir() to an ALREADY-absolute path (`root .
         * 'themes'`) for a different, unrelated reason (buildThemeMaintain()/
         * ExtensionScanner need the absolute form) -- composing root with an
         * already-absolute themesDir() double-prefixes the path, so
         * checkThemeInstalled() (confirmed live) returns false for every
         * theme id, including 'default' itself. getPwgThemes() is filtered
         * through that same check, so it's always empty, and
         * UserService::getDefaultTheme() always falls through to its own
         * hard 'default' fallback -- which can never match a real
         * performAction()-installed theme id ('default' itself can never
         * get a DB row, since performThemeAction()'s own 'activate' case
         * short-circuits for `$id === 'default'`). Exercising
         * pickReplacementDefaultTheme()/setDefaultTheme() directly (both
         * private, already covered read-only elsewhere in this class) via
         * Reflection instead -- same tactic as buildPluginMaintain()/
         * buildThemeMaintain() above -- is the only way to reach their real
         * bodies at all in this harness.
         */
        public function test_pick_replacement_default_theme_returns_any_other_installed_theme(): void
        {
            $keep = $this->themeId();
            $exclude = $this->themeId();
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $keep, ['version' => '1.0', 'name' => 'Keep']);
            $this->lifecycle->performAction(ExtensionType::Theme, 'activate', $exclude, ['version' => '1.0', 'name' => 'Exclude']);

            $method = new \ReflectionMethod($this->lifecycle, 'pickReplacementDefaultTheme');
            $result = $method->invoke($this->lifecycle, $exclude);

            self::assertSame($keep, $result);
        }

        public function test_pick_replacement_default_theme_falls_back_to_default_when_none_other_exists(): void
        {
            $method = new \ReflectionMethod($this->lifecycle, 'pickReplacementDefaultTheme');
            $result = $method->invoke($this->lifecycle, 'a-theme-id-that-is-not-installed-xyz');

            self::assertSame('default', $result);
        }

        public function test_set_default_theme_reassigns_every_user_on_the_fallback_default_theme(): void
        {
            $new = $this->themeId();

            // See this section's own docblock above: getDefaultTheme()
            // always returns the literal 'default' string in this harness,
            // so setDefaultTheme()'s internal findUserIdsByTheme() call
            // always looks up whoever currently has theme = 'default' --
            // snapshot them all so this test can restore the exact prior
            // state no matter how many rows that is.
            $before = $this->conn->fetchAllAssociative("SELECT user_id, theme FROM " . Tables::userInfos() . " WHERE theme = 'default'");

            try {
                $method = new \ReflectionMethod($this->lifecycle, 'setDefaultTheme');
                $method->invoke($this->lifecycle, $new);

                foreach ($before as $row) {
                    $current = $this->conn->fetchOne('SELECT theme FROM ' . Tables::userInfos() . ' WHERE user_id = ?', [$row['user_id']]);
                    self::assertSame($new, $current);
                }
                // defaultUserId()/guestId() (both user_id 2 in this
                // environment) are unconditionally folded into $userIds --
                // still true even for a user who happened to already be on
                // 'default' (already asserted above for user 2), so this
                // only adds real signal when user 2 *wasn't* already in
                // $before; assert it regardless for a stable, exact check.
                $guestTheme = $this->conn->fetchOne('SELECT theme FROM ' . Tables::userInfos() . ' WHERE user_id = 2');
                self::assertSame($new, $guestTheme);
            } finally {
                foreach ($before as $row) {
                    $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . ' SET theme = ? WHERE user_id = ?', [$row['theme'], $row['user_id']]);
                }
            }
        }

        // ---------------------------------------------------- language, more

        public function test_language_delete_of_a_never_activated_but_on_disk_language_succeeds(): void
        {
            $errors = $this->lifecycle->performAction(
                ExtensionType::Language,
                'delete',
                'never-activated-on-disk-xyz',
                ['version' => '1.0', 'name' => 'On Disk Lang'],
            );

            self::assertSame([], $errors);
        }

        public function test_language_set_default_reassigns_the_default_and_guest_users(): void
        {
            $errors = $this->lifecycle->performAction(ExtensionType::Language, 'set_default', 'xx_ZZ', null);

            self::assertSame([], $errors);
            $row = $this->conn->fetchAssociative('SELECT language FROM ' . Tables::userInfos() . ' WHERE user_id = 2');
            self::assertIsArray($row);
            self::assertSame('xx_ZZ', $row['language']);

            $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'en_UK' WHERE user_id = 2");
        }
    }
}
