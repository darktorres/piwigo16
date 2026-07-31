<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

function c13yInternalTestCheckIntegrity(): CheckIntegrity
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(IntegrityIgnoredAnomalyEntity::class);
    expect($repo)->toBeInstanceOf(IntegrityIgnoredAnomalyRepository::class);

    return new CheckIntegrity($repo);
}

// c13y_correction_user() reaches CoreDomainAccessor::userService(), which
// needs the DI container -- unlike every other function-style Integration
// test file in this suite, this is the first one in file-discovery order
// that actually touches it, so it can't rely on some earlier class-based
// Integration test having already called Kernel::boot() for this process.
// boot() is idempotent (no-op once already booted), so this is safe
// regardless of what ran before.
beforeEach(function (): void {
    ConfigLoader::applyDefaults();
    ConfigLoader::applyEnvOverrides();
    Kernel::boot();
});

// c13y_user()/c13y_correction_user() depend on the exact configured guest_id/
// default_user_id/webmaster_id lining up against real fixture rows in a way
// that's fragile to hardcode an exact anomaly count for -- deferred.
// c13y_version()/c13y_exif() are deterministic in THIS environment: the app
// itself couldn't be running at all if PHP_VERSION/the real MySQL version
// didn't already satisfy AppInfo::REQUIRED_PHP_VERSION/SqlDialect::
// REQUIRED_MYSQL_VERSION, and exif_read_data() is confirmed available here
// (see PwgImage's own get_rotation_angle() tests) -- so both real checks
// below are provably "zero anomalies" in this suite's own environment,
// not just today's incidental happy path.

test('c13y_version adds no anomaly when the running PHP/MySQL already satisfy the app\'s own minimum versions', function (): void {
    $c13y = c13yInternalTestCheckIntegrity();

    new C13yInternal()->c13y_version($c13y);

    expect($c13y->retrieve_list)->toBe([]);
});

test('c13y_exif adds no anomaly when exif_read_data() is available', function (): void {
    expect(function_exists('exif_read_data'))->toBeTrue();

    $c13y = c13yInternalTestCheckIntegrity();
    new C13yInternal()->c13y_exif($c13y);

    expect($c13y->retrieve_list)->toBe([]);
});

// c13y_version()'s and c13y_exif()'s own add_anomaly()-calling branches
// (the "PHP/MySQL version too low" and "exif extension missing" true
// paths) are not exercised anywhere in this file, and can't genuinely be:
// version_compare() only takes the anomaly path if the running PHP
// predates AppInfo::REQUIRED_PHP_VERSION ('8.5.0'), which this project's
// own composer.json ("php": "^8.5") makes structurally impossible to
// satisfy from inside a real Composer-installed run -- there is no config
// knob or fixture that flips this without editing the constant itself
// (not a bug, so out of this pass's scope). SqlDialect::
// REQUIRED_MYSQL_VERSION ('5.0.0') is equally unreachable against any DB
// actually usable by this app. c13y_exif()'s branch is the exact same
// "verified untestable without breaking a real runtime guarantee" shape
// tests/Integration/MetadataServiceTest.php already documents for its own
// exif_read_data() guard: function_exists() can't be forced to lie about
// a real, loaded extension from inside a test process, and exif is loaded
// in this environment.

afterEach(function (): void {
    CurrentConfig::setGuestId(2);
    CurrentConfig::setDefaultUserId(2);
    CurrentConfig::setWebmasterId(1);
});

