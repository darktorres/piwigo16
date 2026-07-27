<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\Install\InstallWizard;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;

/**
 * InstallWizard is install.php's whole orchestration, ported verbatim from
 * that script's former top-level code (see its own docblock). Every test
 * here runs the wizard for real -- a real Kernel-booted container, a real
 * Doctrine connection, real schema creation from install/piwigo_structure-
 * mysql.sql + install/config.sql -- against completely disposable state:
 *
 *  - Paths::root is a throwaway directory under sys_get_temp_dir(), never
 *    the real repo root or the shared dev box's real local/.installed(.test)
 *    sentinel. install/, themes/, plugins/, language/ are symlinked in
 *    read-only from the real repo (InstallWizard needs the real admin
 *    theme's Smarty templates + the real install/*.sql files + the real
 *    bundled languages to behave authentically), but every WRITE path
 *    (.env(.test), local/config/database.inc.php, the install stamp,
 *    _data/, Smarty's compile dir) lands only inside this throwaway root.
 *  - The database submitted through the (simulated) install form is a
 *    brand-new, uniquely-named database this test creates and drops itself,
 *    never the shared PIWIGO_DB_BASE Integration-test database every other
 *    Integration test file assumes is already fixture-loaded.
 *
 * DbCredentials::seed()/reset() and $_GET/$_POST/$_SERVER mutations are
 * real process-wide/superglobal state -- every test snapshots and restores
 * them, the same reasoning as InstallServiceTest's own $originalDbEnv.
 * Likewise, this whole test *process* runs with $_SERVER['HTTP_X_PIWIGO_ENV']
 * = 'test' set globally by tests/bootstrap.php, so Env::testModeIsActive()
 * is always true here -- performInstall()'s env file write always lands on
 * '.env.test' (never plain '.env'), and its "also write legacy
 * database.inc.php in prod mode" branch is provably unreachable in this
 * process; the dedicated prod-mode test below works around that by
 * temporarily unsetting that one $_SERVER key for the single call that
 * needs real prod-mode behavior, then restoring it immediately after.
 *
 * render() itself is only exercised for step 1 (the initial form, before
 * any submission) -- real, low-risk, and it's the exact page the disabled
 * tests/Browser/InstallTest.php's first assertions cover. Step 2's
 * rendering (post-install session/activity-log/mail sequence) is NOT
 * exercised here: it needs a real PHP session lifecycle and
 * UrlService request-derived URL generation, and (unless carefully
 * disabled) a real outbound mail/HTTP newsletter call -- genuinely the
 * territory of a full install.php HTTP request, which is exactly the gap
 * tests/Browser/InstallTest.php exists for (see that file's own extensive
 * docblock on just how many real, non-obvious issues that exact rendering
 * path surfaced, including a request thread hanging on a real sendmail
 * invocation). Every DB row and file *that step's own logic doesn't touch*
 * (webmaster/guest users, sites, config, activated language, the written
 * .env/database.inc.php/install stamp) is already fully covered below via
 * performInstall() directly.
 */
final class InstallWizardTest extends IntegrationTestCase
{
    private string $tempRoot;

    private Paths $paths;

    /**
     * @var array<string, string>
     */
    private array $originalDbEnv = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $originalGet = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $originalPost = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $originalServer = [];

    private bool $installBootstrapBooted = false;

    /**
     * @var list<string> extra databases this test created, dropped in tearDown()
     */
    private array $createdDatabases = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalServer = $_SERVER;

