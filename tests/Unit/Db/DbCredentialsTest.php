<?php

declare(strict_types=1);

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
    DbCredentials::reset();
});

afterEach(function () use ($envVars, &$originalEnvVars): void {
    foreach ($envVars as $var) {
        putenv($originalEnvVars[$var] === null ? $var : $var . '=' . $originalEnvVars[$var]);
    }
    DbCredentials::reset();
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
    DbCredentials::reset();

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
    DbCredentials::reset();
    expect(DbCredentials::fromEnv()->port)->toBeNull();

    putenv('PIWIGO_DB_PORT=abc');
    DbCredentials::reset();
    expect(DbCredentials::fromEnv()->port)->toBeNull();
});

test('fromEnv() falls back to the default driver when PIWIGO_DB_DRIVER is explicitly empty, not just when unset', function (): void {
    // Kills line 60's EmptyStringToNotEmpty: the existing "no env
    // vars" test only reaches the $driverEnv === false branch; an
    // explicitly empty (but set) driver env var is a different code
    // path through the same guard.
    putenv('PIWIGO_DB_DRIVER=');
    DbCredentials::reset();

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
test('current() memoizes across calls until reset()', function (): void {
    putenv('PIWIGO_DB_HOST=first.example.test');

    $first = DbCredentials::current();
    putenv('PIWIGO_DB_HOST=second.example.test');
    $stillFirst = DbCredentials::current();

    expect($stillFirst)->toBe($first)
        ->and($stillFirst->host)->toBe('first.example.test');

    DbCredentials::reset();

    expect(DbCredentials::current()->host)->toBe('second.example.test');
});

test('seed() putenvs each non-null value and resets the memo', function (): void {
    DbCredentials::current();

    DbCredentials::seed(['PIWIGO_DB_HOST' => 'seeded.example.test', 'PIWIGO_DB_USER' => null]);

    expect(DbCredentials::current()->host)->toBe('seeded.example.test');
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