test('c13y_user flags a configured webmaster_id that has no matching user row, and c13y_correction_user creates it', function (): void {
    // guest_id/default_user_id stay at their real fixture defaults (a real
    // 'guest' user genuinely exists at id 2), so only the webmaster_id slot
    // is deliberately pointed at a nonexistent id -- isolates the
    // "non_existent" anomaly branch without touching any real fixture row.
    CurrentConfig::setGuestId(2);
    CurrentConfig::setDefaultUserId(2);
    CurrentConfig::setWebmasterId(999999);

    $c13y = c13yInternalTestCheckIntegrity();
    new C13yInternal()->c13y_user($c13y);

    expect($c13y->retrieve_list)->toHaveCount(1);
    $anomaly = $c13y->retrieve_list[0];
    expect($anomaly['correction_fct'])->toBe('c13y_correction_user');
    expect($anomaly['correction_fct_args'])->toBe(['id' => 999999, 'action' => 'creation']);

    $conn = DbConnection::build();

    try {
        $result = new C13yInternal()->c13y_correction_user(999999, 'creation');
        expect($result)->toBeTrue();

        $row = $conn->fetchAssociative('SELECT username FROM ' . \Piwigo\Db\Tables::users() . ' WHERE id = 999999');
        expect($row)->not->toBeFalse();
        expect(is_array($row) ? $row['username'] : null)->toStartWith('webmaster');

        $infosRow = $conn->fetchAssociative('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 999999');
        expect($infosRow)->not->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 999999');
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::users() . ' WHERE id = 999999');
    }
});

test('c13y_user flags a real user whose status does not match the expected one, and c13y_correction_user fixes it', function (): void {
    CurrentConfig::setGuestId(2);
    CurrentConfig::setDefaultUserId(2);
    CurrentConfig::setWebmasterId(1);

    $conn = DbConnection::build();
    $originalStatus = $conn->fetchOne('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 1');
    expect($originalStatus)->not->toBeFalse();

    try {
        $conn->executeStatement("UPDATE " . \Piwigo\Db\Tables::userInfos() . " SET status = 'normal' WHERE user_id = 1");

        $c13y = c13yInternalTestCheckIntegrity();
        new C13yInternal()->c13y_user($c13y);

        $webmasterAnomaly = null;
        foreach ($c13y->retrieve_list as $anomaly) {
            if (($anomaly['correction_fct_args']['id'] ?? null) === 1) {
                $webmasterAnomaly = $anomaly;
            }
        }
        if ($webmasterAnomaly === null) {
            throw new RuntimeException('Expected c13y_user() to flag the webmaster user\'s mismatched status');
        }
        expect($webmasterAnomaly['correction_fct_args'])->toBe(['id' => 1, 'action' => 'status']);

        $result = new C13yInternal()->c13y_correction_user(1, 'status');
        expect($result)->toBeTrue();

        $fixedStatus = $conn->fetchOne('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 1');
        expect($fixedStatus)->toBe('webmaster');
    } finally {
        $conn->executeStatement(sprintf(
            "UPDATE %s SET status = %s WHERE user_id = 1",
            \Piwigo\Db\Tables::userInfos(),
            $conn->quote(is_string($originalStatus) ? $originalStatus : 'webmaster')
        ));
    }
});

test('c13y_correction_user does nothing for id 0', function (): void {
    expect(new C13yInternal()->c13y_correction_user(0, 'creation'))->toBeFalse();
});

test('c13y_correction_user does nothing for an unrecognized action', function (): void {
    expect(new C13yInternal()->c13y_correction_user(1, 'not-a-real-action'))->toBeFalse();
});

test('c13y_user flags a configured default_user_id distinct from guest_id that has no matching user row', function (): void {
    // Every other c13y_user() test in this file keeps guest_id and
    // default_user_id equal (both the real fixture "guest" id, 2), so
    // c13y_user()'s own `if ($guest_id !== $default_user_id)` guard around
    // building the default_user_id slot of $c13y_users never actually ran.
    // This is the one test that diverges them, isolating that branch the
    // same way the existing webmaster test isolates its own slot.
    CurrentConfig::setGuestId(2);
    CurrentConfig::setDefaultUserId(999995);
    CurrentConfig::setWebmasterId(1);

    $c13y = c13yInternalTestCheckIntegrity();
    new C13yInternal()->c13y_user($c13y);

    expect($c13y->retrieve_list)->toHaveCount(1);
    $anomaly = $c13y->retrieve_list[0];
    expect($anomaly['correction_fct'])->toBe('c13y_correction_user');
    expect($anomaly['correction_fct_args'])->toBe(['id' => 999995, 'action' => 'creation']);
});

