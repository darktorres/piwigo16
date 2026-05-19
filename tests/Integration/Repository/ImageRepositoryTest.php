<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Image\ImageRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see ImageRepository}. The fixture seeds
 * 5 photos (ids 1–5); photo 1 lives in album 1, photos 2-5 live in album 2.
 *
 * Also exercises the FULLTEXT(name, comment) index added in F11-b — a
 * regression guard against the "Can't find FULLTEXT index matching the
 * column list" failure that surfaced in production qsearch.
 */
final class ImageRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private ImageRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new ImageRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findById_returns_existing_image(): void
    {
        $image = $this->repo->findById(1);

        self::assertNotNull($image);
        self::assertSame(1, $image->id->value);
        self::assertSame('fixture-photo-1.jpg', $image->file->value);
        self::assertSame('Photo 1', $image->name);
    }

    public function test_findById_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findById(9999));
    }

    public function test_findByIds_returns_subset(): void
    {
        $images = $this->repo->findByIds([1, 3, 5]);

        self::assertCount(3, $images);
        $ids = array_map(static fn ($img) => $img->id->value, $images);
        sort($ids);
        self::assertSame([1, 3, 5], $ids);
    }

    public function test_findIdsByFileLike_uses_LIKE_pattern(): void
    {
        $ids = $this->repo->findIdsByFileLike('fixture-photo-%.jpg');
        sort($ids);
        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    /**
     * F11-b regression guard: the FULLTEXT(name, comment) index must exist
     * on piwigo_images so qsearch MATCH() queries don't error with
     * "Can't find FULLTEXT index matching the column list".
     */
    public function test_fulltext_index_on_name_comment_is_present(): void
    {
        // Direct DDL probe — the row should report FULLTEXT for images_ft_name_comment.
        $row = $this->conn->executeQuery(
            "SHOW INDEX FROM piwigo_images WHERE Key_name = 'images_ft_name_comment'"
        )->fetchAssociative();

        self::assertIsArray($row, 'images_ft_name_comment index must exist (F11-b)');
        self::assertSame('FULLTEXT', $row['Index_type']);

        // The MATCH query itself must execute without error. Result may be
        // empty for synthetic fixture content; what matters is that the
        // engine accepts the clause (no SQL exception thrown).
        $this->conn->executeQuery(
            "SELECT id FROM piwigo_images WHERE MATCH(name, comment) AGAINST('photo' IN BOOLEAN MODE)"
        )->fetchAllAssociative();
    }
}
