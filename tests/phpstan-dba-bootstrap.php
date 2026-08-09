<?php

declare(strict_types=1);

use Piwigo\Core\Env;
use Piwigo\Db\DbCredentials;
use staabm\PHPStanDba\QueryReflection\PdoMysqlQueryReflector;
use staabm\PHPStanDba\QueryReflection\PdoPgSqlQueryReflector;
use staabm\PHPStanDba\QueryReflection\QueryReflection;
use staabm\PHPStanDba\QueryReflection\RuntimeConfiguration;

// phpstan-dba's query reflector -- validates raw SQL literals (DBAL
// QueryBuilder ->executeQuery()/->executeStatement() strings) against a
// real database's live schema. Opens the same .env.test connection
// tests/phpstan-object-manager.php already uses for phpstan-doctrine's DQL
// platform detection -- one live DB, not a second ad hoc one.
//
// PDO (not mysqli/pgsql) purely because phpstan-dba only ships PDO-backed
// reflectors for these two engines -- DbConnection.php's own "native
// drivers only, not pdo_*" policy governs src/ runtime code, not this
// analysis-time tool (see composer.json's require-dev ext-pdo/
// ext-pdo_mysql/ext-pdo_pgsql, deliberately kept out of the real require
// block for the same reason).
//
// Write queries (INSERT/UPDATE/DELETE/REPLACE) are validated via EXPLAIN,
// never actually executed -- see RuntimeConfiguration's own
// analyzeWriteQueries() docblock and QueryReflection::validateQueryString().

require __DIR__ . '/../vendor/autoload.php';

$_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';
Env::loadEnvFile(dirname(__DIR__));

$credentials = DbCredentials::fromEnv();

if ($credentials->driver === 'pgsql') {
    // Mirrors DbConnection::params()'s own comment: pg's DSN accepts a
    // Unix socket directory directly via 'host', no separate branch needed.
    $dsn = sprintf('pgsql:host=%s;dbname=%s', $credentials->host, $credentials->database);
    if ($credentials->port !== null) {
        $dsn .= ';port=' . $credentials->port;
    }

    $reflector = new PdoPgSqlQueryReflector(new PDO($dsn, $credentials->user, $credentials->password));
} else {
    // Mirrors DbConnection::params()'s own unix_socket-vs-host branch.
    $dsn = str_starts_with($credentials->host, '/')
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $credentials->host, $credentials->database)
        : sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $credentials->host, $credentials->database);
    if ($credentials->port !== null) {
        $dsn .= ';port=' . $credentials->port;
    }

    $reflector = new PdoMysqlQueryReflector(new PDO($dsn, $credentials->user, $credentials->password));
}

QueryReflection::setupReflector($reflector, RuntimeConfiguration::create());
