<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;

/**
 * P23 batch 8d: DB-row-backed "only one execution at a time" lock,
 * relocated from include/functions.inc.php -- no natural existing class
 * home, stateless beyond the config-table row it reads/writes through.
 *
 * Legacy Coupling Retirement: DI+DBAL migration Phase 1e -- retargeted
 * onto `DbConnection` (same L1Infrastructure layer, constructed inline
 * per static method, no instance state).
 *
 * Phase 4 Item 18: replaced the hand-rolled `INSERT IGNORE` + re-`SELECT`
 * race-resolution dance + manual `time() - $running_exec_start_time >
 * $timeout` staleness check with MySQL's own native advisory lock
 * primitive -- `GET_LOCK(name, timeout)`/`RELEASE_LOCK(name)`/
 * `IS_USED_LOCK(name)`, one atomic server-side call each, no re-read
 * needed to find out who won (`GET_LOCK()` itself returns whether *this*
 * call acquired it). A real behavioral improvement, not just a different
 * syntax for the same thing: `GET_LOCK()` locks are scoped to the
 * database *connection* and MySQL releases them automatically the instant
 * that connection closes -- including an abnormal PHP process death, not
 * just a clean `ends()` call. This codebase's connections are per-request
 * (`DbConnection::build()`, no persistent connection pooling across
 * requests), so "released when the connection closes" maps exactly onto
 * "released when this request ends" -- the exact semantic this class
 * wants, with no stale-lock cleanup logic needed at all: a crashed
 * process between acquiring and its own `ends()` call can no longer leave
 * a stale row sitting around for a later caller's timeout check to
 * (maybe) notice.
 *
 * `$timeout` changes meaning accordingly -- no longer "how old can an
 * existing marker get before being force-cleared," now `GET_LOCK()`'s own
 * "how long to wait for a lock currently held by another connection
 * before giving up." Defaults to 0 (return immediately, never block), not
 * `GET_LOCK()`'s own default-suggestive 60: the original `INSERT IGNORE`
 * check both real callers relied on was always instant/non-blocking --
 * "someone else is already running this" was meant to make the *current*
 * request skip immediately, not sit blocked for up to a minute waiting
 * for the other one to finish (caught live: a first attempt defaulting to
 * 60 made a contended `PageTailTest` case take over 60 real seconds).
 * Both real callers (`Admin\PiwigoInfosSender`'s `'send_piwigo_infos'`,
 * `Bootstrap\PageTail`'s `'check_for_updates'`) only ever check
 * `begins()`'s return for truthiness, never consumed the old `string`
 * exec-id value for anything else -- return type simplifies
 * to a plain `bool`.
 *
 * `GET_LOCK()` names are capped at 64 characters by MySQL (a real
 * `mysqli_sql_exception`, not silent truncation -- found live in Item 1's
 * own image-upload uniqueness-check fix). $tokenName isn't a fixed,
 * known-short constant structurally (both real callers happen to pass a
 * short literal today, but the parameter itself is a generic string), so
 * {@see lockName()} hashes it rather than concatenating -- folding in the
 * DB table prefix as part of the hashed input, not just a literal string
 * prefix, for the same shared-MySQL-server collision-avoidance reasoning
 * the class-wide lock scoping already needs (`GET_LOCK()` names are global
 * to the whole MySQL *server*, not scoped to one database/schema).
 *
 * `GET_LOCK()`/`RELEASE_LOCK()` are scoped to the physical connection that
 * issued them -- confirmed empirically that `DbConnection::build()` opens
 * a genuinely new one on every call (a pure factory, no pooling), so
 * `begins()` and a later `ends()` call must share one connection or
 * `RELEASE_LOCK()` silently no-ops against a connection that never held
 * the lock (returns `NULL`, confirmed live) and the lock would only ever
 * clear via that first, otherwise-unreferenced connection getting
 * garbage-collected -- unpredictably soon, not "for the duration of this
 * operation." {@see connection()} lazily opens and caches one connection
 * per request instead, matching this class's own "released when the
 * request ends" contract precisely.
 */
final class UniqueExecLock
{
    private static ?Connection $conn = null;

    public static function begins(string $tokenName, int $timeout = 0): bool
    {
        $logger = \Piwigo\Core\CurrentLogger::getStatic();

        $acquired = self::connection()
            ->fetchOne('SELECT GET_LOCK(?, ?)', [self::lockName($tokenName), $timeout]);
        if ($acquired !== 1) {
            $logger->info('[' . $tokenName . '] another execution is running, abort');

            return false;
        }

        $logger->info('[' . $tokenName . '] acquired the lock');

        return true;
    }

    public static function isRunning(string $tokenName): bool
    {
        return self::connection()
            ->fetchOne('SELECT IS_USED_LOCK(?)', [self::lockName($tokenName)]) !== null;
    }

    public static function ends(string $tokenName): void
    {
        $logger = \Piwigo\Core\CurrentLogger::getStatic();

        self::connection()
            ->fetchOne('SELECT RELEASE_LOCK(?)', [self::lockName($tokenName)]);
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

    private static function lockName(string $tokenName): string
    {
        $prefix = DbCredentials::current()->prefix;

        return 'piwigo_exec_' . sha1($prefix . ':unique_exec:' . $tokenName);
    }
}
