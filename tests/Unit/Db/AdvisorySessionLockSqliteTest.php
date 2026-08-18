<?php

declare(strict_types=1);

use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Db\AdvisorySessionLock's real SQLite branch (Wave 3 of the
 * SQLite campaign) -- SQLite has no server-side advisory-lock primitive
 * at all (an embedded, file-based engine with no persistent server
 * process to hold session state), so this exercises PHP's own
 * `flock()`-based real equivalent end to end: a real lock file, real
 * cross-connection contention (two independent DBAL Connection objects
 * against the same in-process `:memory:` database resolve to the same
 * lock file, see AdvisorySessionLock::sqliteLockFilePath()'s own
 * `:memory:` fallback), real timeout-blocking, real reentrancy.
 *
 * DbTransactionTestOverride::rollback() as beforeEach()'s first line,
 * same reason as DbConnectionTest.php's own sqlite3 tests -- without
 * it, DbConnection::build() would transparently return the global
 * Unit-suite override's real mysqli/pgsql test connection instead of a
 * genuine sqlite3 one.
 */
beforeEach(function (): void {
    DbTransactionTestOverride::rollback();
    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=:memory:');
});

afterEach(function (): void {
    putenv('PIWIGO_DB_DRIVER');
    putenv('PIWIGO_DB_BASE');
});

test('acquire() succeeds when nobody else holds the lock', function (): void {
    $conn = DbConnection::build();
    $lockName = 'piwigo_test_' . bin2hex(random_bytes(4));

    expect(AdvisorySessionLock::acquire($conn, $lockName))->toBeTrue();

    AdvisorySessionLock::release($conn, $lockName);
});

test('acquire() is reentrant for the same connection', function (): void {
    $conn = DbConnection::build();
    $lockName = 'piwigo_test_' . bin2hex(random_bytes(4));

    expect(AdvisorySessionLock::acquire($conn, $lockName))->toBeTrue()
        ->and(AdvisorySessionLock::acquire($conn, $lockName))->toBeTrue();

    AdvisorySessionLock::release($conn, $lockName);
});

test('acquire() fails immediately for a genuinely different connection when timeout is 0', function (): void {
    $connA = DbConnection::build();
    $connB = DbConnection::build();
    $lockName = 'piwigo_test_' . bin2hex(random_bytes(4));

    expect(AdvisorySessionLock::acquire($connA, $lockName))->toBeTrue()
        ->and(AdvisorySessionLock::acquire($connB, $lockName, 0))->toBeFalse();

    AdvisorySessionLock::release($connA, $lockName);
});

test('acquire() blocks up to the timeout then fails when another connection still holds it', function (): void {
    $connA = DbConnection::build();
    $connB = DbConnection::build();
    $lockName = 'piwigo_test_' . bin2hex(random_bytes(4));

    expect(AdvisorySessionLock::acquire($connA, $lockName))->toBeTrue();

    $start = microtime(true);
    $acquiredByB = AdvisorySessionLock::acquire($connB, $lockName, 1);
    $elapsed = microtime(true) - $start;

    expect($acquiredByB)
        ->toBeFalse()
        ->and($elapsed)
        ->toBeGreaterThanOrEqual(0.9);

    AdvisorySessionLock::release($connA, $lockName);
});

test('release() lets a different connection acquire the same name afterwards', function (): void {
    $connA = DbConnection::build();
    $connB = DbConnection::build();
    $lockName = 'piwigo_test_' . bin2hex(random_bytes(4));

    expect(AdvisorySessionLock::acquire($connA, $lockName))->toBeTrue();
    AdvisorySessionLock::release($connA, $lockName);

    expect(AdvisorySessionLock::acquire($connB, $lockName))->toBeTrue();

    AdvisorySessionLock::release($connB, $lockName);
});

test('release() on a name that was never held is a no-op, not an error', function (): void {
    $conn = DbConnection::build();
    $lockName = 'piwigo_test_never_held_' . bin2hex(random_bytes(4));

    AdvisorySessionLock::release($conn, $lockName);

    // Proves the no-op left nothing in a broken state, not just that it
    // didn't throw -- a real acquire against the same name still works.
    expect(AdvisorySessionLock::acquire($conn, $lockName))->toBeTrue();

    AdvisorySessionLock::release($conn, $lockName);
});

test('independent lock names never contend with each other', function (): void {
    $conn = DbConnection::build();
    $lockNameA = 'piwigo_test_a_' . bin2hex(random_bytes(4));
    $lockNameB = 'piwigo_test_b_' . bin2hex(random_bytes(4));

    expect(AdvisorySessionLock::acquire($conn, $lockNameA))->toBeTrue()
        ->and(AdvisorySessionLock::acquire($conn, $lockNameB))->toBeTrue();

    AdvisorySessionLock::release($conn, $lockNameA);
    AdvisorySessionLock::release($conn, $lockNameB);
});
