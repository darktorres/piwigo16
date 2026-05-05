<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Job;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Config\Config;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\MessengerFactory;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Verifies the full Messenger dispatch → DB → consume cycle against the
 * test database defined in .env.test.
 *
 * Does NOT boot Kernel — builds the DBAL connection directly from test-env
 * credentials, then exercises MessengerFactory::build() standalone.
 */
final class MessengerBusTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-16.x.sql';

    private Connection $conn;
    private string $tableName;

    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->markTestInstalled();

        if (!defined('PHPWG_ROOT_PATH')) {
            define('PHPWG_ROOT_PATH', realpath(__DIR__ . '/../../../') . '/');
        }

        // Seed Config with the test DB prefix so MessengerFactory reads the right table name.
        Config::loadArray(['db_prefix' => 'piwigo_']);

        $this->conn = DriverManager::getConnection([
            'driver'   => 'mysqli',
            'host'     => $this->dbHost,
            'port'     => $this->dbPort,
            'user'     => $this->dbUser,
            'password' => $this->dbPass,
            'dbname'   => $this->dbName,
            'charset'  => 'utf8mb4',
        ]);

        $this->tableName = Config::dbPrefix() . 'messenger_messages';
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        Config::reset();
    }

    public function test_dispatch_inserts_row_into_messenger_table(): void
    {
        $bus = MessengerFactory::build($this->conn);
        $bus->dispatch(new GenerateDerivativeJob(1, 'thumb'));

        $count = (int) $this->conn
            ->executeQuery("SELECT COUNT(*) FROM {$this->tableName} WHERE queue_name = 'piwigo_async'")
            ->fetchOne();

        self::assertSame(1, $count, 'One row should be pending in piwigo_async after dispatch');
    }

    public function test_consume_ack_removes_row_from_messenger_table(): void
    {
        $bus       = MessengerFactory::build($this->conn);
        $transport = MessengerFactory::buildTransport('piwigo_async', $this->conn);

        $bus->dispatch(new GenerateDerivativeJob(1, 'thumb'));

        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes, 'Transport should yield one envelope');

        // Ack directly on the transport (no handler execution needed here;
        // this test is about transport ack semantics, not handler logic).
        $transport->ack($envelopes[0]);

        $pending = (int) $this->conn
            ->executeQuery("SELECT COUNT(*) FROM {$this->tableName} WHERE queue_name = 'piwigo_async' AND delivered_at IS NULL")
            ->fetchOne();

        self::assertSame(0, $pending, 'No pending rows should remain after ack');
    }

    public function test_reject_removes_row_without_moving_to_failed(): void
    {
        $bus       = MessengerFactory::build($this->conn);
        $transport = MessengerFactory::buildTransport('piwigo_async', $this->conn);

        $bus->dispatch(new GenerateDerivativeJob(99999, 'thumb'));

        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);

        $transport->reject($envelopes[0]);

        $pendingAsync = (int) $this->conn
            ->executeQuery("SELECT COUNT(*) FROM {$this->tableName} WHERE queue_name = 'piwigo_async' AND delivered_at IS NULL")
            ->fetchOne();

        self::assertSame(0, $pendingAsync, 'Rejected message must not remain in async queue');
    }
}
