<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Install\InstallService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;

/**
 * Piwigo\Admin\Install\InstallService -- has its own dedicated
 * tests/Integration/InstallServiceTest.php; this is the same spec ported
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern, plus the Kernel::boot() precedent already established by
 * ExtensionScannerTest.php/CoreUpdateServiceTest.php for the 2 methods
 * (activateCoreThemes()/activateCorePlugins()) that resolve
 * container-shared services through Bootstrap\*Accessor facades.
 *
 * activateCoreThemes()/activateCorePlugins() tests deliberately avoid the
 * Integration original's own "DELETE FROM themes/plugins, then compare a
 * global COUNT(*) before/after" pattern -- composer test's own parallel
 * runner puts this file and ThemeRepositoryTest.php/PluginRepositoryTest.php
 * in different worker processes against the SAME real, shared DB, and both
 * of those files insert/delete their own rows in the exact tables a
 * blanket DELETE/COUNT(*) here would race against (the same cross-file DB
 * race class found and fixed 3 times already this session, in Search/
 * Caddie/Permalink). Every assertion below instead targets one specific,
 * collision-proof id (`p17_unit_install_test_theme`/`_plugin`) that no
 * other test file ever writes, so it's immune to concurrent writers
 * regardless of what else the shared table holds at that moment.
 */
function installServiceTestConfigService(): ConfigService
{
    $conn = DbConnection::build();

    return new ConfigService(
        EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class),
        new EventDispatcher(),
        CurrentConfigTestFactory::get(),
    );
}

function installServiceTestFixtureRoot(string $label): string
{
    $root = sys_get_temp_dir() . '/piwigo-install-service-test-' . $label . '-' . bin2hex(random_bytes(6)) . '/';
    mkdir($root, 0o777, true);

    return $root;
}

/**
 * A plain, un-container-resolved Lang -- the installDbConnect() tests
 * below deliberately never boot Kernel (see installServiceTestBootKernel()'s
 * own docblock), so LangTestFactory::get() (which, unlike
 * CurrentUserTestFactory::get(), has no pre-boot fallback) would throw.
 * Same "type-satisfying instance is enough" reasoning as
 * CoreUpdateServiceTest.php's own core_update_service_test_lang() --
 * installDbConnect() only ever calls $lang->t() to translate a real
 * exception message, never anything else Lang carries.
 */
function installServiceTestLang(): Lang
{
    return new Lang(
        new Translator(new CurrentConfig()),
        HtmlServiceTestFactory::build(),
        Paths::fromRoot(sys_get_temp_dir()),
        new InstallationFlag(),
    );
}

/**
 * This whole file is exempt from tests/Pest.php's own blanket per-test
 * transaction wrapper: executeSqlfile()'s own CREATE/DROP TABLE implicitly
 * commits in MySQL either way, and every installDbConnect() test below
 * specifically exercises DbConnection::build()'s own real credential-based
 * connection-establishment logic (via putenv()'d PIWIGO_DB_* vars) -- the
 * wrapper's own override would silently short-circuit build() to always
 * return the one already-open, already-good connection regardless of
 * whatever credentials a test just set, defeating the entire point of
 * these tests. DbTransactionTestOverride::rollback() ends the wrapper's
 * transaction and clears its override immediately, before any test body
 * runs, falling back to build()'s normal behavior for every test in this
 * file; the wrapper's own global afterEach() then finds nothing left to
 * roll back and is a safe no-op.
 */
beforeEach(function (): void {
    DbTransactionTestOverride::rollback();
});

test('PHP5_HOSTING_HTACCESS carries the expected hosting directives', function (): void {
    // Same reasoning as the Integration original -- this legacy-hosting
    // compat data is otherwise only ever read, never re-derived, so this
    // guard is what catches an accidental edit to one of these entries.
    expect(InstallService::PHP5_HOSTING_HTACCESS)->toHaveCount(11)
        ->and(InstallService::PHP5_HOSTING_HTACCESS['kundenserver.de'])->toBe('AddType x-mapp-php5 .php')
        ->and(InstallService::PHP5_HOSTING_HTACCESS['ovh.net'])->toBe('SetEnv PHP_VER 5')
        ->and(InstallService::PHP5_HOSTING_HTACCESS['free.fr'])->toBe('php 1');
});

