<?php

declare(strict_types=1);

use Piwigo\Db\DbCredentials;

// putenv($var) with no '=value' unsets the var process-wide -- since Pest
// runs the whole suite in one PHP process, clearing PIWIGO_DB_* here
// without restoring the originals would corrupt every later Integration
// test's real DB connection (tests/bootstrap.php loads them once via
// pwg_load_env_file() at process start, nothing else re-populates them).
// Save + restore the real values instead of just clearing them.
$envVars = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX'];
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
});

test('fromEnv() falls back to defaults when no env vars are set', function (): void {
    $credentials = DbCredentials::fromEnv();

    expect($credentials->host)->toBe('localhost')
        ->and($credentials->user)->toBe('root')
        ->and($credentials->password)->toBe('')
        ->and($credentials->database)->toBe('piwigo')
        ->and($credentials->prefix)->toBe('piwigo_');
});

test('fromEnv() reads every PIWIGO_DB_* var when set', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=s3cret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    putenv('PIWIGO_DB_PREFIX=pwg_');

    $credentials = DbCredentials::fromEnv();

    expect($credentials->host)->toBe('db.example.test')
        ->and($credentials->user)->toBe('piwigo_app')
        ->and($credentials->password)->toBe('s3cret')
        ->and($credentials->database)->toBe('piwigo_prod')
        ->and($credentials->prefix)->toBe('pwg_');
});

test('toMysqlArgs() includes -p only when a password is set', function (): void {
    $withoutPassword = new DbCredentials(host: 'localhost', user: 'root', password: '', database: 'piwigo', prefix: 'piwigo_');
    expect($withoutPassword->toMysqlArgs())->toBe(['-hlocalhost', '-uroot']);

    $withPassword = new DbCredentials(host: 'localhost', user: 'root', password: 'secret', database: 'piwigo', prefix: 'piwigo_');
    expect($withPassword->toMysqlArgs())->toBe(['-hlocalhost', '-uroot', '-psecret']);
});
