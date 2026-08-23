<?php

declare(strict_types=1);

use Piwigo\Admin\InstallationStats;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;

// InstallationStats is a container-shared, constructor-injected instance,
// like RateService/HistoryService/ImageService/CategoryService/TagService/
// UserService/GroupService, so every test in this file needs a booted
// container -- same convention as the other Integration tests that touch
// container-backed services.
beforeEach(function (): void {
    // A real Paths is required too, not just any booted Kernel:
    // UserService (one of InstallationStats' own constructor deps) itself
    // constructor-injects DeploymentPolicy, whose own container factory
    // needs Paths bound to autowire.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
});

afterEach(function (): void {
    Kernel::reset();
});

function installation_stats_test_make(): InstallationStats
{
    $installationStats = Kernel::container()->get(InstallationStats::class);
    if (! $installationStats instanceof InstallationStats) {
        throw new LogicException('Container returned an unexpected type for ' . InstallationStats::class);
    }

    return $installationStats;
}

test('getGeneralStatistics returns the full known shape with non-negative integer values', function (): void {
    $stats = installation_stats_test_make()
        ->getGeneralStatistics()
        ->toArray();

    expect(array_keys($stats))
        ->toBe([
            'nb_photos', 'nb_categories', 'nb_tags', 'nb_image_tag', 'nb_users',
            'nb_admins', 'nb_groups', 'nb_rates', 'nb_views', 'disk_usage',
            'nb_formats', 'formats_disk_usage',
        ]);
    foreach ($stats as $key => $value) {
        expect($value)->toBeGreaterThanOrEqual(0, "{$key} should be >= 0");
    }
    // The real fixture DB always has at least the webmaster account.
    expect($stats['nb_users'])->toBeGreaterThan(0);
    expect($stats['nb_admins'])->toBeGreaterThan(0);
});

test('getGeneralStatistics reflects a real, freshly-inserted category and tag as an exact +1 delta', function (): void {
    $before = installation_stats_test_make()
        ->getGeneralStatistics();

    $conn = DbConnection::build();
    $conn->executeStatement(sprintf(
        "INSERT INTO %s (name) VALUES ('Installation Stats Test Album')",
        'categories'
    ));
    $categoryId = (int) $conn->lastInsertId();
    $conn->executeStatement(sprintf(
        "INSERT INTO %s (name, url_name) VALUES ('installation-stats-test-tag', 'installation-stats-test-tag')",
        'tags'
    ));
    $tagId = (int) $conn->lastInsertId();

    try {
        $after = installation_stats_test_make()
            ->getGeneralStatistics();

        expect($after->nbCategories)
            ->toBe($before->nbCategories + 1);
        expect($after->nbTags)
            ->toBe($before->nbTags + 1);
    } finally {
        $conn->executeStatement('DELETE FROM tags WHERE id = ' . $tagId);
        $conn->executeStatement('DELETE FROM categories WHERE id = ' . $categoryId);
    }
});

test('getGeneralStatistics sums image filesize plus format filesize into disk_usage', function (): void {
    $before = installation_stats_test_make()
        ->getGeneralStatistics();

    $conn = DbConnection::build();
    $conn->executeStatement(sprintf(
        "INSERT INTO %s (name, path, filesize, representative_ext, added_by, date_available)
         VALUES ('Installation Stats Test Photo', '/tmp/installation-stats-test.jpg', 12345, NULL, 1, NOW())",
        'images'
    ));
    $imageId = (int) $conn->lastInsertId();
    $conn->executeStatement(sprintf("INSERT INTO image_format (image_id, ext, filesize) VALUES (%d, 'tif', 6789)", $imageId));

    try {
        $after = installation_stats_test_make()
            ->getGeneralStatistics();

        expect($after->nbPhotos)
            ->toBe($before->nbPhotos + 1);
        expect($after->nbFormats)
            ->toBe($before->nbFormats + 1);
        expect($after->formatsDiskUsage)
            ->toBe($before->formatsDiskUsage + 6789);
        expect($after->diskUsage)
            ->toBe($before->diskUsage + 12345 + 6789);
    } finally {
        $conn->executeStatement(sprintf('DELETE FROM image_format WHERE image_id = %d', $imageId));
        $conn->executeStatement('DELETE FROM images WHERE id = ' . $imageId);
    }
});

