<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Tag\TagRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see TagRepository}. The fixture
 * (dev/fixtures/piwigo-17.0.sql) seeds three tags: id 1 'nature',
 * id 2 'travel', id 3 'family'. Tests assume that base state.
 */
final class TagRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private TagRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new TagRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findById_returns_existing_tag(): void
    {
        $tag = $this->repo->findById(1);

        self::assertNotNull($tag);
        self::assertSame('nature', $tag->name);
        self::assertSame('nature', $tag->urlName);
    }

    public function test_findById_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findById(9999));
    }

    public function test_countByExactName_finds_seeded_tag(): void
    {
        self::assertSame(1, $this->repo->countByExactName('travel'));
        self::assertSame(0, $this->repo->countByExactName('nonexistent_tag'));
    }

    public function test_insertNewTag_round_trips_via_findById(): void
    {
        $newId = $this->repo->insertNewTag(['name' => 'fresh', 'url_name' => 'fresh']);

        self::assertGreaterThan(3, $newId, 'auto-increment must start past the 3 seeded tags');
        $tag = $this->repo->findById($newId);
        self::assertNotNull($tag);
        self::assertSame('fresh', $tag->name);
    }

    public function test_deleteByIds_cascades_through_image_tag(): void
    {
        // Fixture attaches tag 1 ('nature') to images 1, 2, 3 — 3 junction rows.
        $before = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_image_tag WHERE tag_id = 1'
        )->fetchOne();
        self::assertSame(3, $before, 'fixture precondition: 3 image_tag rows for tag 1');

        $this->repo->deleteByIds([1]);

        $after = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_image_tag WHERE tag_id = 1'
        )->fetchOne();
        self::assertSame(0, $after, 'fk_image_tag_tag_id ON DELETE CASCADE must remove the junction rows');
    }
}
