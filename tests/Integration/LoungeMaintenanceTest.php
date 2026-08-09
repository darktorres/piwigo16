<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\Env;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\LoungeMaintenance;

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

        $this->conn = DbConnection::build();
        $dateAvailable = $this->conn->fetchOne('SELECT date_available FROM ' . Tables::images() . ' WHERE id = 1');
        self::assertIsString($dateAvailable);
        $this->originalDateAvailable = $dateAvailable;

        $this->conn->executeStatement('DELETE FROM ' . Tables::lounge());
        $this->currentConfig()->setLoungeActive(false);
        $this->currentConfig()->setLoungeMaxDuration(300);
        unset($_REQUEST['method']);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::lounge());
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
            [$this->originalDateAvailable]
        );
        $this->currentConfig()->setLoungeActive(false);
        $this->currentConfig()->setLoungeMaxDuration(300);
        unset($_REQUEST['method']);
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

    public function test_needsEmptying_is_false_when_lounge_active_is_disabled(): void
    {
        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get()));
    }

    public function test_needsEmptying_is_false_when_the_lounge_is_empty(): void
    {
        $this->currentConfig()->setLoungeActive(true);

        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get()));
    }

    public function test_needsEmptying_is_true_once_the_oldest_lounge_photo_exceeds_the_max_duration(): void
    {
        // Anchored on Env::now() rather than the DB server's own real
        // NOW(), matching the real bug fixed in needsEmptying()/
        // findOldestLoungeAgeInfo() itself: the two clock
        // sources agreed only as long as real wall-clock time stayed close
        // to a frozen PIWIGO_TEST_NOW, and drifted apart the moment it
        // didn't.
        $this->currentConfig()->setLoungeActive(true);
        $anHourAgo = Env::now()->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
            [$anHourAgo]
        );
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        self::assertTrue(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get()));
    }

    public function test_needsEmptying_is_false_when_the_oldest_lounge_photo_is_still_within_the_max_duration(): void
    {
        $this->currentConfig()->setLoungeActive(true);
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
            [Env::now()->format('Y-m-d H:i:s')]
        );
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get()));
    }

    public function test_needsEmptying_skips_the_check_during_an_active_upload_request(): void
    {
        $this->currentConfig()->setLoungeActive(true);
        $anHourAgo = Env::now()->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
            [$anHourAgo]
        );
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        $_REQUEST['method'] = 'pwg.images.upload';
        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get()));

        $_REQUEST['method'] = 'pwg.images.uploadAsync';
        self::assertFalse(LoungeMaintenance::needsEmptying(CurrentConfigTestFactory::get()));
    }
}
