<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialectExecutor;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * SqlDialectExecutor's real SQLite branch (Wave 5 of the SQLite
 * campaign) -- SQLite has neither `NOW()` nor `ADDDATE()`; `datetime(
 * 'now', '+n days')` is its own real equivalent, with the day count
 * still bound as a real parameter via SQLite's own `||` concatenation
 * operator (see SqlDialectExecutor's own docblock).
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
    $dbDriver = $originalDbDriver;
    $dbBase = $originalDbBase;
    putenv($dbDriver === false || $dbDriver === null ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $dbDriver);
    putenv($dbBase === false || $dbBase === null ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $dbBase);
});

test('fetchTomorrow returns a real date one day ahead of the DB server\'s current date', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    $tomorrow = new DateTimeImmutable($executor->fetchTomorrow());
    $expected = new DateTimeImmutable('+1 day');

    expect($tomorrow->format('Y-m-d'))
        ->toBe($expected->format('Y-m-d'));
});

test('fetchFutureDatesFor returns an empty array for an empty day list, with no DB access', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    expect($executor->fetchFutureDatesFor([]))->toBe([]);
});

test('fetchFutureDatesFor returns a real future date keyed by each requested day count', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    $result = $executor->fetchFutureDatesFor([1, 7, 30]);

    expect($result)
        ->toHaveKeys([1, 7, 30]);
    foreach ([1, 7, 30] as $day) {
        $expected = new DateTimeImmutable("+{$day} day" . ($day === 1 ? '' : 's'));
        $value = $result[$day];
        expect($value)
            ->toBeString();
        assert(is_string($value));
        $actual = new DateTimeImmutable($value);
        expect($actual->format('Y-m-d'))
            ->toBe($expected->format('Y-m-d'));
    }
});
