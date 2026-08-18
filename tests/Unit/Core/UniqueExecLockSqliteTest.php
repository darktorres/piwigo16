<?php

declare(strict_types=1);

use Piwigo\Core\Logger;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Core\UniqueExecLock's real SQLite branch (Wave 3 of the
 * SQLite campaign) -- mirrors UniqueExecLockTest.php's own
 * begins()/isRunning()/ends() coverage, plus the cross-connection
 * contention case that class's own docblock says is "too flaky/invasive"
 * to attempt against the shared mysqli/pgsql test connection there. A
 * genuinely separate DBAL Connection is cheap and safe here -- SQLite's
 * own `:memory:` fallback in AdvisorySessionLock::sqliteLockFilePath()
 * resolves both to the identical lock file, so a second Connection
 * object correctly simulates real cross-process contention the same way
 * UniqueExecLockTest.php's own otherConn pattern does for mysqli/pgsql.
 *
 * DbTransactionTestOverride::rollback() as beforeEach()'s first line,
 * same reason as DbConnectionTest.php's own sqlite3 tests.
 */
$originalDbDriver = null;
$originalDbBase = null;

beforeEach(function () use (&$originalDbDriver, &$originalDbBase): void {
    DbTransactionTestOverride::rollback();
    // Save+restore, not a blind unset -- this process's real env
    // already carries .env.test's own PIWIGO_DB_DRIVER/PIWIGO_DB_BASE
    // (mysqli/piwigo17_2_test), and every other test in this same
    // worker process needs those back exactly as they were.
    $originalDbDriver = getenv('PIWIGO_DB_DRIVER');
    $originalDbBase = getenv('PIWIGO_DB_BASE');
    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=:memory:');
});

afterEach(function () use (&$originalDbDriver, &$originalDbBase): void {
    UniqueExecLock::reset();
    putenv($originalDbDriver === false ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $originalDbDriver);
    putenv($originalDbBase === false ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $originalDbBase);
});

function uniqueExecLockSqliteTestLogger(): Logger
{
    return new Logger([
        'severity' => Logger::OFF,
    ]);
}

test('begins acquires the lock, isRunning reflects it held, ends releases it', function (): void {
    $tokenName = 'unique-exec-lock-sqlite-test-' . bin2hex(random_bytes(8));

    expect(UniqueExecLock::isRunning($tokenName))->toBeFalse();

    $acquired = UniqueExecLock::begins(uniqueExecLockSqliteTestLogger(), $tokenName);

    expect($acquired)
        ->toBeTrue()
        ->and(UniqueExecLock::isRunning($tokenName))->toBeTrue();

    UniqueExecLock::ends(uniqueExecLockSqliteTestLogger(), $tokenName);

    expect(UniqueExecLock::isRunning($tokenName))->toBeFalse();
});

test('begins on the same connection succeeds again for the same token (reentrant)', function (): void {
    $tokenName = 'unique-exec-lock-sqlite-test-' . bin2hex(random_bytes(8));
    $logger = uniqueExecLockSqliteTestLogger();

    expect(UniqueExecLock::begins($logger, $tokenName))->toBeTrue()
        ->and(UniqueExecLock::begins($logger, $tokenName))->toBeTrue();

    UniqueExecLock::ends($logger, $tokenName);
});

test('begins fails immediately when a different connection already holds the lock', function (): void {
    $tokenName = 'unique-exec-lock-sqlite-test-' . bin2hex(random_bytes(8));
    $otherConn = DbConnection::build();
    $lockName = 'piwigo_exec_' . sha1(':memory::unique_exec:' . $tokenName);

    $acquiredByOther = AdvisorySessionLock::acquire($otherConn, $lockName, 0);
    expect($acquiredByOther)
        ->toBeTrue();

    try {
        expect(UniqueExecLock::begins(uniqueExecLockSqliteTestLogger(), $tokenName))->toBeFalse();
    } finally {
        AdvisorySessionLock::release($otherConn, $lockName);
    }
});

test('isRunning returns false for a token name that was never acquired', function (): void {
    $tokenName = 'unique-exec-lock-sqlite-test-never-acquired-' . bin2hex(random_bytes(8));

    expect(UniqueExecLock::isRunning($tokenName))->toBeFalse();
});

test('ends is a no-op when the lock was never held', function (): void {
    $tokenName = 'unique-exec-lock-sqlite-test-' . bin2hex(random_bytes(8));

    UniqueExecLock::ends(uniqueExecLockSqliteTestLogger(), $tokenName);

    expect(UniqueExecLock::isRunning($tokenName))->toBeFalse();
});
