<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see CategoryRepository}. The fixture
 * seeds two albums: id 1 'Sample Album' (root) and id 2 'Nested Sub Album'
 * with id_uppercat=1.
 */
final class CategoryRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private CategoryRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new CategoryRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findAllIds_returns_seeded_categories(): void
    {
        self::assertSame([1, 2], $this->repo->findAllIds());
    }

    public function test_findByIds_returns_root_and_subalbum(): void
    {
        $rows = $this->repo->findByIds([1, 2]);

        self::assertCount(2, $rows);
        $byId = array_column($rows, null, 'id');
        self::assertSame('Sample Album', $byId[1]['name']);
        self::assertSame('Nested Sub Album', $byId[2]['name']);
        self::assertSame(1, $byId[2]['id_uppercat'], 'sub album references root');
    }

    public function test_findByIds_returns_empty_for_unknown_ids(): void
    {
        self::assertSame([], $this->repo->findByIds([9999]));
    }

    public function test_deleteByIds_cascades_through_image_category(): void
    {
        // Fixture: image_category has 5 photos linked. Photo 1 → cat 1,
        // photos 2-5 → cat 2.
        $beforeRoot = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_image_category WHERE category_id = 1'
        )->fetchOne();
        self::assertGreaterThan(0, $beforeRoot, 'fixture precondition: cat 1 has image_category rows');

        // Use deleteCategoriesAndPermalinksAtomically (the public delete entry)
        $this->repo->deleteCategoriesAndPermalinksAtomically([1]);

        $afterRoot = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_image_category WHERE category_id = 1'
        )->fetchOne();
        self::assertSame(0, $afterRoot, 'fk_image_category_category_id CASCADE must clear the junction');

        // fk_categories_id_uppercat is SET NULL: the sub-album survives
        // with id_uppercat blanked out.
        $sub = $this->repo->findByIds([2]);
        self::assertCount(1, $sub, 'sub-album survives root deletion (FK SET NULL)');
        self::assertNull($sub[0]['id_uppercat'], 'id_uppercat must be SET NULL after parent delete');
    }
}
