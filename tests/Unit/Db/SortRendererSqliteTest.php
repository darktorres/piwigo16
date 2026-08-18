<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\SortRenderer;
use Piwigo\Db\SqlDialect;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * SortRenderer::randomExpression()'s real SQLite branch (Wave 5 of the
 * SQLite campaign) -- SQLite uses the bare RANDOM() keyword, same as
 * Postgres, unlike MySQL/MariaDB's own RAND().
 *
 * DbTransactionTestOverride::rollback() as beforeEach()'s first line,
 * same reason as every other sqlite3 test this campaign has added.
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
    putenv($originalDbDriver === false ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $originalDbDriver);
    putenv($originalDbBase === false ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $originalDbBase);
});

test('randomExpression() returns the bare RANDOM() keyword on sqlite3', function (): void {
    $renderer = new SortRenderer(DbConnection::build());

    expect($renderer->randomExpression())
        ->toBe('RANDOM()')
        ->and(SqlDialect::randomFunction())->toBe('RANDOM()');
});
