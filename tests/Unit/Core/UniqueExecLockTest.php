<?php

declare(strict_types=1);

use Piwigo\Core\Logger;
use Piwigo\Core\UniqueExecLock;

/**
 * Piwigo\Core\UniqueExecLock -- "only one execution at a time" lock
 * built on the database's own session-scoped advisory lock primitive
 * (MySQL GET_LOCK()/RELEASE_LOCK()/IS_USED_LOCK(), or Postgres
 * pg_try_advisory_lock()/pg_advisory_unlock()+pg_locks). No dedicated
 * Integration/Browser spec of its own.
 *
 * Exercises the real begins()/isRunning()/ends() round trip against the
 * real test DB connection, for a uniqid()-based token name (avoids
 * colliding with a concurrent worktree's identical test, same reasoning
 * as SessionUserResolverTest.php's own cookie values -- lock names are
 * global to the whole DB server, not scoped to one database/schema, per
 * this class's own docblock). The "another execution is already
 * running" contention branch would need a genuinely separate DB
 * connection racing this one for the same lock name, too flaky/invasive
 * for this class's own static-single-cached-connection design and not
 * attempted here.
 */
afterEach(function (): void {
    UniqueExecLock::reset();
});

test('begins acquires the lock, isRunning reflects it held, ends releases it', function (): void {
    $logger = new Logger(['severity' => Logger::OFF]);
    $tokenName = 'unique-exec-lock-test-' . bin2hex(random_bytes(8));

    $acquired = UniqueExecLock::begins($logger, $tokenName);

    expect($acquired)->toBeTrue()
        ->and(UniqueExecLock::isRunning($tokenName))->toBeTrue();

    UniqueExecLock::ends($logger, $tokenName);

    expect(UniqueExecLock::isRunning($tokenName))->toBeFalse();
});

test('isRunning returns false for a token name that was never acquired', function (): void {
    $tokenName = 'unique-exec-lock-test-never-acquired-' . bin2hex(random_bytes(8));

    expect(UniqueExecLock::isRunning($tokenName))->toBeFalse();
});
