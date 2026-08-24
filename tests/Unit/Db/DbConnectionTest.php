<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbTransactionTestOverride;

// DBAL connections are lazy -- no socket opens until a query runs, so this
// stays Unit-tier even though it exercises DbConnection::build() for real.
// params() (not build()'s Connection::getParams()) is used for assertions
// since Doctrine itself marks that getter as implementation-detail-only.
//
// DbConnection::params() reads DbCredentials::current() (env-only), not
// CurrentConfig:: -- current() falls back to a fresh fromEnv() read on
// every call since Kernel is never booted in this file, so a bare
// putenv() per scenario is enough to seed it, no reset() needed.

$envVars = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PORT', 'PIWIGO_DB_DRIVER'];
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
        $original_value = $originalEnvVars[$var] ?? null;
        putenv($original_value === null ? $var : $var . '=' . $original_value);
    }
});

test('build() returns a real Connection', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    expect(static fn (): Connection => DbConnection::build())->not->toThrow(Throwable::class);
});

test('params() reads host/user/password/dbname from DbCredentials', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    $params = DbConnection::params();

    // params() returns a 3-way union; only the mysqli arm carries
    // user/password/dbname as unconditional keys, same narrowing this
    // file's own "pins the session sql_mode" test below already needs.
    expect($params['driver'])->toBe('mysqli');
    assert($params['driver'] === 'mysqli');

    expect($params)
        ->toHaveKey('host', 'db.example.test')
        ->and($params['user'])->toBe('piwigo_app')
        ->and($params['password'])->toBe('secret')
        ->and($params['dbname'])->toBe('piwigo_prod');
});

test('params() sets utf8mb4 charset and native int/float driverOptions for the mysqli driver', function (): void {
    // Kills line 80's RemoveArrayItem (dropping 'charset' entirely) and
    // lines 85/86's RemoveArrayItem on 'driverOptions' (dropping the key
    // entirely, or emptying its inner array) -- none of this file's
    // other mysqli-branch tests assert either key's presence or value.
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    $params = DbConnection::params();

    expect($params)
        ->toHaveKey('charset', 'utf8mb4')
        ->and($params)
        ->toHaveKey('driverOptions', [
            MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
            MYSQLI_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
        ]);
});

test('params() treats a host starting with / as a unix socket path', function (): void {
    putenv('PIWIGO_DB_HOST=/var/run/mysqld/mysqld.sock');
    putenv('PIWIGO_DB_USER=root');
    putenv('PIWIGO_DB_PASSWORD=');
    putenv('PIWIGO_DB_BASE=piwigo');

    $params = DbConnection::params();

    expect($params)
        ->toHaveKey('unix_socket', '/var/run/mysqld/mysqld.sock')
        ->and($params)
        ->not->toHaveKey('host');
});

test('params() switches to the native pgsql driver when db_driver is pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');
    putenv('PIWIGO_DB_HOST=pg.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    $params = DbConnection::params();

    expect($params['driver'])->toBe('pgsql')
        ->and($params)
        ->toHaveKey('host', 'pg.example.test')
        // Kills lines 63/64/65's RemoveArrayItem (dropping 'user',
        // 'password', or 'dbname' from the pgsql params array) --
        // the mysqli-branch test above covers these keys for mysqli,
        // but nothing else here asserts them for the pgsql branch.
        ->and($params)
        ->toHaveKey('user', 'piwigo_app')
        ->and($params)
        ->toHaveKey('password', 'secret')
        ->and($params)
        ->toHaveKey('dbname', 'piwigo_prod')
        ->and($params)
        ->not->toHaveKey('charset')
        ->and($params)
        ->not->toHaveKey('driverOptions')
        ->and($params)
        ->not->toHaveKey('unix_socket');
});

test('params() defaults to mysqli when db_driver is unset', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    expect(DbConnection::params()['driver'])->toBe('mysqli');
});

test('params() carries an explicit port through for the mysqli driver with a TCP host', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    putenv('PIWIGO_DB_PORT=3307');

    $params = DbConnection::params();

    expect($params)
        ->toHaveKey('port', 3307)
        ->and($params)
        ->toHaveKey('host', 'db.example.test');
});

test('params() carries an explicit port through for the pgsql driver', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');
    putenv('PIWIGO_DB_HOST=pg.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');
    putenv('PIWIGO_DB_PORT=6432');

    $params = DbConnection::params();

    expect($params['driver'])->toBe('pgsql')
        ->and($params)
        ->toHaveKey('port', 6432)
        ->and($params)
        ->toHaveKey('host', 'pg.example.test');
});

