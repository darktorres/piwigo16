<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;

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
    $c13y = new CheckIntegrity();

    new C13yInternal()->c13y_version($c13y);

    expect($c13y->retrieve_list)->toBe([]);
});

test('c13y_exif adds no anomaly when exif_read_data() is available', function (): void {
    expect(function_exists('exif_read_data'))->toBeTrue();

    $c13y = new CheckIntegrity();
    new C13yInternal()->c13y_exif($c13y);

    expect($c13y->retrieve_list)->toBe([]);
});

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

    $c13y = new CheckIntegrity();
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

        $c13y = new CheckIntegrity();
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
