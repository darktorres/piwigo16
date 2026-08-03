<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Db\DbCredentials;

// putenv($var) with no '=value' unsets the var process-wide -- since Pest
// runs the whole suite in one PHP process, clearing PIWIGO_DB_* here
// without restoring the originals would corrupt every later Integration
// test's real DB connection (tests/bootstrap.php loads them once via
// pwg_load_env_file() at process start, nothing else re-populates them).
// Save + restore the real values instead of just clearing them.
$envVars = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX', 'PIWIGO_DB_PORT', 'PIWIGO_DB_DRIVER'];
$originalEnvVars = [];

beforeEach(function () use ($envVars, &$originalEnvVars): void {
    foreach ($envVars as $var) {
        $value = getenv($var);
        $originalEnvVars[$var] = $value === false ? null : $value;
        putenv($var);
    }
});

afterEach(function () use ($envVars, &$originalEnvVars): void {
    foreach ($envVars as $var) {
        putenv($originalEnvVars[$var] === null ? $var : $var . '=' . $originalEnvVars[$var]);
    }
    Kernel::reset();
});

test('fromEnv() falls back to defaults when no env vars are set', function (): void {
    $credentials = DbCredentials::fromEnv();

    expect($credentials->host)->toBe('localhost')
        ->and($credentials->user)->toBe('')
        ->and($credentials->password)->toBe('')
        ->and($credentials->database)->toBe('')
        ->and($credentials->prefix)->toBe('piwigo_')
        ->and($credentials->port)->toBeNull()
        ->and($credentials->driver)->toBe('mysqli');
});

test('fromEnv() falls back to the default host when PIWIGO_DB_HOST is explicitly empty, not just when unset', function (): void {
    // Kills line 127's EmptyStringToNotEmpty in the shared env()
    // helper: the "no env vars" test above only reaches the $value
    // === false branch; an explicitly empty (but set) var is a
    // different code path through the same guard, for every one of
    // the host/user/password/database/prefix fields this helper backs.
    putenv('PIWIGO_DB_HOST=');

    expect(DbCredentials::fromEnv()->host)->toBe('localhost');
});

test('fromEnv() reads every PIWIGO_DB_* var when set', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=s3cret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    putenv('PIWIGO_DB_PREFIX=pwg_');
    putenv('PIWIGO_DB_PORT=33061');
    putenv('PIWIGO_DB_DRIVER=pgsql');

    $credentials = DbCredentials::fromEnv();

    expect($credentials->host)->toBe('db.example.test')
        ->and($credentials->user)->toBe('piwigo_app')
        ->and($credentials->password)->toBe('s3cret')
        ->and($credentials->database)->toBe('piwigo_prod')
        ->and($credentials->prefix)->toBe('pwg_')
        ->and($credentials->port)->toBe(33061)
        ->and($credentials->driver)->toBe('pgsql');
});

test('fromEnv() treats an empty or non-numeric PIWIGO_DB_PORT the same as unset, not as port 0', function (): void {
    // Kills line 59's BooleanAndToBooleanOr on the chain's LAST &&
    // (`($portEnv !== false && $portEnv !== '') || is_numeric($portEnv)`):
    // for a non-numeric string like 'abc', the left side is already
    // true (it's a real, non-empty string), so the || makes the whole
    // condition true regardless of is_numeric()'s own correct "no"
    // answer, landing on (int) 'abc' (0) instead of null. Confirmed
    // live: the existing "no env vars" test (port unset -> false) and
    // "every var set" test (a genuinely numeric port) can't reach this
    // divergence point -- only a non-numeric string does.
    putenv('PIWIGO_DB_PORT=');
    expect(DbCredentials::fromEnv()->port)->toBeNull();

    putenv('PIWIGO_DB_PORT=abc');
    expect(DbCredentials::fromEnv()->port)->toBeNull();
});

test('fromEnv() falls back to the default driver when PIWIGO_DB_DRIVER is explicitly empty, not just when unset', function (): void {
    // Kills line 60's EmptyStringToNotEmpty: the existing "no env
    // vars" test only reaches the $driverEnv === false branch; an
    // explicitly empty (but set) driver env var is a different code
    // path through the same guard.
    putenv('PIWIGO_DB_DRIVER=');

    expect(DbCredentials::fromEnv()->driver)->toBe('mysqli');
});