test('c13y_correction_user creates the guest_id slot for a "creation" action, renaming around the real "guest" username collision', function (): void {
    // guest_id itself is pointed at a synthetic, not-yet-existing id so the
    // insert has nowhere to collide on the primary key, but the candidate
    // *username* ("guest") is real (fixture id 2 is genuinely named
    // "guest") -- this forces the `while (! $name_ok)` loop to actually
    // retry with a generated suffix instead of succeeding on its first
    // pass, covering both the `$id === $guest_id` branch and the
    // rename-on-collision line inside the loop.
    CurrentConfig::setGuestId(999997);
    CurrentConfig::setDefaultUserId(2);
    CurrentConfig::setWebmasterId(1);

    $conn = DbConnection::build();
    try {
        $result = new C13yInternal()->c13y_correction_user(999997, 'creation');
        expect($result)->toBeTrue();

        $row = $conn->fetchAssociative('SELECT username, password FROM ' . \Piwigo\Db\Tables::users() . ' WHERE id = 999997');
        expect($row)->not->toBeFalse();
        expect(is_array($row) ? $row['username'] : null)->toStartWith('guest');
        // Unlike the webmaster branch (tested above), the guest_id branch
        // never sets $password -- it stays the loop's initial null.
        expect(is_array($row) ? $row['password'] : 'unexpected-fetch-failure')->toBeNull();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 999997');
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::users() . ' WHERE id = 999997');
    }
});

test('c13y_correction_user creates the default_user_id slot for a "creation" action', function (): void {
    CurrentConfig::setGuestId(2);
    CurrentConfig::setDefaultUserId(999996);
    CurrentConfig::setWebmasterId(1);

    $conn = DbConnection::build();
    try {
        $result = new C13yInternal()->c13y_correction_user(999996, 'creation');
        expect($result)->toBeTrue();

        $row = $conn->fetchAssociative('SELECT username FROM ' . \Piwigo\Db\Tables::users() . ' WHERE id = 999996');
        expect($row)->not->toBeFalse();
        expect(is_array($row) ? $row['username'] : null)->toStartWith('guest');
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 999996');
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::users() . ' WHERE id = 999996');
    }
});

test('c13y_correction_user sets a real user\'s status to "guest" when its id matches the configured guest_id', function (): void {
    // Reuses a real, unrelated fixture row (id 3, "regular_user") purely as
    // the stand-in guest_id slot to drive the `$id === $guest_id` branch of
    // the 'status' action -- the same "hijack CurrentConfig, act on a real
    // row" technique the existing webmaster-status test above uses for id 1.
    CurrentConfig::setGuestId(3);
    CurrentConfig::setDefaultUserId(2);
    CurrentConfig::setWebmasterId(1);

    $conn = DbConnection::build();
    $originalStatus = $conn->fetchOne('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 3');
    expect($originalStatus)->not->toBeFalse();

    try {
        $result = new C13yInternal()->c13y_correction_user(3, 'status');
        expect($result)->toBeTrue();

        $fixedStatus = $conn->fetchOne('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 3');
        expect($fixedStatus)->toBe('guest');
    } finally {
        $conn->executeStatement(sprintf(
            "UPDATE %s SET status = %s WHERE user_id = 3",
            \Piwigo\Db\Tables::userInfos(),
            $conn->quote(is_string($originalStatus) ? $originalStatus : 'normal')
        ));
    }
});

test('c13y_correction_user sets a real user\'s status to "guest" when its id matches the configured default_user_id', function (): void {
    CurrentConfig::setGuestId(2);
    CurrentConfig::setDefaultUserId(4);
    CurrentConfig::setWebmasterId(1);

    $conn = DbConnection::build();
    $originalStatus = $conn->fetchOne('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 4');
    expect($originalStatus)->not->toBeFalse();

    try {
        $result = new C13yInternal()->c13y_correction_user(4, 'status');
        expect($result)->toBeTrue();

        $fixedStatus = $conn->fetchOne('SELECT status FROM ' . \Piwigo\Db\Tables::userInfos() . ' WHERE user_id = 4');
        expect($fixedStatus)->toBe('guest');
    } finally {
        $conn->executeStatement(sprintf(
            "UPDATE %s SET status = %s WHERE user_id = 4",
            \Piwigo\Db\Tables::userInfos(),
            $conn->quote(is_string($originalStatus) ? $originalStatus : 'normal')
        ));
    }
});
