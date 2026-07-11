<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Db\DbConnection;

// DBAL connections are lazy -- no socket opens until a query runs, so this
// stays Unit-tier even though it exercises DbConnection::build() for real.
// params() (not build()'s Connection::getParams()) is used for assertions
// since Doctrine itself marks that getter as implementation-detail-only.

beforeEach(function (): void {
    Config::reset();
});

afterEach(function (): void {
    Config::reset();
});

test('build() returns a real Connection', function (): void {
    Config::override('db_host', 'db.example.test');
    Config::override('db_user', 'piwigo_app');
    Config::override('db_password', 'secret');
    Config::override('db_base', 'piwigo_prod');

    expect(DbConnection::build())->toBeInstanceOf(Connection::class);
});

test('params() reads host/user/password/dbname from Config', function (): void {
    Config::override('db_host', 'db.example.test');
    Config::override('db_user', 'piwigo_app');
    Config::override('db_password', 'secret');
    Config::override('db_base', 'piwigo_prod');

    $params = DbConnection::params();

    expect($params)->toHaveKey('host', 'db.example.test')
        ->and($params['user'])->toBe('piwigo_app')
        ->and($params['password'])->toBe('secret')
        ->and($params['dbname'])->toBe('piwigo_prod')
        ->and($params['driver'])->toBe('mysqli');
});

test('params() treats a host starting with / as a unix socket path', function (): void {
    Config::override('db_host', '/var/run/mysqld/mysqld.sock');
    Config::override('db_user', 'root');
    Config::override('db_password', '');
    Config::override('db_base', 'piwigo');

    $params = DbConnection::params();

    expect($params)->toHaveKey('unix_socket', '/var/run/mysqld/mysqld.sock')
        ->and($params)->not->toHaveKey('host');
});
