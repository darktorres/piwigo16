<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;

/**
 * Piwigo\Db\DbInfo -- real DB server diagnostics (version/current
 * datetime/table fingerprint/identity-sequence resync), portable across
 * both real drivers this project supports (MySQL/Postgres). No
 * dedicated Integration/Browser spec of its own.
 *
 * Every method here is run against the real test DB connection, same
 * B2-pattern real-DB approach as this campaign's Repository/Service
 * tests. `resyncIdentitySequence()` is a real no-op on MySQL and a real
 * idempotent resync on Postgres (sets the sequence to the table's own
 * current MAX(id), never below it) -- safe to run against the real
 * `users` table on either platform.
 */
test('version returns a real, non-empty DB server version string', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $result = $dbInfo->version();

    expect($result)
        ->not->toBe('');
});

test('currentDateTime returns the real DB server\'s current datetime', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $result = $dbInfo->currentDateTime();

    expect($result)
        ->toBeString();
    if (is_string($result)) {
        expect($result)->not->toBe('');
    }
});

test('getTableFingerprint returns a real "<epoch>_<count>" fingerprint for a real table', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $result = $dbInfo->getTableFingerprint('categories');

    expect($result)
        ->toMatch('/^\d+_\d+$/');
});

/**
 * A freshly migrated database has no rows, which used to make
 * MAX(lastmodified) NULL and therefore CONCAT() NULL on both engines, so
 * the fingerprint came back as '' rather than as a fingerprint -- and every
 * empty table shared that one value as its cache key.
 *
 * This is not a hypothetical: `tests/Unit/Db` runs against exactly that
 * empty schema in the db-multi-provider CI job, where the assertion above
 * failed on the mysql and pgsql legs and skipped every later step in the
 * job, including the schema-drift guard.
 */
test('getTableFingerprint still returns a well-formed fingerprint for an empty table', function (): void {
    $conn = DbConnection::build();
    $dbInfo = new DbInfo($conn);

    $conn->beginTransaction();

    try {
        $conn->executeStatement('DELETE FROM ' . $conn->getDatabasePlatform()->quoteSingleIdentifier('tags'));

        expect($dbInfo->getTableFingerprint('tags'))
            ->toMatch('/^\d+_\d+$/')
            ->toBe('0_0');
    } finally {
        $conn->rollBack();
    }
});

test('resyncIdentitySequence runs cleanly against the real users table on either platform', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $dbInfo->resyncIdentitySequence('users');
})->throwsNoExceptions();
