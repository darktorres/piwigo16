<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;

/**
 * Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository -- has no
 * dedicated Integration test file of its own (spec ported down from
 * tests/Integration/CheckIntegrityTest.php + Admin/Integrity/
 * CheckIntegrityAddAnomalyTest.php + MaintenancePurgeFailedLoginsCommandTest.php,
 * which exercise it only indirectly through CheckIntegrity's/the
 * maintenance command's own real flows). Real DB, no HTTP -- same
 * ImageRepositoryTest.php precedent as every other Unit-suite Repository
 * test.
 */
function integrityIgnoredAnomalyTestRepo(): IntegrityIgnoredAnomalyRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(IntegrityIgnoredAnomalyEntity::class);

    return $repo;
}

function integrityIgnoredAnomalyTestPurgeVersion(Connection $conn, string $piwigoVersion): void
{
    $conn->createQueryBuilder()
        ->delete(Tables::integrityIgnoredAnomalies())
        ->where('piwigo_version = :v')
        ->setParameter('v', $piwigoVersion)
        ->executeStatement();
}

test('findIgnoredAnomalyIdsForVersion returns only ids scoped to that version', function (): void {
    $conn = DbConnection::build();
    // piwigo_version is VARCHAR(16) -- these must fit.
    $version = 'ut-find-a';
    $otherVersion = 'ut-find-b';

    try {
        $repo = integrityIgnoredAnomalyTestRepo();
        $repo->syncForVersion($version, ['exif_missing', 'user_orphan'], '2026-08-01 12:00:00');
        $repo->syncForVersion($otherVersion, ['unrelated'], '2026-08-01 12:00:00');

        $ids = $repo->findIgnoredAnomalyIdsForVersion($version);
        sort($ids);

        expect($ids)->toBe(['exif_missing', 'user_orphan']);
    } finally {
        integrityIgnoredAnomalyTestPurgeVersion($conn, $version);
        integrityIgnoredAnomalyTestPurgeVersion($conn, $otherVersion);
    }
});

test('syncForVersion inserts newly-ignored ids and deletes ids no longer in the set', function (): void {
    $conn = DbConnection::build();
    $version = 'unit-test-sync-1';

    try {
        $repo = integrityIgnoredAnomalyTestRepo();
        $repo->syncForVersion($version, ['a', 'b'], '2026-08-01 12:00:00');
        $repo->syncForVersion($version, ['b', 'c'], '2026-08-02 12:00:00');

        $ids = $repo->findIgnoredAnomalyIdsForVersion($version);
        sort($ids);

        expect($ids)->toBe(['b', 'c']);
    } finally {
        integrityIgnoredAnomalyTestPurgeVersion($conn, $version);
    }
});

test('syncForVersion leaves an already-ignored id\'s own ignored_at untouched on re-sync, not bumped to $now', function (): void {
    $conn = DbConnection::build();
    $version = 'unit-test-sync-2';

    try {
        $repo = integrityIgnoredAnomalyTestRepo();
        $repo->syncForVersion($version, ['still_ignored'], '2026-08-01 12:00:00');
        $repo->syncForVersion($version, ['still_ignored'], '2026-08-05 09:00:00');

        $ignoredAt = $conn->createQueryBuilder()
            ->select('ignored_at')
            ->from(Tables::integrityIgnoredAnomalies())
            ->where('piwigo_version = :v AND anomaly_id = :a')
            ->setParameter('v', $version)
            ->setParameter('a', 'still_ignored')
            ->fetchOne();

        expect($ignoredAt)->toBe('2026-08-01 12:00:00');
    } finally {
        integrityIgnoredAnomalyTestPurgeVersion($conn, $version);
    }
});

test('purgeOlderThan deletes rows ignored before the threshold, keeps rows at or after it, and returns the deleted count', function (): void {
    $conn = DbConnection::build();
    $oldVersion = 'ut-purge-old';
    $recentVersion = 'ut-purge-new';

    try {
        $repo = integrityIgnoredAnomalyTestRepo();
        $repo->syncForVersion($oldVersion, ['old_one', 'old_two'], '2020-01-01 00:00:00');
        $repo->syncForVersion($recentVersion, ['recent_one'], '2026-08-01 00:00:00');

        $deleted = $repo->purgeOlderThan('2025-01-01 00:00:00');

        // purgeOlderThan() is deliberately global (no version filter) --
        // >=2 rather than ===2 so this doesn't depend on no other old-dated
        // row existing anywhere else in the shared test DB at the same time.
        expect($deleted)->toBeGreaterThanOrEqual(2);

        $remainingIds = $repo->findIgnoredAnomalyIdsForVersion($recentVersion);
        expect($remainingIds)->toBe(['recent_one']);

        $purgedIds = $repo->findIgnoredAnomalyIdsForVersion($oldVersion);
        expect($purgedIds)->toBe([]);
    } finally {
        integrityIgnoredAnomalyTestPurgeVersion($conn, $oldVersion);
        integrityIgnoredAnomalyTestPurgeVersion($conn, $recentVersion);
    }
});
