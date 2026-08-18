<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;

/**
 * "Only one execution at a time" lock built on the database's own
 * session-scoped advisory lock primitive: MySQL's
 * `GET_LOCK()`/`RELEASE_LOCK()`/`IS_USED_LOCK()`, PostgreSQL's
 * `pg_try_advisory_lock()`/`pg_advisory_unlock()` plus a `pg_locks`
 * lookup, or -- SQLite has no server-side session-lock primitive at all
 * (an embedded, file-based engine with no persistent server process to
 * hold session state) -- PHP's own `flock()` against a real lock file,
 * see {@see \Piwigo\Db\AdvisorySessionLock}'s own docblock.
 *
 * Advisory locks are scoped to the connection that acquired them and are
 * released automatically when that connection closes -- including an
 * abnormal process death, not just a clean `ends()` call. Since
 * `DbConnection::build()` opens a fresh connection per request with no
 * pooling, {@see connection()} caches one connection per request so that
 * `begins()` and a later `ends()` call share it; otherwise
 * `RELEASE_LOCK()` would silently no-op against a connection that never
 * held the lock.
 *
 * `$timeout` is how long the lock primitive waits for a lock currently
 * held by another connection before giving up. It defaults to 0 (return
 * immediately, never block): callers use a failed `begins()` to skip the
 * current request immediately when another execution is already running,
 * not to wait for it to finish.
 *
 * `GET_LOCK()` names are capped at 64 characters by MySQL and are global
 * to the whole MySQL server, not scoped to one database/schema. {@see
 * lockName()} hashes `$tokenName` together with the database name rather
 * than concatenating, keeping names short and collision-safe across
 * databases sharing one server.
 *
 * Both the MySQL and Postgres primitives are reentrant per session: an
 * already-held name can be acquired again by the same session, and a
 * single unlock call only decrements that count by one. An unlock call
 * for a name the session never held is a safe no-op, not an error.
 *
 * Cross-database differences are handled in
 * {@see \Piwigo\Db\AdvisorySessionLock}:
 *  - Postgres advisory lock names are a signed bigint key, not an
 *    arbitrary string. `AdvisorySessionLock::key()` takes the first 8
 *    bytes of the lock name's sha1 digest and reinterprets them as a
 *    signed 64-bit int (PHP's `unpack('J', ...)` format), matching
 *    Postgres's own two's-complement bigint column so the raw PHP int can
 *    be bound directly as the query parameter.
 *  - Postgres has no built-in equivalent of `GET_LOCK()`'s
 *    blocking-with-a-timeout: `AdvisorySessionLock::acquire()` emulates it
 *    with a `pg_try_advisory_lock()` poll loop capped by a wall-clock
 *    deadline, degrading to a single non-blocking attempt when `$timeout`
 *    is 0.
 *  - Postgres has no single-call equivalent of `IS_USED_LOCK()`: a
 *    try-then-unlock probe would misreport "not held" due to reentrancy,
 *    so {@see isRunningPostgres()} queries the `pg_locks` system view
 *    instead. A single-bigint-key advisory lock is stored there with the
 *    key split into `classid` (high 32 bits) and `objid` (low 32 bits),
 *    both unsigned, and `objsubid = 1` marking the single-bigint-argument
 *    form; `((classid::bigint << 32) | objid::bigint) = ?` reconstructs
 *    the original signed bigint key in SQL.
 *  - SQLite's `flock()`-based lock has no single-call `IS_USED_LOCK()`
 *    equivalent either, and no reentrancy problem to work around the way
 *    Postgres's own try-then-unlock probe would have -- a fresh `fopen()`
 *    always gets its own independent open file description, so
 *    {@see isRunningSqlite()}'s own try-then-unlock probe correctly
 *    contends even against a lock this same connection already holds via
 *    a different handle.
 */
final class UniqueExecLock
{
    private static ?Connection $conn = null;

    public static function begins(Logger $logger, string $tokenName, int $timeout = 0): bool
    {
        $conn = self::connection();

        $acquired = AdvisorySessionLock::acquire($conn, self::lockName($tokenName), $timeout);

        if (! $acquired) {
            $logger->info('[' . $tokenName . '] another execution is running, abort');

            return false;
        }

        $logger->info('[' . $tokenName . '] acquired the lock');

        return true;
    }

    public static function isRunning(string $tokenName): bool
    {
        $conn = self::connection();

        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return self::isRunningPostgres($conn, $tokenName);
        }

        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            return self::isRunningSqlite($tokenName);
        }

        return $conn->fetchOne(<<<SQL
            SELECT IS_USED_LOCK(?)
            SQL
            , [self::lockName($tokenName)]) !== null;
    }

    public static function ends(Logger $logger, string $tokenName): void
    {
        $conn = self::connection();

        AdvisorySessionLock::release($conn, self::lockName($tokenName));
        $logger->info('[' . $tokenName . '] ends now');
    }

    /**
     * Test isolation only -- clears the cached connection between test
     * cases, same convention as every other static-state class's own
     * `reset()` (see StructuralTest's own "only called from tests/"
     * checks). No real production caller should ever need this: one
     * connection per request is exactly what this class wants.
     */
    public static function reset(): void
    {
        self::$conn = null;
    }

    private static function connection(): Connection
    {
        return self::$conn ??= DbConnection::build();
    }

    private static function isRunningPostgres(Connection $conn, string $tokenName): bool
    {
        // staabm/phpstan-dba misreads `::bigint`'s second colon as a named
        // placeholder; the only real placeholder is `?`.
        // @phpstan-ignore dba.syntaxError
        $held = $conn->fetchOne(
            'SELECT 1 FROM pg_locks WHERE locktype = ? AND objsubid = ? AND ((classid::bigint << 32) | objid::bigint) = ?',
            ['advisory', 1, AdvisorySessionLock::key(self::lockName($tokenName))]
        );

        return $held !== false;
    }

    /**
     * SQLite has no `IS_USED_LOCK()`/`pg_locks` equivalent -- a second,
     * independent, throwaway `flock()` attempt on a fresh handle to the
     * same lock file (via {@see AdvisorySessionLock::sqliteLockFilePath()},
     * the identical path acquire()/release() use) is the real probe:
     * succeeding means nobody (not even this same connection, a fresh
     * `fopen()` gets its own independent open file description, see
     * AdvisorySessionLock's own docblock) currently holds it, so it's
     * immediately released and reported not-running; failing means
     * something else does.
     */
    private static function isRunningSqlite(string $tokenName): bool
    {
        $handle = fopen(AdvisorySessionLock::sqliteLockFilePath(self::lockName($tokenName)), 'c');
        if ($handle === false) {
            return false;
        }

        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return ! $acquired;
    }

    /**
     * Direct container resolve, not the DbCredentials::current() shim --
     * this class is a purely static utility (no instance to speak of, see
     * this class's own docblock), matching FilesystemHelper's own
     * established "no wrapper instance" precedent. Mirrors
     * DbCredentials::current()'s own graceful degradation (a fresh
     * fromEnv() read, not a throw) when Kernel isn't booted.
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

    private static function lockName(string $tokenName): string
    {
        $database = self::dbCredentials()->database;

        return 'piwigo_exec_' . sha1($database . ':unique_exec:' . $tokenName);
    }
}
