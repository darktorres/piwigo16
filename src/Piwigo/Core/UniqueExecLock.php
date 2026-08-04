<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
 *
 * pgsql support pass: PostgreSQL has the same session-scoped advisory
 * lock primitive (`pg_try_advisory_lock(bigint)`/`pg_advisory_unlock(bigint)`),
 * verified live to share every real behavior `GET_LOCK()`/`RELEASE_LOCK()`
 * has here -- reentrant per session (a 2nd `pg_try_advisory_lock()` call
 * for a name already held by *this* session succeeds again, matching
 * `test_begins_on_the_same_connection_succeeds_again_for_the_same_token`),
 * a single unlock call only decrements that reentrant count by one
 * (confirmed MySQL does this too, live: GET_LOCK() twice then
 * RELEASE_LOCK() once still leaves IS_USED_LOCK() reporting it held --
 * not a portability gap to fix, an existing property of both), and an
 * unlock call against a name this session never held is a safe no-op
 * (returns `false`, not an error, confirmed live) exactly like
 * `RELEASE_LOCK()` returning `NULL`.
 *
 * Two real differences needed real handling, not just a syntax swap:
 *  - Advisory lock names are a `bigint` key, not an arbitrary string --
 *    {@see lockKey()} takes the first 8 bytes of {@see lockName()}'s own
 *    raw (non-hex) sha1 digest and reinterprets them as a signed 64-bit
 *    int via `unpack('J', ...)` -- verified live that PHP's 'J' format
 *    (`unsigned long long, big endian`) actually reinterprets a
 *    high-bit-set value as PHP's native *signed* 64-bit int (there is no
 *    unsigned 64-bit type to represent it otherwise), which is exactly
 *    the same bit pattern Postgres's own signed `bigint` column expects
 *    -- both sides agree on the same two's-complement interpretation, so
 *    binding the raw PHP int directly as the query parameter round-trips
 *    correctly for the full bigint range (confirmed live for the most
 *    negative and most positive 64-bit values, not just typical
 *    small/positive hash outputs).
 *  - `GET_LOCK(name, timeout)`'s blocking-with-a-cap has no built-in
 *    Postgres equivalent (`pg_try_advisory_lock()` never blocks at all;
 *    `pg_advisory_lock()` blocks with no timeout option) -- {@see
 *    beginsPostgres()} emulates it with a short poll loop
 *    (`pg_try_advisory_lock()` + `usleep()`, capped by a wall-clock
 *    deadline), which correctly degrades to a single non-blocking attempt
 *    for the real, only-ever-used `$timeout = 0` default (no loop/sleep
 *    at all in that case).
 *  - `IS_USED_LOCK(name)` has no single-call Postgres equivalent either
 *    (a try-then-unlock probe would incorrectly report "not held" for
 *    the exact self-check case
 *    `test_begins_acquires_the_lock` exercises, since a same-session
 *    `pg_try_advisory_lock()` on an already-held name succeeds too, per
 *    the reentrancy note above) -- {@see isRunningPostgres()} instead
 *    queries the real `pg_locks` system view. Verified live exactly how
 *    Postgres stores a single-bigint-key advisory lock there: the 64-bit
 *    key is split into `classid` (high 32 bits) and `objid` (low 32
 *    bits), both stored as unsigned 32-bit values, with `objsubid = 1`
 *    marking the single-bigint-argument form specifically (the two-int32-
 *    argument form uses `objsubid = 2`, a different key scheme this class
 *    never uses) -- `((classid::bigint << 32) | objid::bigint) = ?`
 *    reconstructs the exact original signed bigint key entirely in SQL
 *    (verified live for both the most negative and most positive 64-bit
 *    values), so no PHP-side bit-splitting of the bound parameter is
 *    needed.
 */
final class UniqueExecLock
{
    private static ?Connection $conn = null;

    public static function begins(string $tokenName, int $timeout = 0): bool
    {
        $logger = \Piwigo\Core\CurrentLogger::getStatic();
        $conn = self::connection();

        $acquired = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? self::beginsPostgres($conn, $tokenName, $timeout)
            : $conn->fetchOne('SELECT GET_LOCK(?, ?)', [self::lockName($tokenName), $timeout]) === 1;

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

        return $conn->fetchOne('SELECT IS_USED_LOCK(?)', [self::lockName($tokenName)]) !== null;
    }

    public static function ends(string $tokenName): void
    {
        $logger = \Piwigo\Core\CurrentLogger::getStatic();
        $conn = self::connection();

        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $conn->fetchOne('SELECT pg_advisory_unlock(?)', [self::lockKey($tokenName)]);
        } else {
            $conn->fetchOne('SELECT RELEASE_LOCK(?)', [self::lockName($tokenName)]);
        }
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

    private static function beginsPostgres(Connection $conn, string $tokenName, int $timeout): bool
    {
        $key = self::lockKey($tokenName);
        $deadline = microtime(true) + $timeout;

        while (true) {
            $acquired = (bool) $conn->fetchOne('SELECT pg_try_advisory_lock(?)', [$key]);
            if ($acquired || microtime(true) >= $deadline) {
                return $acquired;
            }

            usleep(100_000);
        }
    }

    private static function isRunningPostgres(Connection $conn, string $tokenName): bool
    {
        $held = $conn->fetchOne(
            'SELECT 1 FROM pg_locks WHERE locktype = ? AND objsubid = ? AND ((classid::bigint << 32) | objid::bigint) = ?',
            ['advisory', 1, self::lockKey($tokenName)]
        );

        return $held !== false;
    }

    private static function lockName(string $tokenName): string
    {
        $prefix = DbCredentials::current()->prefix;

        return 'piwigo_exec_' . sha1($prefix . ':unique_exec:' . $tokenName);
    }

    private static function lockKey(string $tokenName): int
    {
        $prefix = DbCredentials::current()->prefix;
        $rawHash = sha1($prefix . ':unique_exec:' . $tokenName, true);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr($rawHash, 0, 8));

        return $unpacked[1];
    }
}
