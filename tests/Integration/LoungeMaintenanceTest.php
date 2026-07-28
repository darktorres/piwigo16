<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\LoungeMaintenance;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Piwigo\Core\LoungeMaintenance::needsEmptying() -- had zero dedicated
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1). loungeActive defaults false, so every real caller's happy path
 * never reaches this method's own real logic without deliberately
 * enabling it.
 */
final class LoungeMaintenanceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private string $originalDateAvailable;

    #[\Override]
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
        CurrentConfig::setLoungeActive(false);
        CurrentConfig::setLoungeMaxDuration(300);
        unset($_REQUEST['method']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::lounge());
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
            [$this->originalDateAvailable]
        );
        CurrentConfig::setLoungeActive(false);
        CurrentConfig::setLoungeMaxDuration(300);
        unset($_REQUEST['method']);
        parent::tearDown();
    }

    public function test_needsEmptying_is_false_when_lounge_active_is_disabled(): void
    {
        self::assertFalse(LoungeMaintenance::needsEmptying());
    }

    public function test_needsEmptying_is_false_when_the_lounge_is_empty(): void
    {
        CurrentConfig::setLoungeActive(true);

        self::assertFalse(LoungeMaintenance::needsEmptying());
    }

    public function test_needsEmptying_is_true_once_the_oldest_lounge_photo_exceeds_the_max_duration(): void
    {
        CurrentConfig::setLoungeActive(true);
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = 1'
        );
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        self::assertTrue(LoungeMaintenance::needsEmptying());
    }

    public function test_needsEmptying_is_false_when_the_oldest_lounge_photo_is_still_within_the_max_duration(): void
    {
        CurrentConfig::setLoungeActive(true);
        $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_available = NOW() WHERE id = 1');
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        self::assertFalse(LoungeMaintenance::needsEmptying());
    }

    public function test_needsEmptying_skips_the_check_during_an_active_upload_request(): void
    {
        CurrentConfig::setLoungeActive(true);
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = 1'
        );
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        $_REQUEST['method'] = 'pwg.images.upload';
        self::assertFalse(LoungeMaintenance::needsEmptying());

        $_REQUEST['method'] = 'pwg.images.uploadAsync';
        self::assertFalse(LoungeMaintenance::needsEmptying());
    }
}
