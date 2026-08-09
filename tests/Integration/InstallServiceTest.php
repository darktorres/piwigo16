<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use RuntimeException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Install\InstallService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Db\Tables;
use Piwigo\Tests\Support\CurrentUserTestFactory;

/**
 * InstallService is a bag of static helpers pulled verbatim out of the
 * former admin/include/functions_install.inc.php (see its own docblock).
 * Every method here is plain SQL-file execution, a DB-credential probe, or
 * a real filesystem extension scan + DB write -- none of it needs a full
 * InstallWizard orchestration, so each is exercised directly against
 * either a disposable ad hoc table (executeSqlfile) or the shared
 * Integration test database (installDbConnect/activateCoreThemes/
 * activateCorePlugins), the same real-DB pattern ExtensionLifecycleTest
 * already established.
 */
final class InstallServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    /**
     * @var array<string, string> original PIWIGO_DB_* env values, restored
     *   in tearDown() -- DbCredentials::seed() mutates real process env
     *   vars via putenv(), which would otherwise leak a bad/throwaway
     *   credential into every later test in this shared process.
     */
    private array $originalDbEnv = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        // pgsql support pass: real bug found live -- PIWIGO_DB_DRIVER/
        // PIWIGO_DB_PORT were both missing here, defeating this exact
        // list's own stated purpose above: installDbConnect() calls here
        // (this class's real subject) mutate the real process env via
        // DbCredentials::seed()/putenv(), submitting no explicit driver
        // most of the time (defaults to mysqli), which then leaked
        // unrestored into every later Integration test class in the same
        // process -- confirmed live via a full suite run.
        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        DbCredentialsTestFactory::get()->seed([
            'PIWIGO_DB_HOST' => $this->dbHost,
            'PIWIGO_DB_USER' => $this->dbUser,
            'PIWIGO_DB_PASSWORD' => $this->dbPass,
            'PIWIGO_DB_BASE' => $this->dbName,
            'PIWIGO_DB_PREFIX' => $this->dbPrefix,
        ]);
        $this->conn = DbConnection::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        DbCredentialsTestFactory::get()->seed($this->originalDbEnv);
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        Kernel::reset();
        parent::tearDown();
    }

    // --------------------------------------------------- PHP5_HOSTING_HTACCESS

    public function test_php5_hosting_htaccess_constant_has_the_expected_hosting_directives(): void
    {
        // PHPStan proves each of these from the constant's own current
        // literal value, hence "redundant" -- but that's exactly the
        // point: this legacy-hosting-compat data is otherwise only ever
        // read, never re-derived, so this guard (not a PHPStan run) is
        // what catches an accidental edit to one of these entries.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertCount(11, InstallService::PHP5_HOSTING_HTACCESS);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('AddType x-mapp-php5 .php', InstallService::PHP5_HOSTING_HTACCESS['kundenserver.de']);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('SetEnv PHP_VER 5', InstallService::PHP5_HOSTING_HTACCESS['ovh.net']);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('php 1', InstallService::PHP5_HOSTING_HTACCESS['free.fr']);
    }

    // --------------------------------------------------------- executeSqlfile

    public function test_executeSqlfile_creates_tables_applies_prefix_replacement_and_skips_drop_table(): void
    {
        $sqlFile = sys_get_temp_dir() . '/piwigo-install-service-' . bin2hex(random_bytes(6)) . '.sql';
        file_put_contents(
            $sqlFile,
            "-- a comment line, must be ignored\n"
            . "\n"
            . "CREATE TABLE PREFIX_gizmo (\n"
            . "  id INT NOT NULL,\n"
            . "  label VARCHAR(64) NOT NULL\n"
            . ");\n"
            . "INSERT INTO PREFIX_gizmo (id, label) VALUES (42, 'widget-forty-two');\n"
            . "DROP TABLE PREFIX_gizmo;\n"
        );

        try {
            InstallService::executeSqlfile($this->conn, $sqlFile, 'PREFIX_', 'itest_');

            // The DROP TABLE line was skipped -- the table (and its row)
            // must still exist.
            // itest_gizmo is created moments earlier by executeSqlfile()
            // above from an inline, throwaway SQL file -- it never exists
            // in the persistent schema staabm/phpstan-dba's live reflector
            // inspects, so it always reports "table does not exist" here.
            // @phpstan-ignore dba.syntaxError
            $row = $this->conn->fetchAssociative('SELECT id, label FROM itest_gizmo WHERE id = 42');
            self::assertIsArray($row);
            $id = $row['id'];
            self::assertSame(42, is_numeric($id) ? (int) $id : null);
            self::assertSame('widget-forty-two', $row['label']);
        } finally {
            $this->conn->executeStatement('DROP TABLE IF EXISTS itest_gizmo');
            unlink($sqlFile);
        }
    }

    public function test_executeSqlfile_throws_a_runtimeexception_when_the_file_does_not_exist(): void
    {
        $missing = sys_get_temp_dir() . '/piwigo-install-service-does-not-exist-' . bin2hex(random_bytes(6)) . '.sql';

        // executeSqlfile()'s own `file($filepath)` call unavoidably raises a
        // real E_WARNING ("failed to open stream") before returning false --
        // this is the exact real branch being tested (the RuntimeException
        // it triggers), not a flaw in this test. This suite's own phpunit.xml
        // has failOnWarning="true", and neither `@` nor a temporary
        // error_reporting(0) actually hides it from PHPUnit's handler here
        // (confirmed) -- installing a handler that swallows it outright is
        // the same real-guard pattern already used elsewhere in this suite
        // (see tests/Unit/Admin/Image/ImageGdTest.php,
        // tests/Unit/Lang/TranslatorTest.php).
        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('Unable to read SQL file: ' . $missing);

            InstallService::executeSqlfile($this->conn, $missing, 'PREFIX_', 'itest_');
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------- installDbConnect

    public function test_installDbConnect_returns_a_working_connection_and_records_no_errors_for_valid_credentials(): void
    {
        $infos = [];
        $errors = [];

        $conn = InstallService::installDbConnect($infos, $errors, LangTestFactory::get());

        self::assertInstanceOf(Connection::class, $conn);
        self::assertSame([], $errors);
        self::assertSame([], $infos);
        self::assertSame(1, $this->fetchOneInt($conn->fetchOne('SELECT 1')));
    }

    public function test_installDbConnect_returns_null_and_records_an_error_for_a_wrong_password(): void
    {
        DbCredentialsTestFactory::get()->seed([
            'PIWIGO_DB_HOST' => $this->dbHost,
            'PIWIGO_DB_USER' => $this->dbUser,
            'PIWIGO_DB_PASSWORD' => $this->dbPass . '-definitely-wrong',
            'PIWIGO_DB_BASE' => $this->dbName,
            'PIWIGO_DB_PREFIX' => $this->dbPrefix,
        ]);
        $infos = [];
        $errors = [];

        $conn = InstallService::installDbConnect($infos, $errors, LangTestFactory::get());

        self::assertNull($conn);
        self::assertCount(1, $errors);
        // Specific content, not just "some non-empty string" -- proves this
        // really is the driver's authentication failure (distinct from the
        // "unknown database" branch exercised just below, which fails for a
        // completely different reason and must not report the same text).
        // Real wording (and even the username quoting style) differs per
        // driver -- MySQL's mysqli says "Access denied ... 'user'@'host'"
        // (single-quoted), Postgres says 'password authentication failed
        // for user "user"' (double-quoted), confirmed live against the
        // real server.
        if ($this->dbDriver === 'pgsql') {
            self::assertStringContainsString('password authentication failed', $errors[0]);
            self::assertStringContainsString('"' . $this->dbUser . '"', $errors[0]);
        } else {
            self::assertStringContainsString('Access denied', $errors[0]);
            self::assertStringContainsString("'" . $this->dbUser . "'", $errors[0]);
        }
        self::assertSame([], $infos);
    }

    public function test_installDbConnect_returns_null_and_records_an_error_for_an_unknown_database_name(): void
    {
        // A bogus dbname (rather than an unreachable host/IP) fails fast --
        // both drivers reply immediately once the TCP connection itself
        // succeeds (MySQL: "Unknown database"; Postgres: "database ...
        // does not exist"), unlike an unreachable host, which would
        // otherwise block on a real ~60s connect-timeout here.
        DbCredentialsTestFactory::get()->seed([
            'PIWIGO_DB_HOST' => $this->dbHost,
            'PIWIGO_DB_USER' => $this->dbUser,
            'PIWIGO_DB_PASSWORD' => $this->dbPass,
            'PIWIGO_DB_BASE' => 'piwigo_test_db_that_does_not_exist_xyz',
            'PIWIGO_DB_PREFIX' => $this->dbPrefix,
        ]);
        $infos = [];
        $errors = [];

        $conn = InstallService::installDbConnect($infos, $errors, LangTestFactory::get());

        self::assertNull($conn);
        self::assertCount(1, $errors);
        // Specific content -- proves this is really the "unknown database"
        // branch (naming the exact bogus database), not the "wrong
        // password" branch above producing an indistinguishable generic
        // string. A loose assertIsString()/assertNotSame('') check here
        // would pass even if a regression made both failure branches above
        // report the identical "Access denied" message (confirmed live
        // while writing this test: a broken credential-reseeding sequence
        // between the two test methods above produced exactly that
        // indistinguishable-failure symptom).
        self::assertStringContainsString(
            $this->dbDriver === 'pgsql' ? 'does not exist' : 'Unknown database',
            $errors[0]
        );
        self::assertStringContainsString('piwigo_test_db_that_does_not_exist_xyz', $errors[0]);
    }

    // installDbConnect()'s "your MySQL version is too old" throw (its own
    // line 131, guarding `version_compare($version, SqlDialect::
    // REQUIRED_MYSQL_VERSION, '<')`) is left uncovered: REQUIRED_MYSQL_VERSION
    // is '8.4.10' (a `public const string`, immutable -- no seam to lower
    // it from a test), and installDbConnect() builds its own Connection
    // internally via DbConnection::build() (which resolves DbCredentials
    // via its own private dbCredentials() helper) with no parameter to
    // substitute a fake one reporting an older `SELECT VERSION()` result.
    // This environment's real server is pinned to that exact floor, so it
    // can never itself report a version below it.

    // ------------------------------------------------------ activateCoreThemes

    // Doctrine\DBAL\Connection::fetchOne() is declared mixed|false -- narrow
    // for real rather than blind-casting.
    private function fetchOneInt(mixed $value): int
    {
        self::assertTrue(is_numeric($value));

        return (int) $value;
    }

    private function currentConfig(): CurrentConfig
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }

        return $currentConfig;
    }

    private function bootKernelAndConfigService(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
    }

    /**
     * AppInfo::DEFAULT_TEMPLATE ('default') is the only entry
     * activateCoreThemes() ever allowlists, but 'default' is the
     * placeholder/fallback theme, deliberately not selectable/activatable
     * -- ExtensionLifecycle::performThemeAction()'s own `$id === 'default'`
     * no-op guard (see ExtensionLifecycleTest's own
     * test_theme_activate_default_is_a_silent_noop) exempts it even when
     * the scan finds a real matching directory on disk. The real,
     * selectable core themes (e.g. modus, see ExtensionType::defaultIds())
     * aren't bundled in this repo yet, so activateCoreThemes() is
     * currently a no-op end to end -- this locks in that real, current
     * behavior rather than a hypothetical future one.
     */
    public function test_activateCoreThemes_does_not_activate_the_non_selectable_default_placeholder_theme(): void
    {
        $this->bootKernelAndConfigService();

        $themeId = AppInfo::DEFAULT_TEMPLATE;
        $themesDir = sys_get_temp_dir() . '/piwigo-install-service-themes-' . bin2hex(random_bytes(6)) . '/';
        mkdir($themesDir . $themeId, 0777, true);
        file_put_contents(
            $themesDir . $themeId . '/themeconf.inc.php',
            "<?php\n/*\nTheme Name: Default Template Test Fixture\nVersion: 3.1.4\nDescription: synthetic fixture for InstallServiceTest\nAuthor: p17-test\n*/\n"
        );
        $this->currentConfig()->setThemesDir($themesDir);

        try {
            $this->conn->executeStatement('DELETE FROM ' . Tables::themes());

            InstallService::activateCoreThemes(LangTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigServiceTestFactory::get(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), EventDispatcherTestFactory::get());

            self::assertFalse($this->conn->fetchAssociative('SELECT id FROM ' . Tables::themes() . ' WHERE id = ' . $this->conn->quote($themeId)));
            self::assertSame(0, $this->fetchOneInt($this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::themes())));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::themes());
            unlink($themesDir . $themeId . '/themeconf.inc.php');
            rmdir($themesDir . $themeId);
            rmdir($themesDir);
        }
    }

    public function test_activateCoreThemes_activates_nothing_when_no_default_template_theme_directory_is_found(): void
    {
        $this->bootKernelAndConfigService();

        $emptyThemesDir = sys_get_temp_dir() . '/piwigo-install-service-empty-themes-' . bin2hex(random_bytes(6)) . '/';
        mkdir($emptyThemesDir, 0777, true);
        // An explicitly empty scan directory proves the "nothing found on
        // disk" branch directly, regardless of what this repo's own real
        // themes/ directory currently contains.
        $this->currentConfig()->setThemesDir($emptyThemesDir);

        try {
            $this->conn->executeStatement('DELETE FROM ' . Tables::themes());

            InstallService::activateCoreThemes(LangTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigServiceTestFactory::get(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), EventDispatcherTestFactory::get());

            self::assertSame(0, $this->fetchOneInt($this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::themes())));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::themes());
            rmdir($emptyThemesDir);
        }
    }

    // ----------------------------------------------------- activateCorePlugins

    public function test_activateCorePlugins_scans_but_auto_activates_nothing(): void
    {
        $this->bootKernelAndConfigService();

        $before = $this->fetchOneInt($this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::plugins()));

        // Real production plugins/ directory (only an index.php placeholder
        // in this repo -- confirmed by direct read) -- InstallService's own
        // docblock says the auto-activate list is intentionally empty, so
        // even a directory with real scannable plugins would still insert
        // nothing; a truly empty scan directory is the simplest honest way
        // to exercise the same real "no-op by design" behavior.
        InstallService::activateCorePlugins(LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        $after = $this->fetchOneInt($this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::plugins()));
        self::assertSame($before, $after);
        self::assertSame(0, $after);
    }
}
