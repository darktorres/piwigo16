<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\LoungeMaintenance;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * loungeActive defaults false, so every real caller's happy path never
 * reaches needsEmptying()'s own real logic without deliberately enabling
 * it.
 */
final class LoungeMaintenanceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private string $originalDateAvailable;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $this->conn = DbConnection::build();
        $dateAvailable = $this->conn->fetchOne('SELECT date_available FROM images WHERE id = 1');
        self::assertIsString($dateAvailable);
        $this->originalDateAvailable = $dateAvailable;

        $this->conn->executeStatement('DELETE FROM lounge');
        $this->currentConfig()
            ->loungeActive = false;
        $this->currentConfig()
            ->loungeMaxDuration = 300;
        unset($_REQUEST['method']);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM lounge');
        $this->conn->executeStatement(
            'UPDATE images SET date_available = ? WHERE id = 1',
            [$this->originalDateAvailable]
        );
        $this->currentConfig()
            ->loungeActive = false;
        $this->currentConfig()
            ->loungeMaxDuration = 300;
        unset($_REQUEST['method']);
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    private function currentConfig(): CurrentConfig
    {
        // Kernel is already booted by parent::setUp() -- resolve the same
        // container-shared instance a real request would get, matching
        // this suite's own userService()/accessControl() private-helper
        // convention.
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }

        return $currentConfig;
    }

    public function testNeedsEmptyingIsFalseWhenLoungeActiveIsDisabled(): void
    {
        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get(), EntityManagerFactory::build($this->conn)));
    }

    public function testNeedsEmptyingIsFalseWhenTheLoungeIsEmpty(): void
    {
        $this->currentConfig()
            ->loungeActive = true;

        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get(), EntityManagerFactory::build($this->conn)));
    }

    public function testNeedsEmptyingIsTrueOnceTheOldestLoungePhotoExceedsTheMaxDuration(): void
    {
        // Anchored on Env::now() rather than the DB server's own real
        // NOW(), matching the real bug fixed in needsEmptying()/
        // findOldestLoungeAgeInfo() itself: the two clock
        // sources agreed only as long as real wall-clock time stayed close
        // to a frozen PIWIGO_TEST_NOW, and drifted apart the moment it
        // didn't.
        $this->currentConfig()
            ->loungeActive = true;
        $anHourAgo = Env::now()->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE images SET date_available = ? WHERE id = 1',
            [$anHourAgo]
        );
        $this->conn->executeStatement('INSERT INTO lounge (image_id, category_id) VALUES (1, 1)');

        self::assertTrue(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get(), EntityManagerFactory::build($this->conn)));
    }

    public function testNeedsEmptyingIsFalseWhenTheOldestLoungePhotoIsStillWithinTheMaxDuration(): void
    {
        $this->currentConfig()
            ->loungeActive = true;
        $this->conn->executeStatement(
            'UPDATE images SET date_available = ? WHERE id = 1',
            [Env::now()->format('Y-m-d H:i:s')]
        );
        $this->conn->executeStatement('INSERT INTO lounge (image_id, category_id) VALUES (1, 1)');

        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get(), EntityManagerFactory::build($this->conn)));
    }

    public function testNeedsEmptyingSkipsTheCheckDuringAnActiveUploadRequest(): void
    {
        $this->currentConfig()
            ->loungeActive = true;
        $anHourAgo = Env::now()->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE images SET date_available = ? WHERE id = 1',
            [$anHourAgo]
        );
        $this->conn->executeStatement('INSERT INTO lounge (image_id, category_id) VALUES (1, 1)');

        $_REQUEST['method'] = 'pwg.images.upload';
        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get(), EntityManagerFactory::build($this->conn)));

        $_REQUEST['method'] = 'pwg.images.uploadAsync';
        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get(), EntityManagerFactory::build($this->conn)));
    }
}
