<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use mysqli_result;
use Override;
use Piwigo\Admin\Install\InstallWizard;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Validation\InputValidator;
use ReflectionProperty;
use SessionHandler;

/**
 * InstallWizard is install.php's whole orchestration, ported verbatim from
 * that script's former top-level code (see its own docblock). Every test
 * here runs the wizard for real -- a real Kernel-booted container, a real
 * Doctrine connection, real schema creation from the Doctrine Migrations
 * baseline (src/Piwigo/Migrations/) + install/config.sql -- against
 * completely disposable state:
 *
 *  - Paths::root is a throwaway directory under sys_get_temp_dir(), never
 *    the real repo root or the shared dev box's real local/.installed(.test)
 *    sentinel. install/, themes/, plugins/, language/ are symlinked in
 *    read-only from the real repo (InstallWizard needs the real admin
 *    theme's Latte templates + the real install/config.sql file + the
 *    real bundled languages to behave authentically), but every WRITE
 *    path (.env(.test), the install stamp, _data/, Latte's compile dir)
 *    lands only inside this throwaway root.
 *    Piwigo\Db\MigrationDependencyFactory::build() deliberately resolves
 *    config/migrations.php relative to its own source file, not through
 *    Paths (see that class's own docblock), so no config/ symlink is
 *    needed here the way install/ needs one.
 *  - The database submitted through the (simulated) install form is a
 *    brand-new, uniquely-named database this test creates and drops itself,
 *    never the shared PIWIGO_DB_BASE Integration-test database every other
 *    Integration test file assumes is already fixture-loaded.
 *
 * DbCredentials::seed() and $_GET/$_POST/$_SERVER mutations are
 * real process-wide/superglobal state -- every test snapshots and restores
 * them, the same reasoning as InstallServiceTest's own $originalDbEnv.
 * Likewise, this whole test *process* runs with $_SERVER['HTTP_X_PIWIGO_ENV']
 * = 'test' set globally by tests/bootstrap.php, so Env::testModeIsActive()
 * is always true here -- performInstall()'s env file write always lands on
 * '.env.test', never plain '.env'.
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
 * resolve, and fails HttpClientService::fetch()'s own SSRF-guard host
 * check in ~10ms with no network egress at all, not just a slow/blocked
 * one) for the newsletter branch. Calling render() this far
 * also reaches AuthService::logUser()'s own unconditional setcookie() and
 * session_start() calls, which both emit a
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
 * language, the written .env/install stamp) is already fully covered below
 * via performInstall() directly.
 *
 * Three real branches are deliberately left uncovered, not silently
 * skipped -- each is a genuine behavioral guard whose own triggering
 * condition is provably unreachable from a real InstallWizard call in
 * this environment, the same reasoning
 * tests/Unit/Core/CoverageCollectorTest.php's own docblock already
 * documents for CoverageCollector::registerIfActive()'s own
 * `! extension_loaded('pcov')` guard:
 *  - boot()'s `! extension_loaded('mysqli')` check: every test in this
 *    whole file already requires a real mysqli-backed DB connection in
 *    this exact PHP process, so the negation can never be true here
 *    (`php -m` lists mysqli).
 *  - boot()'s `version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION,
 *    '<')` check: PHP_VERSION is fixed for this whole process's lifetime,
 *    and AppInfo::REQUIRED_PHP_VERSION is a compile-time class constant
 *    ('8.5.0', at or below the actual PHP version this suite runs on)
 *    -- nothing reachable from a real InstallWizard call
 *    can make this comparison true.
 *  - render()'s step-2 `$login_user_id` narrowing (the
 *    `elseif (is_string($raw_login_user_id) && is_numeric(...))`/`else`
 *    arms): $raw_login_user_id always comes from
 *    UserService::buildUser(1)'s own 'id' key, ultimately `users.id`
 *    (a `mediumint unsigned` column) read through this
 *    project's real mysqli DBAL driver config, which returns integer
 *    columns as native PHP int (e.g. a bare
 *    `fetchAssociative('SELECT 1 AS id')` against this same driver
 *    config returns `int(1)`, not `"1"`) -- so the `if (is_int(...))`
 *    branch always wins in practice. The one real knob that could produce a
 *    non-int 'id' here, `$conf['user_fields']['id']` (a genuine
 *    external-auth column remap -- see e.g.
 *    AlbumNotificationPageRenderer's own comment on it), cannot be set to
 *    anything but its 'id'=>'id' default at install time: the config
 *    table this wizard itself is creating doesn't exist with any
 *    admin-set override until well after this exact call returns. Faking
 *    it here via CurrentConfig::setUserFields() would force a state no
 *    real InstallWizard call can ever actually be in, not a genuine
 *    input.
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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp()'s own conditional default boot (real repo root)
        // would otherwise defeat bootInstallBootstrap()/submit()'s own
        // Kernel::boot() calls below via Kernel::boot()'s idempotency
        // guard -- worse than just the wrong Paths (already documented for
        // InstallBootstrapTest above), here it also leaves any
        // Connection/EntityManagerInterface PHP-DI resolves before
        // analyzeForm()'s own DbCredentials::seed() call permanently bound
        // to the wrong (real, not the test's freshly created) database,
        // since a live DB connection doesn't retroactively follow a later
        // credentials change. Reset back to a genuinely unbooted baseline
        // so each test's own boot sequence is a real first boot.
        Kernel::reset();
        $this->setUpConnectionFromEnv();

        // PIWIGO_DB_DRIVER/PIWIGO_DB_PORT were both missing from this
        // list, so boot()'s own real DbCredentials::seed() call (every
        // real test here reaches it via analyzeForm()/boot(), submitting
        // no explicit 'dbdriver' field, which InstallWizardRequest
        // defaults to 'mysqli') left the REAL process env var permanently
        // flipped to mysqli after this class's own tests ran, unrestored
        // by tearDown() below -- dozens of unrelated later test classes
        // silently ran against the wrong driver (`PIWIGO_DB_DRIVER=pgsql`
        // in `.env.test`, but `getenv('PIWIGO_DB_DRIVER')` returning the
        // leaked 'mysqli') once this class's tests happened to run first
        // in process order.
        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'] as $key) {
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
        // Present-but-empty, not absent -- InstallWizard's own flow no
        // longer reads this file (nothing under src/Piwigo/Admin/Install/
        // does), but the pre-existing local/config/ subdirectory this write
        // creates is itself relied on below (see
        // testPerformInstallRecordsAnErrorWhenTheEnvFileCannotBeWritten's
        // own comment on already-existing subdirectories surviving a
        // read-only root).
        file_put_contents($this->tempRoot . 'local/config/config.inc.php', "<?php\n// no overrides\n");
        $this->paths = Paths::fromRoot($this->tempRoot);
    }

    #[Override]
    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_SERVER = $this->originalServer;
        // render()'s own step-2 flow (InstallWizard.php ~line 701) calls
        // session_set_save_handler() directly against this test's
        // disposable $conn, then logs the new webmaster in -- a real PHP
        // session, closed only via register_shutdown_function(), which
        // doesn't fire until this whole CLI test *process* exits
        // (composer test:integration runs sequentially, one process, not
        // one per test). Close it here instead, before createdDatabases
        // cleanup below drops that connection's database out from under
        // it.
        //
        // Closing the ACTIVE session isn't enough on its own, though:
        // session_set_save_handler()'s own registration is separate,
        // process-global PHP state that outlives close() -- it stays
        // "the current handler" until something calls
        // session_set_save_handler() again, which doesn't happen for
        // every later request. Confirmed live: RequestPipelineTest calls
        // RequestPipeline::handle() directly, bypassing
        // RequestBootstrap::bootEntryPoint() (the only place that calls
        // InstallationFlag::mark()) entirely, so
        // Http\SessionBootstrap::register()'s own
        // `$installationFlag->isActive()` gate is false for every one of
        // its requests -- normally harmless (PHP's own default
        // file-based handler is already in effect), but it means
        // session_set_save_handler() is never called again to replace
        // this test's leftover handler, so RequestPipelineTest's own
        // session_start() silently reuses it -- still bound to the
        // now-dropped database. Explicitly re-registering PHP's own
        // built-in \SessionHandler (files-based, no DB dependency) is
        // what actually undoes the registration itself, not just the
        // active session it was serving.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_set_save_handler(new SessionHandler());
        DbCredentialsTestFactory::get()->seed($this->originalDbEnv);
        if ($this->installBootstrapBooted) {
            restore_error_handler();
            $errorCollector = Kernel::container()->get(ErrorCollector::class);
            if ($errorCollector instanceof ErrorCollector) {
                $errorCollector->reset();
            }
        }
        if (Kernel::isBooted()) {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
        }
        Kernel::reset();
        foreach ($this->createdDatabases as $dbName) {
            $this->dropDatabase($dbName);
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
        $this->dropAndCreateDatabase($name);
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
     * just InstallWizard's constructor+boot()): InstallWizard now takes
     * UrlServiceInterface/HtmlRenderingInterface/ImageStdParams directly as
     * constructor collaborators (matching Template's own required
     * collaborators), the same shape install.php's own
     * `RequestBootstrap::urlService()`/`htmlRenderer()`/`imageStdParams()`
     * calls supply in production.
     *
     * @param array<string, string> $post
     * @param array<string, string> $get
     */
    private function submit(array $post, array $get = [], bool $preserveAcceptLanguage = false): InstallWizard
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

        $dbCredentials = DbCredentialsTestFactory::get();
        $currentTemplate = new CurrentTemplate();

        $wizard = new InstallWizard(LangTestFactory::get(), $this->paths, $dbCredentials, CurrentConfigServiceTestFactory::get(), CurrentConfigTestFactory::get(), new InputValidator(), new EventDispatcher(), new PageState(), new ErrorCollector(new DeploymentPolicy(), $this->paths), new ProcessCache(), new DeploymentPolicy(), $currentTemplate, CurrentUserTestFactory::get(), new ConnectedWithSession(), new Renderer($currentTemplate), UrlServiceTestFactory::build(), HtmlServiceTestFactory::build(), ImageStdParamsTestFactory::get());
        $wizard->boot();

        return $wizard;
    }

    private function reflectPrivate(object $object, string $property): mixed
    {
        return new ReflectionProperty($object, $property)
            ->getValue($object);
    }

    /**
     * Joins InstallWizard::$errors (a list<string>, reflected) into one message for a failed assertion.
     */
    private function reflectErrorsJoined(object $wizard): string
    {
        $errors = $this->reflectPrivate($wizard, 'errors');
        self::assertIsArray($errors);

        return implode('; ', array_map(static fn (mixed $e): string => is_string($e) ? $e : '', $errors));
    }

    /**
     * pg_fetch_assoc()/mysqli_result::fetch_assoc() are both documented to
     * return purely string-keyed rows (column names) in practice, but
     * their own PHPStan stubs are conservative about it (`array<int|string,
     * ...>` for the pg one) -- rebuilt into a genuinely string-keyed array
     * here rather than trusting/casting past that, since every real caller
     * only ever accesses a named key (e.g. $row['c']).
     *
     * @return array<string, mixed>|null
     */
    private function queryOne(string $dbName, string $sql): ?array
    {
        if ($this->dbDriver === 'pgsql') {
            $conn = $this->newPgsqlConnection($dbName);
            $result = pg_query($conn, $sql);
            self::assertNotFalse($result, 'pg_query failed');
            $row = pg_fetch_assoc($result);
            pg_close($conn);

            if ($row === false) {
                return null;
            }

            $stringKeyed = [];
            foreach ($row as $key => $value) {
                self::assertIsString($key, 'queryOne() expects string column names');
                $stringKeyed[$key] = $value;
            }

            return $stringKeyed;
        }

        $db = $this->newMysqli($dbName);
        self::assertSame(0, $db->connect_errno, $db->connect_error ?? '');
        $result = $db->query($sql);
        self::assertInstanceOf(mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $db->close();

        return $row === false ? null : $row;
    }

    /**
     * Runs a `SELECT COUNT(*) AS c ...` query and returns the count as a real int.
     */
    private function queryOneCount(string $dbName, string $sql): int
    {
        $row = $this->queryOne($dbName, $sql);
        self::assertIsArray($row);
        $count = $row['c'];
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    // --------------------------------------------------------- analyzeForm()

    public function testAnalyzeFormCollectsEveryRealValidationErrorWhileTheConnectionItselfSucceeds(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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

    // ------------------------------------------------------------- render() (step 1)

    public function testRenderOutputsTheInitialInstallFormWithTheSubmittedFieldValuesPrefilled(): void
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

    /**
     * [P44-A] render()'s own newsletter-subscribe label echoes admin_mail
     * back as a raw HTML fragment ('<span class="adminEmail">' . $email .
     * '</span>', assembled entirely in PHP, outside any single Latte
     * print) -- previously unescaped, a real reflected-XSS gap reachable
     * on the very first install.php submission, before any
     * authentication exists at all.
     */
    public function testRenderEscapesAnHtmlSpecialCharacterBearingAdminMailInTheNewsletterLabel(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => 'submitted-host',
            'dbuser' => 'submitted-user',
            'dbname' => 'submitted-db',
            'admin_mail' => '"><script>alert(1)</script>',
        ]);

        ob_start();
        $wizard->render();
        $output = ob_get_clean();

        self::assertIsString($output);
        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringContainsString(
            '<span class="adminEmail">&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;</span>',
            $output
        );
    }

    /**
     * render()'s own `if (count($this->errors) !== 0)` guard -- every
     * other render() test in this file either has zero errors (a fresh
     * step-1 form) or asserts hasErrors() is false before ever calling
     * render(), so this is the first one to reach render() with real,
     * analyzeForm()-collected errors still present. Verified via
     * install.latte's real `{if isset($errors)}` HTML rendering of it --
     * unlike the old ambient `assignContext()`-based mechanism (still
     * checkable via `Template::getTemplateVars()` for the classes that
     * still use it), a real typed `InstallView` property is never
     * written into `Template::$vars`, so the rendered output itself is
     * the only real behavioral assertion available here (P41-D,
     * docs/PLAN.md).
     */
    public function testRenderAssignsTheCollectedValidationErrorsToTheTemplate(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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
        // step stays 1 -- performInstall() never runs -- so render() takes
        // its step-1 branch and reaches this guard with $this->errors still
        // populated from analyzeForm() above.

        ob_start();
        $wizard->render();
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString('please enter the webmaster username', $output);
    }

    // ------------------------------------------------------------ performInstall()

    /**
     * performInstall()'s own mirror of render()'s "reached step 2 before a
     * successful connection" guard (see
     * test_render_throws_when_step_2_is_reached_without_a_successful_connection
     * below) -- the entry shell itself only ever calls performInstall()
     * once hasErrors() is false, which requires analyzeForm() to have
     * already built a real connection (per this method's own docblock), so
     * this is reachable only by a caller that skips straight to
     * performInstall() the way this test does.
     */
    public function testPerformInstallThrowsWhenCalledBeforeASuccessfulConnection(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
        ]);
        // conn defaults to null until analyzeForm() runs -- never called here.

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('performInstall() called before a successful analyzeForm() connection.');

        $wizard->performInstall();
    }

    public function testPerformInstallCreatesTheRealSchemaWebmasterUserAndSiteConfig(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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
        $webmaster = $this->queryOne($freshDb, 'SELECT id, username, password, mail_address FROM users WHERE id = 1');
        self::assertIsArray($webmaster);
        self::assertSame('p17setup', $webmaster['username']);
        self::assertSame('webmaster@example.test', $webmaster['mail_address']);
        self::assertIsString($webmaster['password']);
        self::assertTrue(
            new PasswordService(new PasswordRepository(EntityManagerFactory::build(DbConnection::build())), new DeploymentPolicy())->verify('Sup3r-Secret-99!', $webmaster['password']),
            'the stored hash must verify against the exact submitted password'
        );

        $guest = $this->queryOne($freshDb, 'SELECT id, username, password, mail_address FROM users WHERE id = 2');
        self::assertIsArray($guest);
        self::assertSame('guest', $guest['username']);
        self::assertNull($guest['password']);
        self::assertNull($guest['mail_address']);

        // ---- user_infos: webmaster/guest status + language -------------
        $webmasterInfo = $this->queryOne($freshDb, 'SELECT status, language FROM user_infos WHERE user_id = 1');
        self::assertIsArray($webmasterInfo);
        self::assertSame('webmaster', $webmasterInfo['status']);
        self::assertSame('en_UK', $webmasterInfo['language']);

        $guestInfo = $this->queryOne($freshDb, 'SELECT status, language FROM user_infos WHERE user_id = 2');
        self::assertIsArray($guestInfo);
        self::assertSame('guest', $guestInfo['status']);

        // ---- sites -------------------------------------------------------
        $site = $this->queryOne($freshDb, 'SELECT id, galleries_url FROM sites WHERE id = 1');
        self::assertIsArray($site);
        self::assertSame($this->tempRoot . 'galleries/', $site['galleries_url']);

        // ---- config: secret_key + gallery_title + page_banner -----------
        $secretKey = $this->queryOne($freshDb, "SELECT value FROM config WHERE param = 'secret_key'");
        self::assertIsArray($secretKey);
        self::assertIsString($secretKey['value']);
        $decodedSecret = json_decode($secretKey['value'], true);
        self::assertIsString($decodedSecret);
        self::assertSame(40, strlen($decodedSecret), 'secret_key must be a sha1 hex digest');

        $galleryTitle = $this->queryOne($freshDb, "SELECT value FROM config WHERE param = 'gallery_title'");
        self::assertIsArray($galleryTitle);
        self::assertSame('"Just another Piwigo gallery"', $galleryTitle['value']);

        // ---- languages: only en_UK activated -----------------------------
        $language = $this->queryOne($freshDb, "SELECT id, name FROM languages WHERE id = 'en_UK'");
        self::assertIsArray($language);
        self::assertSame('English (Great Britain)', $language['name']);
        self::assertSame(1, $this->queryOneCount($freshDb, 'SELECT COUNT(*) AS c FROM languages'));

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
        self::assertSame(0, $this->queryOneCount($freshDb, 'SELECT COUNT(*) AS c FROM themes'));
        self::assertSame(0, $this->queryOneCount($freshDb, 'SELECT COUNT(*) AS c FROM plugins'));

        // ---- .env.test (this whole process runs with test mode active) --
        $envFile = $this->tempRoot . '.env.test';
        self::assertFileExists($envFile);
        $envContent = file_get_contents($envFile);
        self::assertIsString($envContent);
        self::assertStringContainsString('PIWIGO_DB_HOST=' . $this->dbHost, $envContent);
        self::assertStringContainsString('PIWIGO_DB_USER=' . $this->dbUser, $envContent);
        self::assertStringContainsString('PIWIGO_DB_BASE=' . $freshDb, $envContent);
        self::assertStringContainsString('PIWIGO_BASE_URL=http://example.test', $envContent);

        // ---- install stamp ------------------------------------------------
        self::assertFileExists($this->paths->siteLocal . Env::testModeInstalledStamp());
    }

    /**
     * Regression test for a fixed bug: MigrateCommand::run() is
     * called directly (not through a full Symfony Application, see
     * performInstall()'s own comment on that block), so it does NOT
     * guarantee catching every failure into a plain exit code the way a
     * real CLI invocation would. A driver-level exception (mysqli's own
     * exception-throwing mode surfacing a genuine "table already exists"
     * collision, reproduced here by pre-creating one of
     * the baseline migration's own tables) escaped it uncaught, and since
     * public/install.php's own top-level catch only handles
     * ResponseReadyException, that reached a real browser as an uncaught
     * fatal error -- a blank page, not the installer's own error UI.
     */
    public function testPerformInstallRecordsAnErrorAndDoesNotProceedWhenTheSchemaMigrationFails(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        // Pre-create one of the tables the baseline migration itself
        // creates, so the real MigrateCommand run below collides on it
        // exactly the way a genuinely-already-installed database does.
        if ($this->dbDriver === 'pgsql') {
            $conn = $this->newPgsqlConnection($freshDb);
            pg_query($conn, 'CREATE TABLE caddie (id INT)');
            pg_close($conn);
        } else {
            $db = $this->newMysqli($freshDb);
            $db->query('CREATE TABLE caddie (id INT)');
            $db->close();
        }

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $freshDb,
            'admin_name' => 'p17migratefail',
            'admin_pass1' => 'Migrate-Fail-Secret-1!',
            'admin_pass2' => 'Migrate-Fail-Secret-1!',
            'admin_mail' => 'migratefail@example.test',
            'install' => '1',
        ]);
        $wizard->analyzeForm();
        self::assertFalse($wizard->hasErrors(), 'unexpected validation/connection errors: ' . $this->reflectErrorsJoined($wizard));

        $wizard->performInstall();

        self::assertTrue($wizard->hasErrors());
        self::assertStringContainsString('Schema migration failed', $this->reflectErrorsJoined($wizard));
        // Reset to step 1 (not left at step 2 -- see performInstall()'s own
        // comment) so a caller's later render() shows the initial form with
        // this error, not a false "installation succeeded" page.
        self::assertSame(1, $this->reflectPrivate($wizard, 'step'));
    }

    public function testPerformInstallRecordsAnErrorWhenTheEnvFileCannotBeWritten(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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

    public function testBootFatalsWhenTheInstallStampAlreadyExists(): void
    {
        $this->bootInstallBootstrap();
        touch($this->paths->siteLocal . Env::testModeInstalledStamp());

        $this->expectException(ResponseReadyException::class);

        $this->submit([], []);
    }

    public function testBootFallsBackToTheDefaultLanguageForAnUnrecognizedRequestedLanguage(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([], [
            'language' => 'totally-bogus-language-xyz',
        ]);

        self::assertSame(AppInfo::DEFAULT_LANGUAGE, $this->reflectPrivate($wizard, 'language'));
    }

    public function testBootPicksTheBrowserLanguageWhenNoneWasRequestedAndItIsBundled(): void
    {
        $this->bootInstallBootstrap();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';

        $wizard = $this->submit([], [], preserveAcceptLanguage: true);

        self::assertSame('de_DE', $this->reflectPrivate($wizard, 'language'));
    }

    // --------------------------------------------------------- analyzeForm(), more

    public function testAnalyzeFormPrintsTheErrorsWhenTheDbConnectionItselfFails(): void
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
            // documents (faithfully preserved from the legacy install.php)
            // that a non-empty admin_mail
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

    public function testAnalyzeFormRejectsAWebmasterLoginContainingAQuoteCharacter(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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
        // this point, deterministically, not a cross-test leak.
        self::assertSame(["the webmaster login may not contain the characters ' or \""], $this->reflectPrivate($wizard, 'errors'));
    }

    public function testAnalyzeFormSurfacesAMalformedMailAddressFromUserService(): void
    {
        $this->bootInstallBootstrap();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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
        // colon in "(example : ...)" -- common.lang is
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
     * in a fresh process) its session_start() call too -- both emit a
     * real E_WARNING ("headers already sent") once
     * Pest's own console output has already occurred earlier in this
     * shared CLI process, the same CLI-SAPI limitation
     * tests/Unit/Http/Middleware/SessionMiddlewareTest.php's own docblock
     * documents for session_start() alone. Neither warning reflects a real
     * application bug (a genuine HTTP response hasn't sent headers yet
     * when this code runs for real) -- a plain `@` does NOT stop PHPUnit's
     * ErrorHandler from surfacing them regardless (see MailServiceTest's
     * own suppressMailerWarning()), so a
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
     * with "Unknown database", reproducing identically in isolation with
     * a different throwaway db each run. Calling session_write_close()
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
    public function testRenderStep2EmailsCredentialsWhenSendCredentialsByMailWasSubmitted(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->smtpHost = '127.0.0.1:1';
        $currentConfig->debugMail = true;

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
     * check) reject the request in ~10ms flat (measured ~11.5ms in
     * isolation, no warning, no exception escaping) -- fast, deterministic,
     * and with zero real network egress, not just "unlikely to hang".
     * What IS asserted directly: preferences.show_newsletter_subscription
     * gets persisted to the real user_infos row as false regardless of
     * that fetch() outcome, exactly as the source comment above it
     * ("Fire-and-forget: the response content is never read") promises.
     */
    public function testRenderStep2DisablesTheNewsletterPreferenceWhenNewsletterSubscribeWasSubmitted(): void
    {
        $this->bootInstallBootstrap();
        $freshDb = $this->createFreshDatabase();

        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
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

        $prefsRow = $this->queryOne($freshDb, 'SELECT preferences FROM user_infos WHERE user_id = 1');
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
     * newsletter call actually succeeding) is deliberately NOT exercised
     * here -- see this file's own top-of-class docblock: tests/Browser/
     * InstallTest.php already covers that through a real install.php
     * request; exercising the same real session/mail lifecycle in-process
     * risks a hung request thread. This one pure guard at the very top of
     * the step-2 branch -- reachable with zero session/mail setup -- is
     * safe and cheap to exercise directly here too.
     */
    public function testRenderThrowsWhenStep2IsReachedWithoutASuccessfulConnection(): void
    {
        $this->bootInstallBootstrap();
        $wizard = $this->submit([
            'dbhost' => $this->dbHost,
            'dbdriver' => $this->dbDriver,
            'dbuser' => $this->dbUser,
            'dbpasswd' => $this->dbPass,
            'dbname' => $this->dbName,
        ]);
        // step defaults to 1 and conn defaults to null until analyzeForm()
        // -> performInstall() run; force step to 2 directly (mirroring what
        // performInstall() does) without ever building a connection, the
        // exact "reached step 2 before a successful connection" state this
        // guard exists for.
        new ReflectionProperty($wizard, 'step')
            ->setValue($wizard, 2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('render() reached step 2 before a successful analyzeForm() connection.');

        $wizard->render();
    }
}