/**
 * Confirmed-equivalent: line 59's FalseToTrue (`$portEnv !== true`
 * instead of `!== false`) and EmptyStringToNotEmpty (a placeholder
 * instead of `''`). getenv() never returns the literal boolean `true`
 * (only a string, or `false` on an unset var), so `$portEnv !== true`
 * is unconditionally true regardless of $portEnv's real value --
 * reducing the mutant's first condition to a no-op. And is_numeric()
 * -- the chain's own third condition -- already independently rejects
 * both `false` and `''` on its own (neither is numeric), so mutating
 * just the '' comparison changes nothing about the final result for
 * any real getenv() return value. Confirmed live across unset, empty,
 * and non-numeric PIWIGO_DB_PORT values: identical output to real code
 * for both mutations.
 *
 * Also confirmed-equivalent: line 59's OTHER BooleanAndToBooleanOr, on
 * the chain's FIRST && (`($portEnv !== false || $portEnv !== '') &&
 * is_numeric($portEnv)`). Initially assumed distinguishable from a
 * quick reasoning pass that missed PHP's own && > || precedence --
 * re-verified directly against pest's own exact mutated source (not
 * a hand-written approximation) before trusting this: for ANY real
 * getenv() return value (`false`, `''`, or any non-empty string),
 * `$portEnv !== false` alone already makes the `||` side true whenever
 * $portEnv is a string at all (since a string is never identical to
 * `false`), so the mutated condition collapses to just
 * `is_numeric($portEnv)` -- the exact same determining factor real
 * code's full `&&` chain converges on for every one of those values.
 * Confirmed live against the precise parenthesized mutation pest
 * generates (not the differently-precedenced version this docblock's
 * sibling test was originally verified against by mistake).
 */
test('current() returns a fresh read on every call when Kernel is not booted', function (): void {
    // Singleton/service-locator elimination campaign, Phase 3: current()
    // is a pure shim now, with no independent memo of its own -- when
    // there's no container to resolve a shared instance from, it falls
    // back to a bare fromEnv() read every time (same reasoning as every
    // other Unit test in this codebase that never boots Kernel at all).
    putenv('PIWIGO_DB_HOST=first.example.test');
    $first = DbCredentials::current();

    putenv('PIWIGO_DB_HOST=second.example.test');
    $second = DbCredentials::current();

    expect($second)->not->toBe($first)
        ->and($first->host)->toBe('first.example.test')
        ->and($second->host)->toBe('second.example.test');
});

test('current() returns the same container-shared instance across calls once Kernel is booted', function (): void {
    putenv('PIWIGO_DB_HOST=booted.example.test');
    Kernel::boot();

    $first = DbCredentials::current();
    $second = DbCredentials::current();

    expect($second)->toBe($first)
        ->and($first->host)->toBe('booted.example.test');
});

test('reload() re-derives every property from the current environment, mutating this instance in place', function (): void {
    $credentials = DbCredentials::fromEnv();

    putenv('PIWIGO_DB_HOST=reloaded.example.test');
    putenv('PIWIGO_DB_USER=reloaded_user');
    putenv('PIWIGO_DB_PASSWORD=reloaded_pass');
    putenv('PIWIGO_DB_BASE=reloaded_db');
    putenv('PIWIGO_DB_PREFIX=reloaded_');
    putenv('PIWIGO_DB_PORT=5432');
    putenv('PIWIGO_DB_DRIVER=pgsql');

    $before = $credentials;
    $credentials->reload();

    // Same object identity -- reload() mutates in place, it doesn't
    // replace the instance (the whole point: every other consumer
    // holding this same reference sees the update too).
    expect($credentials)->toBe($before)
        ->and($credentials->host)->toBe('reloaded.example.test')
        ->and($credentials->user)->toBe('reloaded_user')
        ->and($credentials->password)->toBe('reloaded_pass')
        ->and($credentials->database)->toBe('reloaded_db')
        ->and($credentials->prefix)->toBe('reloaded_')
        ->and($credentials->port)->toBe(5432)
        ->and($credentials->driver)->toBe('pgsql');
});

test('seed() putenvs each non-null value and reload()s this same instance in place', function (): void {
    $credentials = DbCredentials::fromEnv();

    $credentials->seed(['PIWIGO_DB_HOST' => 'seeded.example.test', 'PIWIGO_DB_USER' => null]);

    expect($credentials->host)->toBe('seeded.example.test');
});

test('seed() on the container-shared instance is visible to every other consumer holding it', function (): void {
    Kernel::boot();
    $before = DbCredentials::current();

    $before->seed(['PIWIGO_DB_HOST' => 'seeded-shared.example.test']);

    expect(DbCredentials::current())->toBe($before)
        ->and(DbCredentials::current()->host)->toBe('seeded-shared.example.test');
});

test('toMysqlArgs() includes -p only when a password is set', function (): void {
    $withoutPassword = new DbCredentials(host: 'localhost', user: 'root', password: '', database: 'piwigo', prefix: 'piwigo_');
    expect($withoutPassword->toMysqlArgs())->toBe(['-hlocalhost', '-uroot']);

    $withPassword = new DbCredentials(host: 'localhost', user: 'root', password: 'secret', database: 'piwigo', prefix: 'piwigo_');
    expect($withPassword->toMysqlArgs())->toBe(['-hlocalhost', '-uroot', '-psecret']);
});

test('toMysqlArgs() includes -P only when a port is set', function (): void {
    $withoutPort = new DbCredentials(host: 'localhost', user: 'root', password: '', database: 'piwigo', prefix: 'piwigo_');
    expect($withoutPort->toMysqlArgs())->toBe(['-hlocalhost', '-uroot']);

    $withPort = new DbCredentials(host: 'localhost', user: 'root', password: '', database: 'piwigo', prefix: 'piwigo_', port: 33061);
    expect($withPort->toMysqlArgs())->toBe(['-hlocalhost', '-uroot', '-P33061']);
});