        $this->tempRoot = sys_get_temp_dir() . '/piwigo-install-wizard-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->tempRoot, 0777, true);
        $repoRoot = dirname(__DIR__, 2);
        symlink($repoRoot . '/install', $this->tempRoot . 'install');
        symlink($repoRoot . '/themes', $this->tempRoot . 'themes');
        symlink($repoRoot . '/plugins', $this->tempRoot . 'plugins');
        symlink($repoRoot . '/language', $this->tempRoot . 'language');
        mkdir($this->tempRoot . 'local/config', 0777, true);
        // Present-but-empty, not absent -- see LegacyFileConfTest's own
        // comment: a genuinely missing config.inc.php makes read()'s
        // `@include` raise an E_WARNING that PHPUnit's failOnWarning="true"
        // still catches despite the `@`, for every test that doesn't
        // specifically want to exercise that missing-file branch itself.
        file_put_contents($this->tempRoot . 'local/config/config.inc.php', "<?php\n// no overrides\n");
        $this->paths = Paths::fromRoot($this->tempRoot);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_SERVER = $this->originalServer;
        DbCredentials::seed($this->originalDbEnv);
        DbCredentials::reset();
        if ($this->installBootstrapBooted) {
            restore_error_handler();
        }
        ErrorCollector::reset();
        Kernel::reset();
        CurrentConfig::reset();
        foreach ($this->createdDatabases as $dbName) {
            $db = $this->newMysqli('');
            $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $dbName));
            $db->close();
        }
        $this->removeTree($this->tempRoot);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (is_file($dir)) {
            unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($dir . '/' . $item);
        }
        rmdir($dir);
    }

    private function createFreshDatabase(): string
    {
        $name = 'piwigo_installwizard_' . bin2hex(random_bytes(5));
        $db = $this->newMysqli('');
        self::assertSame(0, $db->connect_errno, $db->connect_error ?? '');
        self::assertTrue($db->query(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $name)));
        $db->close();
        $this->createdDatabases[] = $name;

        return $name;
    }

    private function bootInstallBootstrap(): void
    {
        InstallBootstrap::boot($this->paths);
        $this->installBootstrapBooted = true;
    }

    /**
     * Mirrors public/install.php's own real bootstrap sequence exactly (not
     * just InstallWizard's constructor+boot()): that entry shell seeds
     * PIWIGO_DB_PREFIX itself before constructing the wizard (InstallWizard::
     * boot() only ever seeds host/user/password/dbname -- Tables::*()'s own
     * DbCredentials::current()->prefix read would otherwise silently keep
     * resolving to whatever prefix was already ambient in this process, not
     * the one this test actually wants every table named with), and sets
     * Piwigo\Template\ScriptLoader's static URL service (a "pre-existing
     * gap" the entry shell's own docblock already documents: nothing inside
     * InstallWizard/InstallBootstrap does this, since install.php never runs
     * RequestBootstrap::configure(), the only other real caller).
     *
     * @param array<string, string> $post
     * @param array<string, string> $get
     */
    private function submit(array $post, array $get = [], string $prefix = 'itest_'): InstallWizard
    {
        $_GET = $get;
        $_POST = $post;
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        // Deterministic PIWIGO_BASE_URL computation (performInstall()'s own
        // test-mode-only block) -- fixed host/script/scheme so that value
        // is exactly assertable rather than depending on this CLI process's
        // own ambient (and here, absent) HTTP_HOST/SCRIPT_NAME/HTTPS.
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['SCRIPT_NAME'] = '/install.php';
        unset($_SERVER['HTTPS']);

        DbCredentials::seed([
            'PIWIGO_DB_PREFIX' => $prefix,
        ]);
        \Piwigo\Template\ScriptLoader::setUrlService(new \Piwigo\Url\UrlService(new \Piwigo\Html\HtmlService()));

        $wizard = new InstallWizard($prefix, $this->paths);
        $wizard->boot();

        return $wizard;
    }

    private function reflectPrivate(object $object, string $property): mixed
    {
        return new \ReflectionProperty($object, $property)->getValue($object);
    }

    /** Joins InstallWizard::$errors (a list<string>, reflected) into one message for a failed assertion. */
    private function reflectErrorsJoined(object $wizard): string
    {
        $errors = $this->reflectPrivate($wizard, 'errors');
        self::assertIsArray($errors);

        return implode('; ', array_map(static fn (mixed $e): string => is_string($e) ? $e : '', $errors));
    }

    private function queryOne(string $dbName, string $sql): mixed
    {
        $db = $this->newMysqli($dbName);
        self::assertSame(0, $db->connect_errno, $db->connect_error ?? '');
        $result = $db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $db->close();

        return $row;
    }

    /** Runs a `SELECT COUNT(*) AS c ...` query and returns the count as a real int. */
    private function queryOneCount(string $dbName, string $sql): int
    {
        $row = $this->queryOne($dbName, $sql);
        self::assertIsArray($row);
        $count = $row['c'];
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    // ------------------------------------------------------------- constructor

    public function test_constructor_reads_the_default_data_location_when_the_local_override_sets_nothing(): void
    {
        CurrentPaths::set($this->paths);

        $wizard = new InstallWizard('itest_', $this->paths);

        self::assertSame('_data/', $this->reflectPrivate($wizard, 'confDataLocation'));
    }

    public function test_constructor_throws_when_a_local_override_sets_a_non_string_data_location(): void
    {
        CurrentPaths::set($this->paths);
        file_put_contents($this->paths->local . 'config/config.inc.php', "<?php\n\$conf['data_location'] = 12345;\n");

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Invalid \$conf['data_location'] configuration: expected a string.");

        new InstallWizard('itest_', $this->paths);
    }

    // --------------------------------------------------------- analyzeForm()

    public function test_analyzeForm_collects_every_real_validation_error_while_the_connection_itself_succeeds(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
            'admin_name' => '',
            'admin_pass1' => 'first-password',
            'admin_pass2' => 'a-totally-different-password',
            'admin_mail' => '',
            'install' => '1',
        ]);

        $wizard->analyzeForm();

        self::assertTrue($wizard->hasErrors());
        self::assertSame(
            [
                'please enter the webmaster username',
                'please enter your password again',
                'mail address must be like xxx@yyy.eee (example: jack@altern.org)',
            ],
            $this->reflectPrivate($wizard, 'errors')
        );
        // The connection attempt itself (a valid, reachable DB) succeeded
        // independently of the 3 field-validation errors above -- proves
        // those errors come from the field checks, not a connection failure
        // masquerading as 3 separate messages.
        self::assertNotNull($this->reflectPrivate($wizard, 'conn'));
    }

    public function test_analyzeForm_rejects_a_table_prefix_starting_with_a_digit(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
            'admin_name' => 'somebody',
            'admin_pass1' => 'same-password',
            'admin_pass2' => 'same-password',
            'admin_mail' => 'somebody@example.test',
        ], [], '1bad');

        $wizard->analyzeForm();

        // Every other field here is deliberately valid (real DB
        // credentials, matching passwords, a well-formed mail address) --
        // an exact single-element list proves the prefix check is the only
        // thing that fired, not merely that it fired somewhere among
        // other, unrelated errors a looser assertContains() would tolerate.
        self::assertSame(['invalid table prefix'], $this->reflectPrivate($wizard, 'errors'));
    }

    // ------------------------------------------------------------- render() (step 1)

    public function test_render_outputs_the_initial_install_form_with_the_submitted_field_values_prefilled(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => 'submitted-host',
            'dbuser' => 'submitted-user',
            'dbname' => 'submitted-db',
        ]);

        ob_start();
        $wizard->render();
        $output = ob_get_clean();

        self::assertIsString($output);
        self::assertStringContainsString('Installation', $output);
        self::assertStringContainsString('name="dbhost"', $output);
        self::assertStringContainsString('value="submitted-host"', $output);
        self::assertStringContainsString('value="submitted-user"', $output);
        self::assertStringContainsString('value="submitted-db"', $output);
        // Step 1 never shows the post-install congratulations text.
        self::assertStringNotContainsString('Congratulations', $output);
    }

    // ------------------------------------------------------------ performInstall()

    public function test_performInstall_creates_the_real_schema_webmaster_user_and_site_config(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $freshDb,
            'admin_name' => 'p17setup',
            'admin_pass1' => 'Sup3r-Secret-99!',
            'admin_pass2' => 'Sup3r-Secret-99!',
            'admin_mail' => 'webmaster@example.test',
            'install' => '1',
        ]);

        $wizard->analyzeForm();
        self::assertFalse($wizard->hasErrors(), 'unexpected validation/connection errors: ' . $this->reflectErrorsJoined($wizard));

        $wizard->performInstall();

        // ---- webmaster + guest users -----------------------------------
        $webmaster = $this->queryOne($freshDb, 'SELECT id, username, password, mail_address FROM itest_users WHERE id = 1');
        self::assertIsArray($webmaster);
        self::assertSame('p17setup', $webmaster['username']);
        self::assertSame('webmaster@example.test', $webmaster['mail_address']);
        self::assertIsString($webmaster['password']);
        self::assertTrue(
            new PasswordService(new PasswordRepository(DbConnection::build()))->verify('Sup3r-Secret-99!', $webmaster['password']),
            'the stored hash must verify against the exact submitted password'
        );

        $guest = $this->queryOne($freshDb, 'SELECT id, username, password, mail_address FROM itest_users WHERE id = 2');
        self::assertIsArray($guest);
        self::assertSame('guest', $guest['username']);
        self::assertNull($guest['password']);
        self::assertNull($guest['mail_address']);

        // ---- user_infos: webmaster/guest status + language -------------
        $webmasterInfo = $this->queryOne($freshDb, 'SELECT status, language FROM itest_user_infos WHERE user_id = 1');
        self::assertIsArray($webmasterInfo);
        self::assertSame('webmaster', $webmasterInfo['status']);
        self::assertSame('en_UK', $webmasterInfo['language']);

        $guestInfo = $this->queryOne($freshDb, 'SELECT status, language FROM itest_user_infos WHERE user_id = 2');
        self::assertIsArray($guestInfo);
        self::assertSame('guest', $guestInfo['status']);

        // ---- sites -------------------------------------------------------
        $site = $this->queryOne($freshDb, 'SELECT id, galleries_url FROM itest_sites WHERE id = 1');
        self::assertIsArray($site);
        self::assertSame($this->tempRoot . 'galleries/', $site['galleries_url']);

        // ---- config: secret_key + gallery_title + page_banner -----------
        $secretKey = $this->queryOne($freshDb, "SELECT value FROM itest_config WHERE param = 'secret_key'");
        self::assertIsArray($secretKey);
        self::assertIsString($secretKey['value']);
        $decodedSecret = json_decode($secretKey['value'], true);
        self::assertIsString($decodedSecret);
        self::assertSame(40, strlen($decodedSecret), 'secret_key must be a sha1 hex digest');

        $galleryTitle = $this->queryOne($freshDb, "SELECT value FROM itest_config WHERE param = 'gallery_title'");
        self::assertIsArray($galleryTitle);
        self::assertSame('"Just another Piwigo gallery"', $galleryTitle['value']);

        // ---- languages: only en_UK activated -----------------------------
        $language = $this->queryOne($freshDb, "SELECT id, name FROM itest_languages WHERE id = 'en_UK'");
        self::assertIsArray($language);
        self::assertSame('English (Great Britain)', $language['name']);
        self::assertSame(1, $this->queryOneCount($freshDb, 'SELECT COUNT(*) AS c FROM itest_languages'));

        // ---- themes/plugins: this repo's real themes/ (symlinked in above)
        // only bundles the 'default' placeholder theme so far (the real
        // selectable core themes, e.g. modus, aren't ported into this repo
        // yet -- see ExtensionType::defaultIds()) -- and 'default' is
        // deliberately not selectable/activatable (ExtensionLifecycle::
        // performThemeAction()'s own `$id === 'default'` no-op guard, see
        // ExtensionLifecycleTest's test_theme_activate_default_is_a_silent_noop),
        // so activateCoreThemes() activates zero themes; zero plugins are
        // auto-activated by design too (see InstallServiceTest's own
        // equivalent assertions) -------------------
        self::assertSame(0, $this->queryOneCount($freshDb, 'SELECT COUNT(*) AS c FROM itest_themes'));
        self::assertSame(0, $this->queryOneCount($freshDb, 'SELECT COUNT(*) AS c FROM itest_plugins'));

        // ---- .env.test (this whole process runs with test mode active) --
        $envFile = $this->tempRoot . '.env.test';
        self::assertFileExists($envFile);
        $envContent = file_get_contents($envFile);
        self::assertIsString($envContent);
        self::assertStringContainsString('PIWIGO_DB_HOST=' . $this->dbHost, $envContent);
        self::assertStringContainsString('PIWIGO_DB_USER=' . $this->dbUser, $envContent);
        self::assertStringContainsString('PIWIGO_DB_BASE=' . $freshDb, $envContent);
        self::assertStringContainsString('PIWIGO_DB_PREFIX=itest_', $envContent);
        self::assertStringContainsString('PIWIGO_BASE_URL=http://example.test', $envContent);

        // ---- legacy database.inc.php is skipped while test mode is active
        self::assertFileDoesNotExist($this->paths->siteLocal . 'config/database.inc.php');

        // ---- install stamp ------------------------------------------------
        self::assertFileExists($this->paths->siteLocal . Env::testModeInstalledStamp());
    }

    public function test_performInstall_writes_the_legacy_database_inc_php_file_outside_test_mode(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $freshDb,
            'admin_name' => 'p17setup2',
            'admin_pass1' => 'Anoth3r-Secret-77!',
            'admin_pass2' => 'Anoth3r-Secret-77!',
            'admin_mail' => 'webmaster2@example.test',
            'install' => '1',
        ]);
        $wizard->analyzeForm();
        self::assertFalse($wizard->hasErrors(), 'unexpected validation/connection errors: ' . $this->reflectErrorsJoined($wizard));

        // Temporarily leave test mode for this one call -- performInstall()
        // gates the legacy-config-file write on `! Env::testModeIsActive()`,
        // which (see this class's own docblock) is otherwise always true in
        // this CLI process.
        $savedHeader = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
        unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        // Regression test for a fixed bug, found via this exact test,
        // confirmed live (not assumed): performInstall()'s own
        // legacy-config-file branch used to call a bare `@umask(0111);`
        // and never restore the process's original umask afterwards --
        // since umask() is process-wide, that leaked into every later
        // mkdir()/fopen() for the rest of this PHP process (or, in
        // production, the rest of that worker's lifetime under FrankenPHP/
        // PHP-FPM). Confirmed concretely: running this one test before
        // LegacyFileConfTest in the same process made every one of that
        // file's own `mkdir(..., 0777, true)` calls silently produce
        // directories with mode 0666 (no execute bit) instead of 0777,
        // which then made every `file_put_contents()` into them fail
        // silently (no exception -- just a `false` return nobody checked),
        // so LegacyFileConf::read()'s own `@include` had nothing to include
        // and kept returning unmodified defaults. Fixed by saving/restoring
        // the umask around just the config-file-write block in the source
        // itself -- asserted directly below, not just guarded against here.
        $originalUmask = umask();
        try {
            $wizard->performInstall();
            self::assertSame(
                $originalUmask,
                umask(),
                'performInstall() must restore the process umask it temporarily changes for the legacy config-file write'
            );
        } finally {
            umask($originalUmask);
            if ($savedHeader !== null) {
                $_SERVER['HTTP_X_PIWIGO_ENV'] = $savedHeader;
            }
        }

        $configFile = $this->paths->siteLocal . 'config/database.inc.php';
        self::assertFileExists($configFile);
        $content = file_get_contents($configFile);
        self::assertIsString($content);
        self::assertStringContainsString("\$conf['db_base'] = '" . $freshDb . "';", $content);
        self::assertStringContainsString("\$conf['db_user'] = '" . $this->dbUser . "';", $content);
        self::assertStringContainsString("\$conf['db_host'] = '" . $this->dbHost . "';", $content);
        self::assertStringContainsString("\$prefixeTable = 'itest_';", $content);
        self::assertStringContainsString("define('PHPWG_INSTALLED', true);", $content);

        // Plain '.env' this time, not '.env.test' -- the same env-write
        // block ran, but Env::testModeEnvFile() resolved differently while
        // the header was unset.
        self::assertFileExists($this->tempRoot . '.env');
    }
}