/**
 * The session sql_mode is pinned on connect rather than inherited from the
 * server, because this codebase depends on strict mode for correctness --
 * out-of-range integers must be rejected rather than clamped, zero-date
 * sentinels must stay unwritable, and every query was written to be valid
 * under ONLY_FULL_GROUP_BY.
 *
 * MYSQLI_INIT_COMMAND rather than a `SET SESSION` issued after connecting:
 * it also runs on reconnect.
 */
test('params() pins the session sql_mode for the mysqli driver', function (): void {
    putenv('PIWIGO_DB_DRIVER=mysqli');
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    $params = DbConnection::params();

    // params() returns a union; only the mysqli arm carries driverOptions.
    expect($params['driver'])
        ->toBe('mysqli');
    assert($params['driver'] === 'mysqli');

    expect($params['driverOptions'])
        ->toHaveKey(MYSQLI_INIT_COMMAND)
        ->and($params['driverOptions'][MYSQLI_INIT_COMMAND])
        ->toContain('STRICT_TRANS_TABLES')
        ->toContain('ONLY_FULL_GROUP_BY')
        ->toContain('NO_ZERO_DATE');
});

/**
 * PostgreSQL has no sql_mode concept at all, so the pgsql branch must not
 * grow a mysqli-only driver option.
 */
test('params() adds no sql_mode option for the pgsql driver', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');
    putenv('PIWIGO_DB_HOST=pg.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');
    putenv('PIWIGO_DB_BASE=piwigo_prod');

    expect(DbConnection::params())
        ->not->toHaveKey('driverOptions');
});

/**
 * $database doubles as the SQLite file path for this driver -- see
 * DbCredentials's own docblock. host/user/password/port are meaningless
 * for a file-based target, so the sqlite3 arm carries neither.
 */
test('params() switches to the native sqlite3 driver and maps database to path when db_driver is sqlite3', function (): void {
    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=/var/lib/piwigo/piwigo.sqlite');
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');
    putenv('PIWIGO_DB_PASSWORD=secret');

    $params = DbConnection::params();

    expect($params)
        ->toBe([
            'driver' => 'sqlite3',
            'path' => '/var/lib/piwigo/piwigo.sqlite',
        ]);
});

/**
 * build()'s SQLite-specific per-connection setup (initSqliteConnection())
 * -- a real REGEXP query and a real PRAGMA foreign_keys read, both against
 * a real connection, not just "doesn't throw". `:memory:` keeps this
 * Unit-tier: no filesystem I/O, a real SQLite engine nonetheless.
 *
 * REGEXP: DBAL's own SQLitePlatform::getRegexpExpression() emits the bare
 * SQL keyword REGEXP assuming native support that doesn't exist --
 * unregistered, this query would throw "no such function: REGEXP" instead
 * of returning a row.
 */
test('build() registers a working REGEXP UDF and enables foreign_keys for the sqlite3 driver', function (): void {
    // Ends tests/Pest.php's own global per-Unit-test transaction override
    // early (same escape hatch InstallServiceTest.php's own DDL test
    // uses) -- without this, DbConnection::build() below would
    // transparently return the override's real mysqli test connection
    // instead of a genuine sqlite3 one.
    DbTransactionTestOverride::rollback();

    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=:memory:');

    $conn = DbConnection::build();
    $conn->executeStatement('CREATE TABLE t (name TEXT)');
    // t only exists in this test's own throwaway sqlite3 :memory: connection,
    // never in the live reflector's persistent (mysqli) schema.
    // @phpstan-ignore dba.syntaxError
    $conn->executeStatement('INSERT INTO t (name) VALUES (?)', ['hello world']);

    // @phpstan-ignore dba.syntaxError
    expect($conn->fetchOne('SELECT name FROM t WHERE name REGEXP ?', ['^hello']))
        ->toBe('hello world')
        ->and($conn->fetchOne('PRAGMA foreign_keys'))
        ->toBe(1);

    // The UDF's own return type must be a real INTEGER (0/1), not a PHP
    // bool -- a real, previously-live bug (Wave 5 of the SQLite
    // campaign): SQLite3::createFunction() stores a returned `bool` as
    // TEXT ('1'/''), not INTEGER, confirmed live via typeof(). Every real
    // production Db\DqlFunction\RegexpFunction caller (e.g.
    // CategoryRepository's own uppercats ancestor-matching queries)
    // compiles to `col REGEXP pattern = 1` -- this exact shape, not the
    // bare form above, is what actually caught the bug: comparing
    // SQLite's own TEXT '1' against the INTEGER literal 1 is false, so
    // every one of those real queries silently matched zero rows before
    // the fix.
    // @phpstan-ignore dba.syntaxError
    expect($conn->fetchOne('SELECT name FROM t WHERE name REGEXP ? = 1', ['^hello']))
        ->toBe('hello world');
});
