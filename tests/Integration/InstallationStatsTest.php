<?php

declare(strict_types=1);

use Piwigo\Admin\InstallationStats;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

function installationStatsDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

// InstallationStats reads several of its counts through Bootstrap\
// CoreDomainAccessor/ExtendedDomainAccessor (TagRepository/GroupRepository/
// HistoryRepository/ImageRepository/UserRepository/RateService), so every
// test in this file needs a booted container -- same convention as the
// other Integration tests that touch container-backed services.
beforeEach(function (): void {
    // A real Paths is required too, not just any booted Kernel:
    // CoreDomainAccessor::userService() resolves UserService, which now
    // constructor-injects DeploymentPolicy (Phase 4), whose own container
    // factory needs Paths bound to autowire.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getGeneralStatistics returns the full known shape with non-negative integer values', function (): void {
    $stats = InstallationStats::getGeneralStatistics();

    expect(array_keys($stats))->toBe([
        'nb_photos', 'nb_categories', 'nb_tags', 'nb_image_tag', 'nb_users',
        'nb_admins', 'nb_groups', 'nb_rates', 'nb_views', 'disk_usage',
        'nb_formats', 'formats_disk_usage',
    ]);
    foreach ($stats as $key => $value) {
        expect($value)->toBeInt("{$key} should be an int");
        expect($value)->toBeGreaterThanOrEqual(0, "{$key} should be >= 0");
    }
    // The real fixture DB always has at least the webmaster account.
    expect($stats['nb_users'])->toBeGreaterThan(0);
    expect($stats['nb_admins'])->toBeGreaterThan(0);
});

test('getGeneralStatistics reflects a real, freshly-inserted category and tag as an exact +1 delta', function (): void {
    $before = InstallationStats::getGeneralStatistics();

    $conn = DbConnection::build();
    $conn->executeStatement(sprintf(
        "INSERT INTO %s (name) VALUES ('Installation Stats Test Album')",
        Tables::categories()
    ));
    $categoryId = (int) $conn->lastInsertId();
    $conn->executeStatement(sprintf(
        "INSERT INTO %s (name, url_name) VALUES ('installation-stats-test-tag', 'installation-stats-test-tag')",
        Tables::tags()
    ));
    $tagId = (int) $conn->lastInsertId();

    try {
        $after = InstallationStats::getGeneralStatistics();

        expect($after['nb_categories'])->toBe($before['nb_categories'] + 1);
        expect($after['nb_tags'])->toBe($before['nb_tags'] + 1);
    } finally {
        $conn->executeStatement('DELETE FROM ' . Tables::tags() . ' WHERE id = ' . $tagId);
        $conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ' . $categoryId);
    }
});

test('getGeneralStatistics sums image filesize plus format filesize into disk_usage', function (): void {
    $before = InstallationStats::getGeneralStatistics();

    $conn = DbConnection::build();
    $prefix = installationStatsDbPrefix();
    $conn->executeStatement(sprintf(
        "INSERT INTO %s (name, path, filesize, representative_ext, added_by, date_available)
         VALUES ('Installation Stats Test Photo', '/tmp/installation-stats-test.jpg', 12345, NULL, 1, NOW())",
        Tables::images()
    ));
    $imageId = (int) $conn->lastInsertId();
    $conn->executeStatement(sprintf(
        "INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, 'tif', 6789)",
        $prefix,
        $imageId
    ));

    try {
        $after = InstallationStats::getGeneralStatistics();

        expect($after['nb_photos'])->toBe($before['nb_photos'] + 1);
        expect($after['nb_formats'])->toBe($before['nb_formats'] + 1);
        expect($after['formats_disk_usage'])->toBe($before['formats_disk_usage'] + 6789);
        expect($after['disk_usage'])->toBe($before['disk_usage'] + 12345 + 6789);
    } finally {
        $conn->executeStatement(sprintf('DELETE FROM %simage_format WHERE image_id = %d', $prefix, $imageId));
        $conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ' . $imageId);
    }
});

