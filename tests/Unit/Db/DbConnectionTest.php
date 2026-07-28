<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;

// DBAL connections are lazy -- no socket opens until a query runs, so this
// stays Unit-tier even though it exercises DbConnection::build() for real.
// params() (not build()'s Connection::getParams()) is used for assertions
// since Doctrine itself marks that getter as implementation-detail-only.
//
// DbConnection::params() reads DbCredentials::current() (env-only, memoized)
// rather than CurrentConfig:: (Config generic-accessor removal moved DB
// credentials off CurrentConfig:: entirely) -- putenv() + DbCredentials::reset()
// seeds each scenario the same way tests/Unit/Db/DbCredentialsTest.php does.

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

test('build() returns a real Connection', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    DbCredentials::reset();

    expect(DbConnection::build())->toBeInstanceOf(Connection::class);
});

test('params() reads host/user/password/dbname from DbCredentials', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    DbCredentials::reset();

    $params = DbConnection::params();

    expect($params)->toHaveKey('host', 'db.example.test')
        ->and($params['user'])->toBe('piwigo_app')
        ->and($params['password'])->toBe('secret')
        ->and($params['dbname'])->toBe('piwigo_prod')
        ->and($params['driver'])->toBe('mysqli');
});

test('params() treats a host starting with / as a unix socket path', function (): void {
    putenv('PIWIGO_DB_HOST=/var/run/mysqld/mysqld.sock');
    putenv('PIWIGO_DB_USER=root');
    putenv('PIWIGO_DB_PASSWORD=');
    putenv('PIWIGO_DB_BASE=piwigo');
    DbCredentials::reset();

    $params = DbConnection::params();

    expect($params)->toHaveKey('unix_socket', '/var/run/mysqld/mysqld.sock')
        ->and($params)->not->toHaveKey('host');
});

test('params() switches to the native pgsql driver when db_driver is pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');
    putenv('PIWIGO_DB_HOST=pg.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    DbCredentials::reset();

    $params = DbConnection::params();

    expect($params['driver'])->toBe('pgsql')
        ->and($params)->toHaveKey('host', 'pg.example.test')
        ->and($params)->not->toHaveKey('charset')
        ->and($params)->not->toHaveKey('driverOptions')
        ->and($params)->not->toHaveKey('unix_socket');
});

test('params() defaults to mysqli when db_driver is unset', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    DbCredentials::reset();

    expect(DbConnection::params()['driver'])->toBe('mysqli');
});

test('params() carries an explicit port through for the mysqli driver with a TCP host', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    putenv('PIWIGO_DB_PORT=3307');
    DbCredentials::reset();

    $params = DbConnection::params();

    expect($params)->toHaveKey('port', 3307)
        ->and($params)->toHaveKey('host', 'db.example.test');
});

test('params() carries an explicit port through for the pgsql driver', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');
    putenv('PIWIGO_DB_HOST=pg.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    putenv('PIWIGO_DB_PORT=6432');
    DbCredentials::reset();

    $params = DbConnection::params();

    expect($params['driver'])->toBe('pgsql')
        ->and($params)->toHaveKey('port', 6432)
        ->and($params)->toHaveKey('host', 'pg.example.test');
});
