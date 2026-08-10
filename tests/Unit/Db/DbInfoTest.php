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

    expect($result)->not->toBe('');
});

test('currentDateTime returns the real DB server\'s current datetime', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $result = $dbInfo->currentDateTime();

    expect($result)->toBeString();
    if (is_string($result)) {
        expect($result)->not->toBe('');
    }
});

test('getTableFingerprint returns a real "<epoch>_<count>" fingerprint for a real table', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $result = $dbInfo->getTableFingerprint('categories');

    expect($result)->toMatch('/^\d+_\d+$/');
});

test('resyncIdentitySequence runs cleanly against the real users table on either platform', function (): void {
    $dbInfo = new DbInfo(DbConnection::build());

    $dbInfo->resyncIdentitySequence('users');
})->throwsNoExceptions();
