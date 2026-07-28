<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\Projection\Image;

final class ImageRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ImageRepository $repo;

    private Connection $conn;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Image\ImageEntity::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET hit = 0, coi = NULL WHERE id IN (1, 2)");
        $this->conn->executeStatement('DELETE FROM ' . Tables::imageFormat());
        parent::tearDown();
    }

    public function test_increment_visit_counter_increments_hit(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        $before = is_numeric($before) ? (int) $before : 0;

        $this->repo->incrementVisitCounter(1);

        $after = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        $after = is_numeric($after) ? (int) $after : 0;

        self::assertSame($before + 1, $after);
    }

    public function test_increment_visit_counter_does_not_change_other_rows(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        $before = is_numeric($before) ? (int) $before : 0;

        $this->repo->incrementVisitCounter(1);

        $after = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        $after = is_numeric($after) ? (int) $after : 0;

        self::assertSame($before, $after);
    }

    public function test_update_coi_sets_the_column(): void
    {
        $this->repo->updateCoi(1, 'ABCD');

        $coi = $this->conn->createQueryBuilder()
            ->select('coi')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame('ABCD', $coi);
    }

    public function test_update_coi_with_null_clears_the_column(): void
    {
        $this->repo->updateCoi(1, 'ABCD');

        $this->repo->updateCoi(1, null);

        $coi = $this->conn->createQueryBuilder()
            ->select('coi')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertNull($coi);
    }

    public function test_find_by_id_returns_a_typed_image_projection(): void
    {
        $image = $this->repo->findById(1);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(1, $image->id);
        self::assertSame('fixture-photo-1.jpg', $image->file);
        self::assertSame(200, $image->width);
        self::assertSame(150, $image->height);
    }

    public function test_find_by_id_returns_null_for_a_nonexistent_image(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function test_find_by_path_returns_the_matching_image(): void
    {
        $path = $this->conn->createQueryBuilder()
            ->select('path')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        self::assertIsString($path);

        $image = $this->repo->findByPath($path);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(1, $image->id);
        self::assertSame($path, $image->path);
    }

    public function test_find_by_path_returns_null_for_an_unknown_path(): void
    {
        self::assertNull($this->repo->findByPath('upload/does/not/exist.jpg'));
    }

    public function test_find_by_ids_returns_typed_images_keyed_by_id(): void
    {
        $images = $this->repo->findByIds(['1', '2']);

        self::assertCount(2, $images);

        $ids = [];
        foreach ($images as $key => $image) {
            // PHP canonicalises a numeric-string array key ('1') back to an
            // int key (1) -- the key is always int, never the original string.
            self::assertSame($key, $image->id);
            $ids[] = $image->id;
        }
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function test_find_by_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findByIds([]));
    }

    public function test_find_format_by_id_returns_a_typed_image_format(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 12345);

        $format = $this->repo->findFormatById($formatId);

        self::assertNotNull($format);
        self::assertSame($formatId, $format->formatId);
        self::assertSame(1, $format->imageId);
        self::assertSame('webp', $format->ext);
        self::assertSame(12345, $format->filesize);
    }

    public function test_find_format_by_id_returns_null_for_a_missing_format(): void
    {
        self::assertNull($this->repo->findFormatById(999_999));
    }

    public function test_find_formats_for_image_returns_every_format_for_that_image(): void
    {
        $this->insertFormat(1, 'webp', 100);
        $this->insertFormat(1, 'avif', 200);
        $this->insertFormat(2, 'webp', 300);

        $formats = $this->repo->findFormatsForImage(1);

        self::assertCount(2, $formats);
        $exts = array_column($formats, 'ext');
        sort($exts);
        self::assertSame(['avif', 'webp'], $exts);
    }

    public function test_find_formats_for_image_returns_empty_for_an_image_with_no_formats(): void
    {
        self::assertSame([], $this->repo->findFormatsForImage(1));
    }

    public function test_find_full_formats_by_image_ids_returns_every_matching_row(): void
    {
        $this->insertFormat(1, 'webp', 100);
        $this->insertFormat(2, 'avif', 200);

        $formats = $this->repo->findFullFormatsByImageIds([1, 2]);

        self::assertCount(2, $formats);
        $imageIds = array_column($formats, 'imageId');
        sort($imageIds);
        self::assertSame([1, 2], $imageIds);
    }

    public function test_find_full_formats_by_image_ids_returns_empty_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findFullFormatsByImageIds([]));
    }

    private function insertFormat(int $imageId, string $ext, int $filesize): int
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::imageFormat())
            ->values([
                'image_id' => ':imageId',
                'ext' => ':ext',
                'filesize' => ':filesize',
            ])
            ->setParameter('imageId', $imageId)
            ->setParameter('ext', $ext)
            ->setParameter('filesize', $filesize)
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    public function test_try_acquire_lounge_lock_then_find_lounge_lock_value_round_trips(): void
    {
        try {
            $this->repo->tryAcquireLoungeLock('exec123-1700000000');

            self::assertSame('exec123-1700000000', $this->repo->findLoungeLockValue());
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'empty_lounge_running'");
        }
    }

    public function test_try_acquire_lounge_lock_is_a_noop_once_held(): void
    {
        try {
            $this->repo->tryAcquireLoungeLock('exec123-1700000000');
            $this->repo->tryAcquireLoungeLock('exec456-1700000001');

            self::assertSame('exec123-1700000000', $this->repo->findLoungeLockValue());
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'empty_lounge_running'");
        }
    }

    public function test_find_lounge_lock_value_returns_null_when_no_lock_held(): void
    {
        self::assertNull($this->repo->findLoungeLockValue());
    }

    public function test_update_coi_is_a_noop_for_a_nonexistent_image(): void
    {
        $this->repo->updateCoi(999_999, 'ABCD');

        $coi = $this->conn->createQueryBuilder()
            ->select('coi')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertNull($coi);
    }

    public function test_find_formats_by_image_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findFormatsByImageIds([]));
    }

    public function test_delete_images_is_a_noop_for_empty_ids(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::images());

        $this->repo->deleteImages([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($before, $after);
    }

    public function test_find_orphan_image_ids_excludes_ids_present_in_lounged_ids(): void
    {
        // Every fixture image already belongs to exactly one album -- break
        // image 3's own link so it's a real orphan for this test, then
        // restore the exact original row.
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = 3 AND category_id = 1'
        );

        try {
            $withoutLoungedIds = $this->repo->findOrphanImageIds([]);
            self::assertContains(3, $withoutLoungedIds);

            $withLoungedIds = $this->repo->findOrphanImageIds([3]);
            self::assertNotContains(3, $withLoungedIds);
        } finally {
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id, `rank`) VALUES (3, 1, 3)'
            );
        }
    }

    public function test_touch_lastmodified_is_a_noop_for_empty_ids(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('lastmodified')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->touchLastmodified([]);

        $after = $this->conn->createQueryBuilder()
            ->select('lastmodified')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_update_rotation_sets_the_column_for_an_existing_image(): void
    {
        try {
            $this->repo->updateRotation(1, 3);

            $rotation = $this->conn->createQueryBuilder()
                ->select('rotation')
                ->from(Tables::images())
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();

            self::assertSame(3, is_numeric($rotation) ? (int) $rotation : null);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET rotation = 0 WHERE id = 1');
        }
    }

    public function test_update_rotation_is_a_noop_for_a_nonexistent_image(): void
    {
        $this->repo->updateRotation(999_999, 2);

        $rotation = $this->conn->createQueryBuilder()
            ->select('rotation')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, is_numeric($rotation) ? (int) $rotation : null);
    }

    public function test_mass_insert_lounge_is_a_noop_for_empty_inserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::lounge());

        $this->repo->massInsertLounge([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::lounge());
        self::assertSame($before, $after);
    }

    public function test_mass_insert_image_category_is_a_noop_for_empty_inserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageCategory());

        $this->repo->massInsertImageCategory([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageCategory());
        self::assertSame($before, $after);
    }

    public function test_update_dimensions_sets_width_and_height_for_an_existing_image(): void
    {
        try {
            $this->repo->updateDimensions(1, 999, 888);

            $row = $this->conn->createQueryBuilder()
                ->select('width', 'height')
                ->from(Tables::images())
                ->where('id = 1')
                ->executeQuery()
                ->fetchAssociative();

            self::assertIsArray($row);
            self::assertSame(999, is_numeric($row['width']) ? (int) $row['width'] : null);
            self::assertSame(888, is_numeric($row['height']) ? (int) $row['height'] : null);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET width = 200, height = 150 WHERE id = 1');
        }
    }

    public function test_update_dimensions_is_a_noop_for_a_nonexistent_image(): void
    {
        $this->repo->updateDimensions(999_999, 111, 222);

        $row = $this->conn->createQueryBuilder()
            ->select('width', 'height')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(200, is_numeric($row['width']) ? (int) $row['width'] : null);
        self::assertSame(150, is_numeric($row['height']) ? (int) $row['height'] : null);
    }
}
