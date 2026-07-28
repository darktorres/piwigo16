<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;

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