test('getInstallationDate returns user 2\'s own registration_date when it is a real, post-2001 date', function (): void {
    $conn = DbConnection::build();
    $original = $conn->fetchOne('SELECT registration_date FROM user_infos WHERE user_id = 2');
    expect($original)
        ->not->toBeFalse();

    try {
        $conn->executeStatement("UPDATE user_infos SET registration_date = '2020-05-15 10:00:00' WHERE user_id = 2");

        expect(installation_stats_test_make()->getInstallationDate())
            ->toBe('2020-05-15 10:00:00');
    } finally {
        $conn->executeStatement(sprintf(
            'UPDATE %s SET registration_date = %s WHERE user_id = 2',
            'user_infos',
            $conn->quote(is_string($original) ? $original : '2020-01-01 00:00:00')
        ));
    }
});

test('getInstallationDate falls back to the MIN registration_date across all users when user 2\'s own date predates piwigo\'s origin', function (): void {
    $conn = DbConnection::build();
    $originalRows = $conn->fetchAllAssociative('SELECT user_id, registration_date FROM user_infos');

    try {
        // Push every real user's registration_date before piwigo's own
        // 2001-09-01 origin, then insert one fresh, valid user whose
        // registration_date should become the real MIN() fallback.
        $conn->executeStatement("UPDATE user_infos SET registration_date = '1999-01-01 00:00:00'");
        $conn->executeStatement("INSERT INTO users (username, password, mail_address) VALUES ('installation-stats-fallback-user', NULL, NULL)");
        $newUserId = (int) $conn->lastInsertId();
        $conn->executeStatement(sprintf(
            "INSERT INTO %s (user_id, status, registration_date) VALUES (%d, 'normal', '2023-03-10 08:00:00')",
            'user_infos',
            $newUserId
        ));

        expect(installation_stats_test_make()->getInstallationDate())
            ->toBe('2023-03-10 08:00:00');

        $conn->executeStatement(sprintf('DELETE FROM %s WHERE user_id = %d', 'user_infos', $newUserId));
        $conn->executeStatement(sprintf('DELETE FROM users WHERE id = %d', $newUserId));
    } finally {
        foreach ($originalRows as $row) {
            $conn->executeStatement(sprintf(
                'UPDATE %s SET registration_date = %s WHERE user_id = %d',
                'user_infos',
                $conn->quote(is_string($row['registration_date']) ? $row['registration_date'] : '2020-01-01 00:00:00'),
                $row['user_id']
            ));
        }
    }
});

test('getInstallationDate falls back to the earliest image\'s date_available when no user has a valid registration_date', function (): void {
    $conn = DbConnection::build();
    $originalRows = $conn->fetchAllAssociative('SELECT user_id, registration_date FROM user_infos');

    try {
        $conn->executeStatement("UPDATE user_infos SET registration_date = '1999-01-01 00:00:00'");

        $earliestDateAvailable = $conn->fetchOne('SELECT date_available FROM images ORDER BY id ASC LIMIT 1');

        if ($earliestDateAvailable === false) {
            // No fixture photos at all -- both DB-backed candidates are
            // exhausted, so the method has nothing left to return.
            expect(installation_stats_test_make()->getInstallationDate())
                ->toBeNull();
        } else {
            expect(installation_stats_test_make()->getInstallationDate())
                ->toBe($earliestDateAvailable);
        }
    } finally {
        foreach ($originalRows as $row) {
            $conn->executeStatement(sprintf(
                'UPDATE %s SET registration_date = %s WHERE user_id = %d',
                'user_infos',
                $conn->quote(is_string($row['registration_date']) ? $row['registration_date'] : '2020-01-01 00:00:00'),
                $row['user_id']
            ));
        }
    }
});
