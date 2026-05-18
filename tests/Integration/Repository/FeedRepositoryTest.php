<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Feed\FeedRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see FeedRepository}. The fixture seeds
 * no user_feed rows; each test sets up its own.
 */
final class FeedRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private FeedRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new FeedRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insert_then_existsById_round_trips(): void
    {
        $this->repo->insert('feed-uuid-alpha', 1);

        self::assertTrue($this->repo->existsById('feed-uuid-alpha'));
        self::assertFalse($this->repo->existsById('feed-uuid-missing'));
    }

    public function test_findById_returns_user_id_and_null_last_check(): void
    {
        $this->repo->insert('feed-uuid-beta', 3);

        $row = $this->repo->findById('feed-uuid-beta');

        self::assertIsArray($row);
        self::assertSame(3, $row['user_id']);
        self::assertNull($row['last_check']);
    }

    public function test_findById_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findById('nonexistent-feed'));
    }

    public function test_updateLastCheck_updates_the_timestamp(): void
    {
        $this->repo->insert('feed-uuid-gamma', 1);

        $this->repo->updateLastCheck('feed-uuid-gamma', '2026-05-18 14:00:00');

        $row = $this->repo->findById('feed-uuid-gamma');
        self::assertIsArray($row);
        self::assertSame('2026-05-18 14:00:00', $row['last_check']);
    }
}
