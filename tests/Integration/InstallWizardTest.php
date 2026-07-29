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
use Piwigo\Http\ResponseReadyException;
use Piwigo\Session\SessionService;

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
 * render() itself is fully exercised for step 1 (the initial form, before
 * any submission) -- real, low-risk, and it's the exact page the disabled
 * tests/Browser/InstallTest.php's first assertions cover. Step 2's full
 * happy-path rendering (the real session_start()/AuthService::logUser()
 * cookie lifecycle actually taking effect, real UrlService request-derived
 * URL generation, a real outbound mail/HTTP newsletter call actually
 * succeeding) is still NOT exercised here -- genuinely the territory of a
 * full install.php HTTP request, which is exactly the gap
 * tests/Browser/InstallTest.php exists for (see that file's own extensive
 * docblock on just how many real, non-obvious issues that exact rendering
 * path surfaced, including a request thread hanging on a real sendmail
 * invocation once send_credentials_by_mail is left checked). Two of step
 * 2's own branches ARE exercised below though (the
 * isSendCredentialsByMail/isNewsletterSubscribe conditionals), by forcing
 * each one's real outbound call to fail fast and deterministically instead
 * of hanging or reaching a live host: a real closed local port
 * (smtp_host=127.0.0.1:1, the same trick MailServiceTest already
 * establishes) for the mail branch, and AppInfo::URL's own
 * 'upstream.example.invalid' domain (RFC 2606 -- guaranteed to never
 * resolve, confirmed empirically to fail HttpClientService::fetch()'s own
 * SSRF-guard host check in ~10ms with no network egress at all, not just a
 * slow/blocked one) for the newsletter branch. Calling render() this far
 * also reaches AuthService::logUser()'s own unconditional setcookie() and
 * session_start() calls, which -- confirmed empirically -- both emit a
 * real E_WARNING("headers already sent") once Pest's own console output
 * has already occurred earlier in this shared CLI process (the same
 * CLI-SAPI limitation tests/Unit/Http/Middleware/SessionMiddlewareTest.php's
 * own docblock documents for session_start() alone); neither warning is a
 * real application bug (a genuine HTTP response hasn't sent headers yet
 * when this code runs for real), so both new tests below suppress
 * warnings for the render() call the same way MailServiceTest's own
 * suppressMailerWarning() does, just over that call's necessarily wider
 * span (no seam exists to isolate just the session/cookie/mail primitives
 * from the rest of the method). Every DB row and file *step 1/2's shared
 * logic doesn't touch* (webmaster/guest users, sites, config, activated
 * language, the written .env/database.inc.php/install stamp) is already
 * fully covered below via performInstall() directly.
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
        // render()'s step-2 body (via AuthService::logUser()'s
        // session_start()/session_regenerate_id()) is the only thing in
        // this file that ever touches SessionService -- its self-managed
        // singleton (see SessionService::get()'s own docblock) otherwise
        // survives into the next test still bound to this test's own
        // throwaway database connection, dropped a few lines below. Reset
        // before the drop so a later test starts from a clean, un-memoized
        // state instead of resolving a stale connection.
        SessionService::reset();
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
    private function submit(array $post, array $get = [], string $prefix = 'itest_', bool $preserveAcceptLanguage = false): InstallWizard
    {
        $_GET = $get;
        $_POST = $post;
        if (! $preserveAcceptLanguage) {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }
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

    // performInstall()'s OWN config-write-failure fallback (secureDirectory()
    // + a tmp pwg_<hash> file under _data/, template 'config_creation_failed'
    // flag) is deliberately not chased here: it needs the *prod-mode* legacy
    // database.inc.php write (already isolated to one test above, which
    // temporarily unsets the test-mode header) to ALSO fail its own
    // fopen($this->configFile, 'w'), which needs local/config/ itself made
    // unwritable at exactly that moment -- stacking that on top of the
    // already-delicate umask/header save-restore dance above, for a
    // secondary fallback-of-a-fallback path, was judged not worth the added
    // fragility risk versus this batch's remaining scope.

    public function test_performInstall_records_an_error_when_the_env_file_cannot_be_written(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $freshDb,
            'admin_name' => 'p17setup3',
            'admin_pass1' => 'Env-Write-Fails-1!',
            'admin_pass2' => 'Env-Write-Fails-1!',
            'admin_mail' => 'webmaster3@example.test',
            'install' => '1',
        ]);
        $wizard->analyzeForm();
        self::assertFalse($wizard->hasErrors(), 'unexpected validation/connection errors: ' . $this->reflectErrorsJoined($wizard));

        // Env::mergeIntoEnvFile() writes directly into $this->tempRoot
        // (creating a brand-new '.env.test' entry there) -- a read-only
        // root blocks exactly that one write (mkdir()/fopen() into
        // already-existing subdirectories like local/config/ still succeed,
        // since directory write permission is checked on the immediate
        // parent only, not inherited from an ancestor), without touching
        // any of the DB-backed schema/user/site creation performInstall()
        // does afterwards.
        chmod($this->tempRoot, 0o555);
        try {
            $wizard->performInstall();
        } finally {
            chmod($this->tempRoot, 0o777);
        }

        self::assertStringContainsString('Could not write', $this->reflectErrorsJoined($wizard));
    }

    // ------------------------------------------------------------- boot()

    public function test_boot_fatals_when_the_install_stamp_already_exists(): void
    {
        $this->bootInstallBootstrap();
        touch($this->paths->siteLocal . Env::testModeInstalledStamp());

        $this->expectException(ResponseReadyException::class);

        $this->submit([], []);
    }

    public function test_boot_falls_back_to_the_default_language_for_an_unrecognized_requested_language(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([], ['language' => 'totally-bogus-language-xyz']);

        self::assertSame(\Piwigo\Core\AppInfo::DEFAULT_LANGUAGE, $this->reflectPrivate($wizard, 'language'));
    }

    public function test_boot_picks_the_browser_language_when_none_was_requested_and_it_is_bundled(): void
    {
        $this->bootInstallBootstrap();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';

        $wizard = $this->submit([], [], preserveAcceptLanguage: true);

        self::assertSame('de_DE', $this->reflectPrivate($wizard, 'language'));
    }

    // ------------------------------------------------------- boot(), ?dl= download

    /**
     * Real bug fixed alongside this test: boot()'s own ?dl= branch used to
     * call header()/echo/unlink()/exit() directly -- exactly the pattern
     * every sibling check in this same method (a few lines below: the
     * mysqli-extension guard, the already-installed guard) had already
     * moved off of, onto throw new ResponseReadyException(...), precisely
     * because an exit()-terminated branch can't be exercised from inside
     * this same PHP test process without killing the whole run. Converted
     * it to the same pattern here -- public/install.php's own entry shell
     * already wraps every boot()/analyzeForm()/performInstall()/render()
     * call in a `catch (ResponseReadyException $e)` that emits whatever
     * response it carries, so this needed no change on that end.
     */
    public function test_boot_serves_and_deletes_the_temporary_database_config_file_for_a_valid_dl_param(): void
    {
        $this->bootInstallBootstrap();

        // Mirrors the real shape performInstall()'s own config-write-
        // failure fallback leaves behind: a temp `pwg_<hash>` file under
        // confDataLocation, named after a 32-hex-char hash -- matches both
        // InstallWizardRequest::fromArrays()'s own dl validation regex
        // ('/^[a-f0-9]{32}$/') and install.tpl's own config_url template
        // var (see performInstall()'s own 'config_url' => 'install.php?dl='
        // . $tmp_filename assignment; an md5() digest -- the real hash
        // that branch generates -- is exactly 32 lowercase hex chars).
        mkdir($this->tempRoot . '_data', 0777, true);
        $hash = md5('install-wizard-dl-test-fixture');
        $fileContent = "<?php\n\$conf['dblayer'] = 'mysqli';\n\$conf['db_base'] = 'whatever';\n";
        $tmpFile = $this->tempRoot . '_data/pwg_' . $hash;
        file_put_contents($tmpFile, $fileContent);

        $body = null;
        $headers = [];
        try {
            $this->submit([], ['dl' => $hash]);
            self::fail('expected boot() to throw ResponseReadyException for a valid, existing dl= download');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $body = (string) $response->getBody();
            $headers = [
                'Cache-Control' => $response->getHeader('Cache-Control'),
                'Pragma' => $response->getHeader('Pragma'),
                'Content-Disposition' => $response->getHeader('Content-Disposition'),
                'Content-Transfer-Encoding' => $response->getHeader('Content-Transfer-Encoding'),
                'Content-Length' => $response->getHeader('Content-Length'),
            ];
        }

        self::assertSame($fileContent, $body);
        self::assertSame(
            [
                'Cache-Control' => ['no-cache, must-revalidate'],
                'Pragma' => ['no-cache'],
                'Content-Disposition' => ['attachment; filename="database.inc.php"'],
                'Content-Transfer-Encoding' => ['binary'],
                'Content-Length' => [(string) strlen($fileContent)],
            ],
            $headers
        );

        // Real, load-bearing cleanup -- the original header()/echo/
        // unlink()/exit() sequence deleted the temp file once served, and
        // the ResponseReadyException-based replacement preserves that
        // exactly (unlink() happens before the throw, not in some
        // finally/emitter step this test would otherwise miss).
        self::assertFileDoesNotExist($tmpFile);
    }

    public function test_boot_falls_through_to_the_normal_request_body_for_a_dl_param_with_no_matching_file(): void
    {
        $this->bootInstallBootstrap();

        // Syntactically valid (32 lowercase hex chars, passes
        // InstallWizardRequest's own dl validation regex) but nothing on
        // disk matches it -- proves the branch's file_exists() half
        // actually gates it, not just the "dl param is present" half the
        // test above alone couldn't distinguish.
        $hash = str_repeat('a', 32);

        $wizard = $this->submit([], ['dl' => $hash]);

        self::assertSame(1, $this->reflectPrivate($wizard, 'step'));
    }

    // --------------------------------------------------------- analyzeForm(), more

    public function test_analyzeForm_prints_the_errors_when_the_db_connection_itself_fails(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => '127.0.0.1:1', // nothing listens here -- a real, immediate connection failure
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
            'admin_name' => 'somebody',
            'admin_pass1' => 'same-password',
            'admin_pass2' => 'same-password',
            // Empty, not a real address: userService()'s own docblock
            // documents (faithfully preserved from the legacy install.php,
            // confirmed by reading it directly) that a non-empty admin_mail
            // makes analyzeForm() attempt a *second*, entirely independent
            // DbConnection::build() for validateMailAddress() -- with the
            // same broken dbhost above, that second attempt is never
            // caught by anything and really does crash the whole request,
            // in both the legacy code and here. Only an empty admin_mail
            // avoids that call entirely, isolating this test to the one
            // connection-failure behavior it actually means to exercise.
            'admin_mail' => '',
            'install' => '1',
        ]);

        ob_start();
        $wizard->analyzeForm();
        $output = ob_get_clean();

        self::assertIsString($output);
        // analyzeForm()'s own print_r($this->errors) -- a real connection
        // failure's Lang::t($e->getMessage()) text, not asserted verbatim
        // (mysqli's own driver message text/wording isn't this class's
        // contract), just that the debug dump actually ran.
        self::assertStringContainsString('Array', $output);
        self::assertTrue($wizard->hasErrors());
        self::assertNull($this->reflectPrivate($wizard, 'conn'));
    }

    public function test_analyzeForm_rejects_a_webmaster_login_containing_a_quote_character(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
            'admin_name' => "O'Brien",
            'admin_pass1' => 'same-password',
            'admin_pass2' => 'same-password',
            'admin_mail' => 'somebody@example.test',
            'install' => '1',
        ]);

        $wizard->analyzeForm();

        // install.po's own en_UK translation rewords this slightly from
        // the raw source literal -- install.lang is genuinely loaded by
        // this point (confirmed live, deterministically, not a cross-test
        // leak).
        self::assertSame(["the webmaster login may not contain the characters ' or \""], $this->reflectPrivate($wizard, 'errors'));
    }

    public function test_analyzeForm_surfaces_a_malformed_mail_address_from_user_service(): void
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
            'admin_mail' => 'not-an-email-address-at-all',
            'install' => '1',
        ]);

        $wizard->analyzeForm();

        // common.po's own en_UK translation drops the space before the
        // colon in "(example : ...)" -- confirmed live, common.lang is
        // genuinely loaded by this point.
        self::assertSame(
            ['mail address must be like xxx@yyy.eee (example: jack@altern.org)'],
            $this->reflectPrivate($wizard, 'errors')
        );
    }

    // ------------------------------------------------------------ render(), step 2

    /**
     * render()'s step-2 body reaches AuthService::logUser()'s own
     * unconditional setcookie() call and (since session_id() starts empty
     * in a fresh process) its session_start() call too -- both confirmed
     * empirically to emit a real E_WARNING ("headers already sent") once
     * Pest's own console output has already occurred earlier in this
     * shared CLI process, the same CLI-SAPI limitation
     * tests/Unit/Http/Middleware/SessionMiddlewareTest.php's own docblock
     * documents for session_start() alone. Neither warning reflects a real
     * application bug (a genuine HTTP response hasn't sent headers yet
     * when this code runs for real) -- a plain `@` does NOT stop PHPUnit's
     * ErrorHandler from surfacing them regardless (confirmed elsewhere in
     * this suite, see MailServiceTest's own suppressMailerWarning()), so a
     * real no-op error handler for the whole render() call is the only
     * reliable way to swallow them. Wider than that helper's own
     * single-call scope on purpose: there's no seam to isolate just the
     * session/cookie/mail primitives from the rest of this ~60-line
     * method, and every real caller (install.php) hits the identical
     * unavoidable combination.
     *
     * render()'s own step-2 body registers session_write_close() via
     * register_shutdown_function() (matching the original top-level
     * install.php code exactly) -- correct for a real request, where "PHP
     * script shutdown" and "end of this HTTP request" are the same
     * moment, so the deferred write always lands before anything else
     * runs. In this shared PHPUnit/Pest process they are NOT the same
     * moment: shutdown functions only fire at the very end of the whole
     * test binary, long after this test's own tearDown() has already
     * dropped its throwaway database, so the deferred write later fails
     * with "Unknown database" (confirmed empirically: reproduces
     * identically on the pre-WS1-6 codebase, single test in isolation,
     * different throwaway db each run). Calling session_write_close()
     * here forces that write to happen now, while the database still
     * exists -- a no-op for any other caller of this helper with no
     * active session (PHP's own documented behavior).
     */
    private function renderSuppressingHeaderWarnings(InstallWizard $wizard): string
    {
        set_error_handler(static fn (): bool => true);
        try {
            ob_start();
            $wizard->render();
            $output = ob_get_clean();
            session_write_close();
        } finally {
            restore_error_handler();
        }
        self::assertIsString($output);

        return $output;
    }

    /**
     * Covers render()'s own isSendCredentialsByMail branch (Lang::
     * buildArgs() content array + PresentationAccessor::mailService()->
     * mail() call) -- a container-resolved real MailService this test
     * can't inject a spy into (see this class's own use of MailService in
     * the sibling MailServiceTest.php, which faces the identical
     * constraint), so this proves the branch actually ran the same way
     * that file's own test_sendMailTest_dumps_a_labelled_error_file_when_
     * debug_mail_is_enabled() does: force the send to fail fast and
     * deterministically against a real closed local port
     * (smtp_host=127.0.0.1:1, instead of falling through to Symfony's
     * native sendmail transport -- see tests/Browser/InstallTest.php's own
     * docblock for the real 60-120s hang that transport causes in this
     * sandbox with send_credentials_by_mail left checked), then enable
     * debug_mail so the failed send dumps the actually-composed email
     * (subject/To/body -- built from this exact branch's own
     * Lang::buildArgs() calls) to _data/tmp/mail.*.ERROR.txt under this
     * test's own throwaway Paths::root.
     */
    public function test_render_step2_emails_credentials_when_send_credentials_by_mail_was_submitted(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $freshDb,
            'admin_name' => 'p17mailcreds',
            'admin_pass1' => 'Mail-Creds-Secret-1!',
            'admin_pass2' => 'Mail-Creds-Secret-1!',
            'admin_mail' => 'mailcreds@example.test',
            'install' => '1',
            'send_credentials_by_mail' => '1',
            // Deliberately NOT set -- keeps this test isolated to only the
            // mail branch; the newsletter branch has its own dedicated
            // test below.
        ]);
        $wizard->analyzeForm();
        self::assertFalse($wizard->hasErrors(), 'unexpected validation/connection errors: ' . $this->reflectErrorsJoined($wizard));
        $wizard->performInstall();
        self::assertFalse($wizard->hasErrors(), 'unexpected performInstall() errors: ' . $this->reflectErrorsJoined($wizard));

        CurrentConfig::setSmtpHost('127.0.0.1:1');
        CurrentConfig::setDebugMail(true);

        $mailTmpDir = $this->tempRoot . '_data/tmp';
        $before = glob($mailTmpDir . '/mail.*');
        self::assertIsArray($before);

        $output = $this->renderSuppressingHeaderWarnings($wizard);

        self::assertStringContainsString('Congratulations', $output);

        $after = glob($mailTmpDir . '/mail.*');
        self::assertIsArray($after);
        $created = array_values(array_diff($after, $before));
        self::assertCount(1, $created, 'expected exactly one dumped mail (the credentials email) after the send_credentials_by_mail branch ran');
        self::assertStringEndsWith('.ERROR.txt', $created[0]);

        $contents = file_get_contents($created[0]);
        self::assertIsString($contents);
        self::assertStringStartsWith('ERROR: ', $contents);
        // The To: header of a real Symfony Email is never MIME-encoded
        // (only non-ASCII display names are) -- a reliable, unmocked proof
        // this exact branch's mail() call really did address the admin's
        // own submitted email, not some other recipient.
        self::assertStringContainsString('mailcreds@example.test', $contents);

        unlink($created[0]);
    }

    /**
     * Covers render()'s own isNewsletterSubscribe branch (~lines 692-704):
     * HttpClientService::fetch() has no injectable seam (confirmed by this
     * project's own tests/Unit/Http/HttpClientServiceTest.php, which
     * explicitly declines to cover fetch()/guardedFetch() for the same
     * reason), so this can't spy on the call itself -- but AppInfo::URL
     * resolves to 'https://upstream.example.invalid' (RFC 2606 -- a domain
     * reserved to NEVER resolve), which makes HttpClientService's own
     * SSRF guard (assertUrlIsSafe()'s gethostbyname() lookup, which
     * returns the unmodified hostname on failure per PHP's own
     * documented behavior, then fails the private/reserved-IP format
     * check) reject the request in ~10ms flat, confirmed empirically
     * (a standalone script against this exact URL returned `false` in
     * 11.5ms, no warning, no exception escaping) -- fast, deterministic,
     * and with zero real network egress, not just "unlikely to hang".
     * What IS asserted directly: preferences.show_newsletter_subscription
     * gets persisted to the real user_infos row as false regardless of
     * that fetch() outcome, exactly as the source comment above it
     * ("Fire-and-forget: the response content is never read") promises.
     */
    public function test_render_step2_disables_the_newsletter_preference_when_newsletter_subscribe_was_submitted(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $freshDb,
            'admin_name' => 'p17newsletter',
            'admin_pass1' => 'Newsletter-Secret-1!',
            'admin_pass2' => 'Newsletter-Secret-1!',
            'admin_mail' => 'newsletter@example.test',
            'install' => '1',
            'newsletter_subscribe' => '1',
            // send_credentials_by_mail deliberately NOT set -- keeps this
            // test isolated to only the newsletter branch, avoiding the
            // separate smtp_host workaround the mail test above needs.
        ]);
        $wizard->analyzeForm();
        self::assertFalse($wizard->hasErrors(), 'unexpected validation/connection errors: ' . $this->reflectErrorsJoined($wizard));
        $wizard->performInstall();
        self::assertFalse($wizard->hasErrors(), 'unexpected performInstall() errors: ' . $this->reflectErrorsJoined($wizard));

        $output = $this->renderSuppressingHeaderWarnings($wizard);

        self::assertStringContainsString('Congratulations', $output);

        $prefsRow = $this->queryOne($freshDb, 'SELECT preferences FROM itest_user_infos WHERE user_id = 1');
        self::assertIsArray($prefsRow);
        self::assertIsString($prefsRow['preferences']);
        $preferences = json_decode($prefsRow['preferences'], true);
        self::assertIsArray($preferences);
        self::assertArrayHasKey('show_newsletter_subscription', $preferences);
        self::assertFalse($preferences['show_newsletter_subscription']);
    }

    /**
     * render()'s full step-2 happy path (a real session_start()/
     * setcookie() lifecycle actually taking effect, a real outbound mail/
     * newsletter call actually succeeding) is still deliberately NOT
     * exercised here -- see this file's own top-of-class docblock:
     * tests/Browser/InstallTest.php already covers that through a real
     * install.php request, and that file's own docblock documents a real
     * hung request thread this suite previously hit trying to exercise the
     * same real session/mail lifecycle in-process. This one pure guard at
     * the very top of the step-2 branch -- reachable with zero session/
     * mail setup -- is safe and cheap to exercise directly here too.
     */
    public function test_render_throws_when_step_2_is_reached_without_a_successful_connection(): void
    {
        $this->bootInstallBootstrap();
        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
        ]);
        // step defaults to 1 and conn defaults to null until analyzeForm()
        // -> performInstall() run; force step to 2 directly (mirroring what
        // performInstall() does) without ever building a connection, the
        // exact "reached step 2 before a successful connection" state this
        // guard exists for.
        new \ReflectionProperty($wizard, 'step')->setValue($wizard, 2);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('render() reached step 2 before a successful analyzeForm() connection.');

        $wizard->render();
    }
}
