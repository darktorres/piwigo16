<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

/**
 * Shared MySQL `GET_LOCK()`/`RELEASE_LOCK()` <->
 * Postgres `pg_try_advisory_lock()`/`pg_advisory_unlock()` <-> SQLite
 * `flock()` translation, extracted from `Piwigo\Core\UniqueExecLock`
 * (its own docblock has the full research trail: reentrancy per
 * session, the `unpack('J', ...)` bigint-key derivation, why a poll
 * loop is needed to emulate `GET_LOCK()`'s blocking-with-a-timeout
 * since `pg_try_advisory_lock()` never blocks at all). `UniqueExecLock`
 * and `Admin\Upload\UploadService::upload()`'s duplicate-detection lock
 * each build their own already-hashed, MySQL-shaped lock-name string
 * (capped at MySQL's own 64-character `GET_LOCK()` limit) and use it
 * verbatim for the MySQL branch -- {@see key()} re-hashes that same
 * string for the Postgres branch's bigint key, and {@see
 * sqliteLockFilePath()} reuses it verbatim as a filename for the SQLite
 * branch, so every caller only ever has to build and carry one string.
 *
 * SQLite has no server-side advisory-lock primitive at all -- it's an
 * embedded, file-based engine with no persistent server process to hold
 * session state, unlike MySQL/Postgres's own connection-scoped locks.
 * The real equivalent is PHP's own `flock()` against a lock file living
 * alongside the SQLite database file: one file per $lockName (not one
 * shared file for the whole database), since distinct lock names must
 * stay independently acquirable, not serialize on each other the way a
 * single shared file would force. `flock()`'s own OS-level guarantee --
 * released when the holding file descriptor closes, including on
 * abnormal process death, not just an explicit unlock call -- is the
 * same "no staleness" property `GET_LOCK()`/`pg_try_advisory_lock()`
 * already give every other platform, if anything a stronger one (a
 * process crash releases it too, not just a clean connection close).
 * Per-connection reentrancy (the same $lockName re-acquired without an
 * intervening release succeeding immediately, see this class's own
 * MySQL/Postgres precedent) is handled by {@see self::$sqliteHandles}:
 * a second acquireSqlite() call for a $conn/$lockName pair already in
 * that registry returns true immediately without a second flock() call.
 */
final class AdvisorySessionLock
{
    /**
     * Keyed by `spl_object_id($conn) . ':' . $lockName` -- open file
     * descriptions, not just file descriptor numbers, are what `flock()`
     * contends on (confirmed live: two independent `fopen()` calls to
     * the same path, even from the same PHP process, correctly contend
     * with each other), so a second real connection acquiring the same
     * $lockName needs its own independent handle to correctly simulate
     * cross-connection contention, while the SAME connection re-acquiring
     * reuses its own registered handle (the reentrancy case above).
     *
     * @var array<string, resource>
     */
    private static array $sqliteHandles = [];