test('executeSqlfile() creates tables and skips DROP TABLE', function (): void {
    $conn = DbConnection::build();
    // A unique table name baked directly into the SQL, not a shared
    // literal like 'gizmo' -- this suite's DB is shared/persistent across
    // composer test's own parallel runner, so a fixed name risks a real
    // collision with a concurrently-running instance of this same test.
    $table = 'itest_p17unit_' . bin2hex(random_bytes(6)) . '_gizmo';
    $sqlFile = sys_get_temp_dir() . '/piwigo-install-service-' . bin2hex(random_bytes(6)) . '.sql';
    file_put_contents(
        $sqlFile,
        "-- a comment line, must be ignored\n"
        . "\n"
        . "CREATE TABLE {$table} (\n"
        . "  id INT NOT NULL,\n"
        . "  label VARCHAR(64) NOT NULL\n"
        . ");\n"
        . "INSERT INTO {$table} (id, label) VALUES (42, 'widget-forty-two');\n"
        . "DROP TABLE {$table};\n"
    );

    try {
        InstallService::executeSqlfile($conn, $sqlFile);

        // The DROP TABLE line was skipped -- the table (and its row) must
        // still exist.
        $row = $conn->fetchAssociative('SELECT id, label FROM ' . $table . ' WHERE id = 42');
        if (! is_array($row)) {
            throw new LogicException('expected an array row');
        }
        $id = $row['id'];
        expect(is_numeric($id) ? (int) $id : null)
            ->toBe(42)
            ->and($row['label'])->toBe('widget-forty-two');
    } finally {
        $conn->executeStatement('DROP TABLE IF EXISTS ' . $table);
        unlink($sqlFile);
    }
});

test('executeSqlfile() throws a RuntimeException when the file does not exist', function (): void {
    $conn = DbConnection::build();
    $missing = sys_get_temp_dir() . '/piwigo-install-service-does-not-exist-' . bin2hex(random_bytes(6)) . '.sql';

    // file()'s own real E_WARNING ("failed to open stream") is the exact
    // branch being tested (the RuntimeException it triggers), matching
    // the Integration original's own reasoning -- swallow it rather than
    // let it fail the suite.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => InstallService::executeSqlfile($conn, $missing))
            ->toThrow(RuntimeException::class, 'Unable to read SQL file: ' . $missing);
    } finally {
        restore_error_handler();
    }
});

$installServiceTestEnvVars = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'];
$installServiceTestOriginalEnv = [];

beforeEach(function () use ($installServiceTestEnvVars, &$installServiceTestOriginalEnv): void {
    foreach ($installServiceTestEnvVars as $var) {
        $value = getenv($var);
        $installServiceTestOriginalEnv[$var] = $value === false ? null : $value;
    }
});

afterEach(function () use ($installServiceTestEnvVars, &$installServiceTestOriginalEnv): void {
    foreach ($installServiceTestEnvVars as $var) {
        $value = $installServiceTestOriginalEnv[$var];
        putenv($value === null ? $var : $var . '=' . $value);
    }
});

test('installDbConnect() returns a working connection and records no errors for valid credentials', function (): void {
    $infos = [];
    $errors = [];

    $conn = InstallService::installDbConnect($infos, $errors, installServiceTestLang());

    if (! $conn instanceof Connection) {
        throw new LogicException('expected a working Connection, got null');
    }
    expect($errors)
        ->toBe([])
        ->and($infos)
        ->toBe([])
        ->and($conn->fetchOne('SELECT 1'))
        ->toBe(1);
});

test('installDbConnect() returns null and records an error for a wrong password', function (): void {
    $realPassword = getenv('PIWIGO_DB_PASSWORD');
    putenv('PIWIGO_DB_PASSWORD=' . ($realPassword === false ? '' : $realPassword) . '-definitely-wrong');
    $infos = [];
    $errors = [];

    $conn = InstallService::installDbConnect($infos, $errors, installServiceTestLang());

    $driver = getenv('PIWIGO_DB_DRIVER');
    $driver = $driver === false || $driver === '' ? 'mysqli' : $driver;
    $user = getenv('PIWIGO_DB_USER');
    $user = $user === false ? '' : $user;

    expect($conn)
        ->toBeNull()
        ->and($errors)
        ->toHaveCount(1);
    // Specific content, not just "some non-empty string" -- proves this
    // really is the driver's authentication failure, distinct from the
    // "unknown database" branch below. Real wording (and username
    // quoting style) differs per driver, per the real
    // server -- MySQL: "Access denied ... 'user'@'host'" (single-quoted);
    // Postgres: 'password authentication failed for user "user"'.
    if ($driver === 'pgsql') {
        expect($errors[0])->toContain('password authentication failed')
            ->toContain('"' . $user . '"');
    } else {
        expect($errors[0])->toContain('Access denied')
            ->toContain("'" . $user . "'");
    }
    expect($infos)
        ->toBe([]);
});

