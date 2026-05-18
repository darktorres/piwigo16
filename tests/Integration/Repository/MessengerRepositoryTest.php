<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Job\MessengerRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see MessengerRepository}. Symfony
 * Messenger's table is created on first dispatch via the Doctrine
 * transport; the fixture seeds it empty. F11-a wired the DI factory so
 * BatchManagerController could resolve this repository.
 */
final class MessengerRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private MessengerRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new MessengerRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    private function insertMessage(string $queue, string $body, ?string $deliveredAt = null): int
    {
        $this->conn->insert('piwigo_messenger_messages', [
            'body'         => $body,
            'headers'      => '{}',
            'queue_name'   => $queue,
            'created_at'   => '2026-05-18 10:00:00',
            'available_at' => '2026-05-18 10:00:00',
            'delivered_at' => $deliveredAt,
        ]);
        return (int) $this->conn->lastInsertId();
    }

    public function test_findFailedJobs_returns_only_failed_queue(): void
    {
        $this->insertMessage('piwigo_async', '{"async-payload":1}');
        $this->insertMessage('piwigo_failed', '{"failed-payload":2}');

        $jobs = $this->repo->findFailedJobs();

        self::assertCount(1, $jobs);
        self::assertSame('{"failed-payload":2}', $jobs[0]['body']);
    }

    public function test_findFailedJobById_scopes_to_failed_queue(): void
    {
        $asyncId  = $this->insertMessage('piwigo_async', '{"async":1}');
        $failedId = $this->insertMessage('piwigo_failed', '{"failed":2}');

        // Only the failed-queue id resolves.
        $row = $this->repo->findFailedJobById($failedId);
        self::assertNotNull($row);
        self::assertSame('{"failed":2}', $row['body']);

        // Async id is rejected — the method filters on queue_name.
        self::assertNull($this->repo->findFailedJobById($asyncId));
    }

    public function test_requeueFailed_moves_row_to_async_queue(): void
    {
        $id = $this->insertMessage('piwigo_failed', '{"body":3}');

        $this->repo->requeueFailed($id);

        $row = $this->conn->executeQuery(
            'SELECT queue_name, delivered_at FROM piwigo_messenger_messages WHERE id = ?',
            [$id]
        )->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('piwigo_async', $row['queue_name']);
        self::assertNull($row['delivered_at']);
    }

    public function test_purgeFailed_removes_all_failed_rows(): void
    {
        $this->insertMessage('piwigo_failed', '{"a":1}');
        $this->insertMessage('piwigo_failed', '{"b":2}');
        $this->insertMessage('piwigo_async', '{"keep-me":3}');

        $this->repo->purgeFailed();

        self::assertCount(0, $this->repo->findFailedJobs());
        $asyncCount = $this->conn->executeQuery(
            "SELECT COUNT(*) FROM piwigo_messenger_messages WHERE queue_name = 'piwigo_async'"
        )->fetchOne();
        self::assertSame(1, $asyncCount, 'async queue untouched');
    }

    public function test_countPendingByQueueName_skips_delivered(): void
    {
        $this->insertMessage('piwigo_async', '{"pending":1}');
        $this->insertMessage('piwigo_async', '{"pending":2}', deliveredAt: '2026-05-18 11:00:00');
        $this->insertMessage('piwigo_failed', '{"failed":3}');

        $counts = $this->repo->countPendingByQueueName();

        self::assertSame(1, $counts['piwigo_async'] ?? 0);
        self::assertSame(1, $counts['piwigo_failed'] ?? 0);
    }
}