    public static function acquire(Connection $conn, string $lockName, int $timeoutSeconds = 0): bool
    {
        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return self::acquirePostgres($conn, $lockName, $timeoutSeconds);
        }

        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            return self::acquireSqlite($conn, $lockName, $timeoutSeconds);
        }

        if (! $conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new LogicException(self::class . ' has no lock implementation for platform ' . $conn->getDatabasePlatform()::class);
        }

        return $conn->fetchOne(<<<SQL
            SELECT GET_LOCK(?, ?)
            SQL
            , [$lockName, $timeoutSeconds]) === 1;
    }

    public static function release(Connection $conn, string $lockName): void
    {
        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $conn->fetchOne(<<<SQL
                SELECT pg_advisory_unlock(?)
                SQL
                , [self::key($lockName)]);

            return;
        }

        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            self::releaseSqlite($conn, $lockName);

            return;
        }

        if (! $conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new LogicException(self::class . ' has no lock implementation for platform ' . $conn->getDatabasePlatform()::class);
        }

        $conn->fetchOne(<<<SQL
            SELECT RELEASE_LOCK(?)
            SQL
            , [$lockName]);
    }

    private static function acquirePostgres(Connection $conn, string $lockName, int $timeoutSeconds): bool
    {
        $key = self::key($lockName);
        $deadline = microtime(true) + (float) $timeoutSeconds;

        while (true) {
            $acquired = (bool) $conn->fetchOne(<<<SQL
                SELECT pg_try_advisory_lock(?)
                SQL
                , [$key]);
            if ($acquired || microtime(true) >= $deadline) {
                return $acquired;
            }

            usleep(100_000);
        }
    }

    private static function acquireSqlite(Connection $conn, string $lockName, int $timeoutSeconds): bool
    {
        $registryKey = spl_object_id($conn) . ':' . $lockName;
        if (isset(self::$sqliteHandles[$registryKey])) {
            return true;
        }

        $handle = fopen(self::sqliteLockFilePath($lockName), 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + (float) $timeoutSeconds;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                return false;
            }

            usleep(100_000);
        }

        self::$sqliteHandles[$registryKey] = $handle;

        return true;
    }

    private static function releaseSqlite(Connection $conn, string $lockName): void
    {
        $registryKey = spl_object_id($conn) . ':' . $lockName;
        $handle = self::$sqliteHandles[$registryKey] ?? null;
        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        unset(self::$sqliteHandles[$registryKey]);
    }

    /**
     * Postgres advisory lock names are a `bigint` key, not an arbitrary
     * string -- first 8 bytes of the raw (non-hex) sha1 digest,
     * reinterpreted as a signed 64-bit int via `unpack('J', ...)`.
     * {@see \Piwigo\Core\UniqueExecLock}'s own docblock has the full
     * verification trail for why this round-trips correctly across the
     * entire bigint range.
     */
    public static function key(string $lockName): int
    {
        $rawHash = sha1($lockName, true);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr($rawHash, 0, 8));

        return $unpacked[1];
    }

    /**
     * `<dir>/<lockName>.lock`, living alongside the real SQLite database
     * file -- $lockName is always an already-hashed, filename-safe
     * string in every real caller (`piwigo_exec_<sha1>`,
     * `piwigo_iud_<sha1>`, etc.), so no further escaping is needed.
     *
     * Reads the path via `DbConnection::params()`, not `$conn->
     * getParams()` -- the latter is a real Doctrine `@internal` API
     * (confirmed via a real PHPStan run, not assumed), off limits from
     * outside the Doctrine root namespace per this codebase's own
     * established policy (see `InstallWizard.php`'s own docblock on the
     * same rule). `DbConnection::params()` exists specifically as the
     * sanctioned, public replacement -- its own docblock says so
     * verbatim ("testable as a plain array without touching a getter
     * Doctrine\DBAL\Connection itself marks as implementation-detail-
     * only"). Every real caller's own `$conn` was itself built via
     * `DbConnection::build()`, so this always reflects the same
     * credentials that connection was opened with -- not actually
     * introspecting the passed connection at all, hence no $conn
     * parameter here despite acquire()/release() themselves needing one
     * for the platform check and the handle registry key.
     *
     * `:memory:` (a testing-only convenience, per `DbCredentials`'s own
     * docblock -- no real deployment ever uses it) has no real directory
     * to live alongside -- falls back to the system temp directory.
     *
     * Exposed publicly (not just used internally by acquire()/release())
     * since {@see \Piwigo\Core\UniqueExecLock}'s own isRunning() needs
     * the identical path to open its own independent, throwaway probe
     * handle.
     */
    public static function sqliteLockFilePath(string $lockName): string
    {
        $params = DbConnection::params();
        $path = $params['driver'] === 'sqlite3' ? $params['path'] : '';
        $dir = $path === '' || $path === ':memory:' ? sys_get_temp_dir() : dirname($path);

        return $dir . '/' . $lockName . '.lock';
    }
}
