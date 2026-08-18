<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Core\Kernel;
use SQLite3;

/**
 * Factory for the shared Doctrine DBAL connection.
 *
 * Pins the session `sql_mode` to {@see SQL_MODE} on every MySQL/MariaDB
 * connection, rather than inheriting whatever the server is configured
 * with.
 *
 * This does NOT relax the mode the way the reference implementation's
 * equivalent DbConnection does -- that one drops ONLY_FULL_GROUP_BY to let
 * invalid GROUP BY queries through. It does the opposite: every caller here
 * was rewritten to stay valid under strict mode (see e.g. SearchService,
 * SectionPopulator, CategoryRepository, CalendarRepository,
 * CommentRepository), and pinning makes that a guarantee instead of an
 * assumption about someone else's server configuration.
 *
 * An earlier version of this docblock claimed "a literal grep gate bans
 * exactly that class of session-mode mutation from `src/`". No such gate
 * exists anywhere in this repository -- not in tests/Arch, CI, lefthook or
 * tools -- and the same paragraph named the setting it claimed to be
 * avoiding naming. Removed rather than reproduced.
 *
 * Also deliberately does NOT call Kernel::service() (the reference's
 * DbConnection::get() does) -- v17's own architectural rule bans the
 * service locator. This is a pure factory; the container
 * wires it as a Connection::class entry.
 */
final class DbConnection
{
    /**
     * The session `sql_mode` every MySQL/MariaDB connection is pinned to.
     *
     * This is MySQL 8.4's own default minus `ERROR_FOR_DIVISION_BY_ZERO`,
     * which that version deprecates (its behaviour is folded into strict
     * mode). Verified accepted verbatim, and normalised identically, by both
     * MySQL 8.4 and MariaDB 12.
     *
     * Pinning matters most for MariaDB, whose default is materially weaker:
     * `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,
     * NO_ENGINE_SUBSTITUTION` -- no `ONLY_FULL_GROUP_BY` and no
     * `NO_ZERO_DATE`/`NO_ZERO_IN_DATE`. Without this, a query invalid under
     * `ONLY_FULL_GROUP_BY` fails on MySQL but passes on MariaDB, and the
     * zero-date sentinel (`'0000-00-00 00:00:00'`, see `Image\ImageEntity`'s
     * own docblock) remains writable there.
     */
    private const string SQL_MODE = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

    /**
     * Test-only -- restricted to tests/ by an arch test (see
     * StructuralTest.php's own "X::reset() is only called from tests/"
     * convention, applied here to useTestOverride() instead). Lets the
     * Unit suite's own per-test transaction wrapper
     * (tests/Support/DbTransactionTestOverride.php) make every build()
     * call anywhere in a test's call graph -- test code, service
     * internals, container-resolved Connection::class -- transparently
     * return the same already-in-a-transaction connection, without every
     * caller needing to thread one connection through by hand. Mirrors
     * Env::now()'s own PIWIGO_TEST_NOW branch: a narrow, explicit
     * test-mode seam baked directly into otherwise-production code, not
     * a mock or a facade.
     */
    private static ?Connection $testOverride = null;

    /**
     * Direct container resolve, not the DbCredentials::current() shim --
     * this class is a purely static factory (see this class's own
     * docblock), matching FilesystemHelper's own established "no wrapper
     * instance" precedent. Mirrors DbCredentials::current()'s own graceful
     * degradation (a fresh fromEnv() read, not a throw) when Kernel isn't
     * booted -- most callers of build() are plain Unit tests that never
     * boot a Kernel at all.
     */
    private static function dbCredentials(): DbCredentials
    {
        if (Kernel::isBooted()) {
            $dbCredentials = Kernel::container()->get(DbCredentials::class);
            if ($dbCredentials instanceof DbCredentials) {
                return $dbCredentials;
            }
        }

        return DbCredentials::fromEnv();
    }

    public static function build(): Connection
    {
        if (self::$testOverride instanceof Connection) {
            return self::$testOverride;
        }

        $params = self::params();
        $conn = DriverManager::getConnection($params);

        if ($params['driver'] === 'sqlite3') {
            self::initSqliteConnection($conn);
        }

        return $conn;
    }

