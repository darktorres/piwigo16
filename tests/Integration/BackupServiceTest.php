<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Backup\BackupService;

/**
 * Real command-level proof for `bin/piwigo backup:create`/`backup:restore`,
 * replacing the rewire tools/restore-drill.sh's own header comment
 * anticipated ("Rewire onto the real bin/piwigo backup:restore once P12
 * lands"). Deliberately NOT wired into that script/its CI job instead --
 * see docs/PLAN-REPLAY.md P12's scope-decision section for why: that job
 * is intentionally PHP-dependency-free (~10s, no composer install step),
 * and adding one just for this would regress its own deliberate
 * minimalism. This test reuses the exact row-count + join-query smoke
 * assertions restore-drill.sh established, against a real archive produced
 * by the real service -- the round trip restore-drill.sh's own comment was
 * waiting for, just proven here instead.
 *
 * Restores into its own scratch database, never piwigo_test itself --
 * same safety discipline as tools/restore-drill.sh.
 */
final class BackupServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private string $scratchDb = '';

    private string $archivePath = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (!self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-16.x.sql');
            self::$fixtureReady = true;
        }

        $this->scratchDb = $this->dbName . '_backup_service_test';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratchDb));
        $db->close();

        if ($this->archivePath !== '' && is_file($this->archivePath)) {
            unlink($this->archivePath);
        }

        parent::tearDown();
    }

    public function test_create_then_restore_round_trips_real_data(): void
    {
        $service = new BackupService();

        $this->archivePath = $service->create();
        self::assertFileExists($this->archivePath);

        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratchDb));
        $db->query(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->scratchDb
        ));
        $db->close();

        $service->restore($this->archivePath, $this->scratchDb);

        $scratch = $this->newMysqli($this->scratchDb);

        $imagesResult = $scratch->query(sprintf('SELECT COUNT(*) FROM `%simages`', $this->dbPrefix));
        self::assertInstanceOf(\mysqli_result::class, $imagesResult);
        $imageCount = (int) $imagesResult->fetch_row()[0];

        $usersResult = $scratch->query(sprintf('SELECT COUNT(*) FROM `%susers`', $this->dbPrefix));
        self::assertInstanceOf(\mysqli_result::class, $usersResult);
        $userCount = (int) $usersResult->fetch_row()[0];

        self::assertGreaterThanOrEqual(1, $imageCount, 'Restored DB should have at least one image');
        self::assertGreaterThanOrEqual(1, $userCount, 'Restored DB should have at least one user');

        // Schema smoke query: a join across tables proves the schema itself
        // (not just raw row presence) survived the restore intact -- same
        // assertion tools/restore-drill.sh makes.
        $joinResult = $scratch->query(sprintf(
            'SELECT i.id FROM `%1$simages` i JOIN `%1$simage_category` ic ON ic.image_id = i.id LIMIT 1',
            $this->dbPrefix
        ));
        self::assertInstanceOf(\mysqli_result::class, $joinResult);

        $scratch->close();
    }

    public function test_restore_rejects_a_corrupt_archive(): void
    {
        $badArchive = tempnam(sys_get_temp_dir(), 'piwigo-bad-backup-');
        self::assertIsString($badArchive);
        file_put_contents($badArchive, 'not a real archive');

        $service = new BackupService();

        try {
            $this->expectException(\RuntimeException::class);
            $service->restore($badArchive, $this->scratchDb);
        } finally {
            unlink($badArchive);
        }
    }
}