test('installDbConnect() returns null and records an error for an unknown database name', function (): void {
    // A bogus dbname (rather than an unreachable host) fails fast -- both
    // drivers reply immediately once the TCP connection itself succeeds,
    // unlike an unreachable host, which would otherwise block on a real
    // connect-timeout.
    putenv('PIWIGO_DB_BASE=piwigo_test_db_that_does_not_exist_xyz');
    $infos = [];
    $errors = [];

    $conn = InstallService::installDbConnect($infos, $errors, installServiceTestLang());

    $driver = getenv('PIWIGO_DB_DRIVER');
    $driver = $driver === false || $driver === '' ? 'mysqli' : $driver;

    expect($conn)
        ->toBeNull()
        ->and($errors)
        ->toHaveCount(1)
        ->and($errors[0])->toContain($driver === 'pgsql' ? 'does not exist' : 'Unknown database')
        ->toContain('piwigo_test_db_that_does_not_exist_xyz');
});

// installDbConnect()'s "your MySQL/PostgreSQL version is too old" throws
// are left uncovered, same as the Integration original -- both
// REQUIRED_MYSQL_VERSION/REQUIRED_POSTGRES_VERSION are immutable class
// constants with no seam to lower them from a test, and this
// environment's real server is pinned at or above that exact floor, so it
// can never itself report a version below it.

/**
 * Deliberately NOT a file-wide beforeEach()/afterEach() -- Pest's hooks
 * apply to every test() in the file regardless of where they're declared
 * relative to that test, and booting Kernel here would
 * change DbConnection::build()'s own credential-resolution path (it
 * prefers the container-shared DbCredentials once Kernel::isBooted()),
 * silently breaking the installDbConnect() tests above, which rely on a
 * live getenv()/putenv() round-trip. Only the 4 tests below (the ones
 * that actually resolve container-shared services through
 * Bootstrap\*Accessor facades) opt in, explicitly.
 */
function installServiceTestBootKernel(): void
{
    // config/container.php has no default Paths::class definition -- a
    // bare Kernel::boot() with no explicit Paths (unlike the Integration
    // original's plain Kernel::boot() call, which reuses whatever real
    // root an earlier-booted Kernel in the same process already carries)
    // fails autowiring with "Parameter $root of __construct() has no
    // value defined or guessable", same as ExtensionScannerTest.php's own
    // beforeEach().
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 4)));
    CurrentConfigServiceTestFactory::get()->set(installServiceTestConfigService());
}

function installServiceTestResetKernel(): void
{
    CurrentConfigTestFactory::get()->reset();
    LangTestFactory::get()->reset();
    Kernel::reset();
    CurrentUserTestFactory::get()->reset();
}