test('getInstallationDate returns user 2\'s own registration_date when it is a real, post-2001 date', function (): void {
    $conn = DbConnection::build();
    $original = $conn->fetchOne('SELECT registration_date FROM ' . Tables::userInfos() . ' WHERE user_id = 2');
    expect($original)->not->toBeFalse();

    try {
        $conn->executeStatement("UPDATE " . Tables::userInfos() . " SET registration_date = '2020-05-15 10:00:00' WHERE user_id = 2");

        expect(InstallationStats::getInstallationDate())->toBe('2020-05-15 10:00:00');
    } finally {
        $conn->executeStatement(sprintf(
            "UPDATE %s SET registration_date = %s WHERE user_id = 2",
            Tables::userInfos(),
            $conn->quote(is_string($original) ? $original : '2020-01-01 00:00:00')
        ));
    }
});

test('getInstallationDate falls back to the MIN registration_date across all users when user 2\'s own date predates piwigo\'s origin', function (): void {
    $conn = DbConnection::build();
    $prefix = installationStatsDbPrefix();
    $originalRows = $conn->fetchAllAssociative('SELECT user_id, registration_date FROM ' . Tables::userInfos());

    try {
        // Push every real user's registration_date before piwigo's own
        // 2001-09-01 origin, then insert one fresh, valid user whose
        // registration_date should become the real MIN() fallback.
        $conn->executeStatement("UPDATE " . Tables::userInfos() . " SET registration_date = '1999-01-01 00:00:00'");
        $conn->executeStatement(sprintf(
            "INSERT INTO %susers (username, password, mail_address) VALUES ('installation-stats-fallback-user', NULL, NULL)",
            $prefix
        ));
        $newUserId = (int) $conn->lastInsertId();
        $conn->executeStatement(sprintf(
            "INSERT INTO %s (user_id, status, registration_date) VALUES (%d, 'normal', '2023-03-10 08:00:00')",
            Tables::userInfos(),
            $newUserId
        ));

        expect(InstallationStats::getInstallationDate())->toBe('2023-03-10 08:00:00');

        $conn->executeStatement(sprintf('DELETE FROM %s WHERE user_id = %d', Tables::userInfos(), $newUserId));
        $conn->executeStatement(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $newUserId));
    } finally {
        foreach ($originalRows as $row) {
            $rowUserId = $row['user_id'];
            if (! is_numeric($rowUserId)) {
                throw new RuntimeException('Expected user_id to be numeric: ' . var_export($rowUserId, true));
            }
            $conn->executeStatement(sprintf(
                'UPDATE %s SET registration_date = %s WHERE user_id = %d',
                Tables::userInfos(),
                $conn->quote(is_string($row['registration_date']) ? $row['registration_date'] : '2020-01-01 00:00:00'),
                (int) $rowUserId
            ));
        }
    }
});

test('getInstallationDate falls back to the earliest image\'s date_available when no user has a valid registration_date', function (): void {
    $conn = DbConnection::build();
    $originalRows = $conn->fetchAllAssociative('SELECT user_id, registration_date FROM ' . Tables::userInfos());

    try {
        $conn->executeStatement("UPDATE " . Tables::userInfos() . " SET registration_date = '1999-01-01 00:00:00'");

        $earliestDateAvailable = $conn->fetchOne('SELECT date_available FROM ' . Tables::images() . ' ORDER BY id ASC LIMIT 1');

        if ($earliestDateAvailable === false) {
            // No fixture photos at all -- both DB-backed candidates are
            // exhausted, so the method has nothing left to return.
            expect(InstallationStats::getInstallationDate())->toBeNull();
        } else {
            expect(InstallationStats::getInstallationDate())->toBe($earliestDateAvailable);
        }
    } finally {
        foreach ($originalRows as $row) {
            $rowUserId = $row['user_id'];
            if (! is_numeric($rowUserId)) {
                throw new RuntimeException('Expected user_id to be numeric: ' . var_export($rowUserId, true));
            }
            $conn->executeStatement(sprintf(
                'UPDATE %s SET registration_date = %s WHERE user_id = %d',
                Tables::userInfos(),
                $conn->quote(is_string($row['registration_date']) ? $row['registration_date'] : '2020-01-01 00:00:00'),
                (int) $rowUserId
            ));
        }
    }
});