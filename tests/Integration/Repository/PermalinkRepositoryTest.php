<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see PermalinkRepository}. Permalinks
 * live on piwigo_categories.permalink (current) and piwigo_old_permalinks
 * (history of past permalinks). Fixture seeds no permalinks.
 */
final class PermalinkRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private PermalinkRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new PermalinkRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_setCategoryPermalink_then_findCategoryIdByPermalink(): void
    {
        $this->repo->setCategoryPermalink(1, 'sample-album');

        self::assertSame(1, $this->repo->findCategoryIdByPermalink('sample-album'));
        self::assertSame('sample-album', $this->repo->findPermalinkByCategoryId(1));
    }

    public function test_findCategoryIdByPermalink_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findCategoryIdByPermalink('not-a-real-permalink'));
    }

    public function test_findPermalinkByCategoryId_returns_null_when_unset(): void
    {
        // Fixture seeds categories with permalink = NULL.
        self::assertNull($this->repo->findPermalinkByCategoryId(2));
    }

    public function test_insertOldPermalinkDeleted_then_findOldCategoryId(): void
    {
        $this->repo->insertOldPermalinkDeleted('old-sample', 1);

        self::assertSame(1, $this->repo->findOldCategoryId('old-sample'));
    }

    public function test_deleteOldPermalinkByValue_returns_true_when_row_existed(): void
    {
        $this->repo->insertOldPermalinkDeleted('was-here', 1);

        self::assertTrue($this->repo->deleteOldPermalinkByValue('was-here'));
        self::assertNull($this->repo->findOldCategoryId('was-here'));
    }

    public function test_deleteOldPermalinkByValue_returns_false_for_missing(): void
    {
        self::assertFalse($this->repo->deleteOldPermalinkByValue('never-existed'));
    }
}