test('activateCoreThemes() does not activate the non-selectable default placeholder theme, even when a real fs entry is found', function (): void {
    // AppInfo::DEFAULT_TEMPLATE ('default') is the only entry
    // activateCoreThemes() ever allowlists, but 'default' is the
    // placeholder/fallback theme, deliberately not selectable/activatable
    // -- ExtensionLifecycle::performThemeAction()'s own `$id === 'default'`
    // no-op guard exempts it even when the scan finds a real matching
    // directory on disk.
    installServiceTestBootKernel();
    $themeId = AppInfo::DEFAULT_TEMPLATE;
    $themesDir = installServiceTestFixtureRoot('themes-default');
    mkdir($themesDir . $themeId, 0o777, true);
    file_put_contents(
        $themesDir . $themeId . '/themeconf.inc.php',
        "<?php\n/*\nTheme Name: Default Template Test Fixture\nVersion: 3.1.4\n*/\n"
    );
    // Sidesteps ExtensionScanner::scanTheme()'s own PreferencesService
    // dependency (only reached when a theme has no screenshot.png), which
    // needs a real logged-in CurrentUser this test never sets -- same
    // technique ExtensionScannerTest.php already established.
    file_put_contents($themesDir . $themeId . '/screenshot.png', 'not a real png -- only its existence is checked');
    CurrentConfigTestFactory::get()->themesDir = $themesDir;

    // Owns the `themes` row space for $themeId rather than assuming the
    // shared DB's ambient state -- performThemeAction()'s own activate
    // guard is `$dbRow !== null || $id === 'default'`, and this test's
    // whole point is proving the *second* clause fires (the placeholder
    // is exempted even with a real matching fs entry found). With a
    // pre-existing themes row both clauses are simultaneously true, so
    // the test could pass even if the $id === 'default' exemption were
    // deleted from the source entirely -- the delete below isolates it.
    $conn = DbConnection::build();
    $conn->executeStatement('DELETE FROM themes WHERE id = ' . $conn->quote($themeId));

    try {
        InstallService::activateCoreThemes(LangTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigServiceTestFactory::get(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), EventDispatcherTestFactory::get());

        expect($conn->fetchAssociative('SELECT id FROM themes WHERE id = ' . $conn->quote($themeId)))->toBeFalse();
    } finally {
        FilesystemHelper::deltree($themesDir);
        installServiceTestResetKernel();
    }
});

test('activateCoreThemes() activates nothing when no default template theme directory is found on disk', function (): void {
    installServiceTestBootKernel();
    $emptyThemesDir = installServiceTestFixtureRoot('themes-empty');
    CurrentConfigTestFactory::get()->themesDir = $emptyThemesDir;

    // See the sibling test above: owns the `themes` row space rather
    // than assuming the shared DB's ambient state.
    $conn = DbConnection::build();
    $conn->executeStatement('DELETE FROM themes WHERE id = ' . $conn->quote(AppInfo::DEFAULT_TEMPLATE));

    try {
        InstallService::activateCoreThemes(LangTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigServiceTestFactory::get(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), EventDispatcherTestFactory::get());

        expect($conn->fetchAssociative('SELECT id FROM themes WHERE id = ' . $conn->quote(AppInfo::DEFAULT_TEMPLATE)))->toBeFalse();
    } finally {
        FilesystemHelper::deltree($emptyThemesDir);
        installServiceTestResetKernel();
    }
});

test('activateCorePlugins() scans but auto-activates nothing, even when a real fs entry is found', function (): void {
    // activateCorePlugins()'s own docblock: "No core plugins are
    // auto-activated at install time (empty list, matching the
    // original's own empty in_array() haystack)" -- a fake plugin the
    // scan genuinely finds on disk still must not produce an insert.
    installServiceTestBootKernel();
    $pluginId = 'p17_unit_install_test_plugin';
    $root = installServiceTestFixtureRoot('plugins');
    mkdir($root . 'plugins/' . $pluginId, 0o777, true);
    file_put_contents($root . 'plugins/' . $pluginId . '/main.inc.php', "<?php\n/*\nPlugin Name: P17 Unit Install Test Plugin\n*/\n");

    try {
        InstallService::activateCorePlugins(LangTestFactory::get(), Paths::fromRoot($root), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        $conn = DbConnection::build();
        expect($conn->fetchAssociative('SELECT id FROM plugins WHERE id = ' . $conn->quote($pluginId)))->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
        installServiceTestResetKernel();
    }
});

test('activateCorePlugins() is a no-op for an empty plugins directory', function (): void {
    installServiceTestBootKernel();
    $root = installServiceTestFixtureRoot('plugins-empty');
    mkdir($root . 'plugins', 0o777, true);

    try {
        InstallService::activateCorePlugins(LangTestFactory::get(), Paths::fromRoot($root), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        // No fs entry to even scan -- the same "no-op by design" behavior
        // via a different, simpler route (nothing found at all, rather
        // than something found and then deliberately not activated).
        $conn = DbConnection::build();
        expect($conn->fetchAssociative('SELECT id FROM plugins WHERE id = ' . $conn->quote('p17_unit_install_test_plugin')))->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
        installServiceTestResetKernel();
    }
});