    /**
     * SQLite-specific per-connection setup, run once right after a real
     * connection opens (this class opens a fresh connection per request,
     * see its own docblock, so this runs on every request, matching
     * MYSQLI_INIT_COMMAND's own "every (re)connect" role for MySQL's
     * pinned sql_mode).
     *
     * `PRAGMA foreign_keys = ON`: SQLite disables FK enforcement by
     * default per-connection, unlike MySQL/PostgreSQL where it's always
     * on -- a real, easy-to-miss gotcha, not a stylistic default.
     *
     * REGEXP UDF: DBAL's `SQLitePlatform::getRegexpExpression()` emits
     * the literal SQL text `REGEXP`, assuming SQLite has REGEXP support
     * built in -- it doesn't; an unregistered `REGEXP` query fails at
     * runtime with "no such function: REGEXP". `SQLite3::createFunction()`
     * (verified live against a real connection before writing this)
     * registers a PHP-callback-backed `regexp` function SQLite then
     * calls for every `REGEXP` comparison, closing that gap without
     * needing an external SQLite extension.
     *
     * Returns a real `int` (0/1), not `bool` -- a real, previously-live
     * bug found and fixed while verifying `Db\DqlFunction\RegexpFunction`
     * end to end (Wave 5 of the SQLite campaign): `SQLite3::
     * createFunction()` stores a PHP `bool` return value as SQLite `TEXT`
     * (`'1'`/`''`, confirmed live via `typeof()`), not `INTEGER`. Every
     * real production `REGEXP(...)` caller in this codebase (e.g.
     * `CategoryRepository`'s own `uppercats` ancestor-matching queries)
     * compiles to `col REGEXP pattern = 1` (an `INTEGER` literal, DQL's
     * own boolean-comparison convention here) -- comparing SQLite's own
     * `TEXT` `'1'` against the `INTEGER` `1` is false under SQLite's
     * comparison rules (no implicit numeric coercion for a bare,
     * affinity-less function result), so every one of those real queries
     * silently matched zero rows before this fix, confirmed live both
     * ways: `bool` return -> `WHERE name REGEXP ? = 1` finds nothing even
     * against a real matching row; `int` return -> finds it correctly.
     */
    private static function initSqliteConnection(Connection $conn): void
    {
        $native = $conn->getNativeConnection();
        if ($native instanceof SQLite3) {
            $native->createFunction('regexp', static function (string $pattern, ?string $subject): int {
                return $subject !== null && preg_match('/' . str_replace('/', '\/', $pattern) . '/ui', $subject) === 1 ? 1 : 0;
            });
        }

        $conn->executeStatement('PRAGMA foreign_keys = ON');
    }

    /**
     * Test-only -- see $testOverride's own docblock above.
     */
    public static function useTestOverride(?Connection $connection): void
    {
        self::$testOverride = $connection;
    }

    /**
     * Split from build() so the driver/host branching is testable as a
     * plain array without touching a getter Doctrine\DBAL\Connection
     * itself marks as implementation-detail-only. The precise shape (not
     * a generic array<string,mixed>) is what lets PHPStan verify it
     * against DriverManager::getConnection()'s own sealed parameter shape
     * at the build() call site.
     *
     * Native drivers only (mysqli, pgsql, sqlite3) -- not pdo_mysql/
     * pdo_pgsql/pdo_sqlite, matching docs/REFERENCE.md's
     * native-platform-first library policy and the precedent already set
     * by mysqli. MariaDB speaks the same wire protocol as MySQL, so it
     * shares the mysqli branch; there is no separate 'mariadb' driver
     * value. `sqlite3` (DBAL's `Driver\SQLite3\Driver`, wrapping PHP's
     * native `SQLite3` class) was chosen over `pdo_sqlite` for the same
     * native-first reason, verified directly against a real connection
     * before committing to it: `SQLite3::createFunction()` (used in
     * {@see initSqliteConnection()}) registers a REGEXP UDF exactly like
     * PDO's own `sqliteCreateFunction()` would.
     *
     * `driverOptions` carries a bool (MYSQLI_OPT_INT_AND_FLOAT_NATIVE) and a
     * string (MYSQLI_INIT_COMMAND), hence the `bool|string` value type.
     *
     * @return array{driver: 'mysqli', user: string, password: string, dbname: string, charset: string, driverOptions: array<int, bool|string>, host?: string, unix_socket?: string, port?: int}|array{driver: 'pgsql', user: string, password: string, dbname: string, host: string, port?: int}|array{driver: 'sqlite3', path: string}
     */
    public static function params(): array
    {
        $credentials = self::dbCredentials();
        $host = $credentials->host;
        $port = $credentials->port;

        if ($credentials->driver === 'sqlite3') {
            // $database doubles as the SQLite file path for this driver
            // -- see DbCredentials's own docblock. host/user/password/port
            // are meaningless for a file-based target and stay unused.
            return [
                'driver' => 'sqlite3',
                'path' => $credentials->database,
            ];
        }

        if ($credentials->driver === 'pgsql') {
            // pg_connect() accepts a Unix socket directory directly via
            // 'host' (unlike mysqli, PostgreSQL has no separate
            // unix_socket param) -- no branching needed here.
            $params = [
                'driver' => 'pgsql',
                'user' => $credentials->user,
                'password' => $credentials->password,
                'dbname' => $credentials->database,
                'host' => $host,
            ];
            if ($port !== null) {
                $params['port'] = $port;
            }

            return $params;
        }

        $params = [
            'driver' => 'mysqli',
            'user' => $credentials->user,
            'password' => $credentials->password,
            'dbname' => $credentials->database,
            'charset' => 'utf8mb4',
            // Return native int/float types instead of strings for
            // integer and floating-point columns. Without this, mysqli
            // stringifies every numeric column and every read site must
            // re-cast.
            'driverOptions' => [
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
                // Pin the session sql_mode instead of inheriting the
                // server's. This codebase depends on strict mode for
                // correctness -- see this class's own docblock -- and that
                // dependency was previously an unverified assumption about
                // someone else's server configuration.
                //
                // Runs on connect *and* on reconnect, which a one-off
                // `SET SESSION` after connecting would not.
                MYSQLI_INIT_COMMAND => "SET SESSION sql_mode='" . self::SQL_MODE . "'",
            ],
        ];

        // A host starting with '/' is treated as a Unix socket path.
        if (str_starts_with($host, '/')) {
            $params['unix_socket'] = $host;
        } else {
            $params['host'] = $host;
        }
        if ($port !== null) {
            $params['port'] = $port;
        }

        return $params;
    }
}
