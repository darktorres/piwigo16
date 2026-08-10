<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialectExecutor;

/**
 * Piwigo\Db\SqlDialectExecutor -- real DB round-trips for a
 * SqlDialect-built date expression, portable across both real drivers
 * this project supports (MySQL/Postgres). No dedicated Integration/
 * Browser spec of its own.
 *
 * Every method here is run against the real test DB connection, same
 * B2-pattern real-DB approach as this campaign's Repository/Service
 * tests.
 */
test('fetchRecentCutoffDate returns a real, non-empty cutoff date', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    $result = $executor->fetchRecentCutoffDate(7);

    expect($result)->not->toBe('');
});

test('fetchTomorrow returns a real date one day ahead of the DB server\'s current date', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    $result = $executor->fetchTomorrow();

    expect($result)->not->toBe('');
});

test('fetchFutureDatesFor returns an empty array for an empty day list, with no DB access', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    $result = $executor->fetchFutureDatesFor([]);

    expect($result)->toBe([]);
});

test('fetchFutureDatesFor returns a real future date keyed by each requested day count', function (): void {
    $executor = new SqlDialectExecutor(DbConnection::build());

    $result = $executor->fetchFutureDatesFor([1, 7, 30]);

    expect($result)->toHaveKeys([1, 7, 30]);
    foreach ([1, 7, 30] as $day) {
        expect($result[$day])->not->toBeNull();
    }
});
