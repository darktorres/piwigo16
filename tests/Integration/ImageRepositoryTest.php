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

    public function test_update_descriptive_fields_is_a_noop_for_a_nonexistent_image(): void
    {
        $nameBefore = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateDescriptiveFields(999_999, name: 'Should Not Apply');

        $nameAfter = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($nameBefore, $nameAfter);
    }

    public function test_update_descriptive_fields_sets_date_creation_when_supplied(): void
    {
        try {
            $this->repo->updateDescriptiveFields(1, dateCreation: '2020-05-01 00:00:00');

            $dateCreation = $this->conn->createQueryBuilder()
                ->select('date_creation')
                ->from(Tables::images())
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();

            self::assertSame('2020-05-01 00:00:00', $dateCreation);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET date_creation = NULL WHERE id = 1');
        }
    }

    public function test_update_format_filesize_updates_an_existing_format(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 100);

        $this->repo->updateFormatFilesize($formatId, 999);

        $filesize = $this->conn->createQueryBuilder()
            ->select('filesize')
            ->from(Tables::imageFormat())
            ->where('format_id = ' . $formatId)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(999, is_numeric($filesize) ? (int) $filesize : null);
    }

    public function test_update_format_filesize_is_a_noop_for_a_nonexistent_format(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 100);

        $this->repo->updateFormatFilesize(999_999, 555);

        $filesize = $this->conn->createQueryBuilder()
            ->select('filesize')
            ->from(Tables::imageFormat())
            ->where('format_id = ' . $formatId)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(100, is_numeric($filesize) ? (int) $filesize : null);
    }

    public function test_mass_insert_formats_is_a_noop_for_empty_inserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageFormat());

        $this->repo->massInsertFormats([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageFormat());
        self::assertSame($before, $after);
    }

    public function test_find_image_ids_and_exts_by_format_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findImageIdsAndExtsByFormatIds([]));
    }

    public function test_delete_formats_by_ids_is_a_noop_for_empty_ids(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 100);

        $this->repo->deleteFormatsByIds([]);

        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageFormat() . ' WHERE format_id = ' . $formatId);
        self::assertSame(1, is_numeric($count) ? (int) $count : null);
    }

    public function test_find_paths_and_level_for_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findPathsAndLevelForIds([]));
    }

    public function test_update_text_field_for_images_is_a_noop_for_empty_ids(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateTextFieldForImages([], 'name', 'Should Not Apply');

        $after = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_mass_update_fields_is_a_noop_for_empty_datas(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->massUpdateFields([
            'primary' => ['id'],
            'update' => ['name'],
        ], []);

        $after = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_update_fields_is_a_noop_for_empty_updates(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateFields(1, []);

        $after = $this->conn->createQueryBuilder()
            ->select('name')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_mass_insert_images_is_a_noop_for_empty_inserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::images());

        $this->repo->massInsertImages([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($before, $after);
    }

    public function test_find_oldest_lounge_age_info_returns_null_when_the_oldest_lounged_images_date_available_is_not_scalar(): void
    {
        // date_available is nullable on `images` -- a lounge row pointing at
        // an image whose date_available is genuinely NULL must trip the
        // `! is_scalar($dateAvailable)` guard rather than returning a
        // non-string value. The fixture's own lounge table starts empty, so
        // this single row is guaranteed to be the "oldest" (ORDER BY
        // image_id ASC LIMIT 1) one found.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('lounge-null-date.jpg', 'upload/lounge-null-date.jpg', NULL)"
        );
        $imageId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (?, ?)',
            [$imageId, 1]
        );

        try {
            self::assertNull($this->repo->findOldestLoungeAgeInfo());
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::lounge() . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
    }

    public function test_delete_image_category_links_for_category_ids_is_a_noop_for_empty_category_ids(): void
    {
        // Fixture: image 1 is linked to category 1 (image_category rows
        // (1,1),(2,1),(3,1),(4,2),(5,2)).
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageCategory() . ' WHERE image_id = 1');

        $this->repo->deleteImageCategoryLinksForCategoryIds(1, []);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageCategory() . ' WHERE image_id = 1');
        self::assertSame($before, $after);
    }

    public function test_find_earliest_date_available_returns_null_when_no_images_exist(): void
    {
        // Same beginTransaction()/rollBack() convention as
        // FilterResolverTest's own "images table is empty" tests --
        // guarantees the wipe never survives past this test regardless of
        // how the assertion resolves.
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images());

            self::assertNull($this->repo->findEarliestDateAvailable());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_find_history_display_info_by_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findHistoryDisplayInfoByIds([]));
    }

    public function test_find_most_recent_image_category_info_returns_null_when_no_image_is_linked_to_a_category(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory());

            self::assertNull($this->repo->findMostRecentImageCategoryInfo());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_delete_image_category_rows_for_image_ids_is_a_noop_for_empty_ids(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageCategory());

        $this->repo->deleteImageCategoryRowsForImageIds([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::imageCategory());
        self::assertSame($before, $after);
    }

    public function test_find_ids_by_filename_in_category_returns_matching_image_ids(): void
    {
        // Fixture: image 1 is 'fixture-photo-1.jpg', linked to category 1.
        self::assertSame([1], $this->repo->findIdsByFilenameInCategory('fixture-photo-1.jpg', 1));
    }

    public function test_find_ids_by_filename_in_category_returns_empty_for_a_filename_not_in_that_category(): void
    {
        // Image 1 matches the filename but is only linked to category 1,
        // not category 2 (category 2 holds images 4 and 5).
        self::assertSame([], $this->repo->findIdsByFilenameInCategory('fixture-photo-1.jpg', 2));
    }

    public function test_find_upload_result_info_by_id_returns_null_for_a_nonexistent_image(): void
    {
        self::assertNull($this->repo->findUploadResultInfoById(999_999));
    }

    public function test_find_ids_by_md5sums_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findIdsByMd5sums([]));
    }

    public function test_find_ids_by_filenames_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findIdsByFilenames([]));
    }

    public function test_find_existing_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findExistingIds([]));
    }

    public function test_find_category_links_for_image_ids_with_condition_returns_empty_array_for_empty_image_ids(): void
    {
        self::assertSame([], $this->repo->findCategoryLinksForImageIdsWithCondition([], '1=1'));
    }

    public function test_find_ids_and_paths_by_storage_category_ids_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findIdsAndPathsByStorageCategoryIds([]));
    }

    public function test_find_add_method_breakdown_groups_by_storage_category_presence(): void
    {
        // Every fixture image has storage_category_id NULL (added via the
        // API, never a filesystem sync) -- temporarily give image 1 a real
        // storage_category_id so both the 'sync' and 'api' IF() branches,
        // and both array_map() mapping passes, actually run against real
        // grouped data.
        try {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = 1 WHERE id = 1');

            $breakdown = $this->repo->findAddMethodBreakdown();

            $byMethod = array_column($breakdown, null, 'add_method');
            self::assertArrayHasKey('sync', $byMethod);
            self::assertArrayHasKey('api', $byMethod);
            self::assertSame(1, $byMethod['sync']['nb_files']);
            self::assertSame(4, $byMethod['api']['nb_files']);
            // Every fixture image shares the same date_available, so both
            // groups' MAX(date_available) resolves to that same value --
            // proves the is_string($row['last_added_on'] ?? null) branch
            // maps a real, non-null value rather than only being exercised
            // by the null-coalescing default.
            self::assertSame('2026-08-01 00:00:00', $byMethod['sync']['last_added_on']);
            self::assertSame('2026-08-01 00:00:00', $byMethod['api']['last_added_on']);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = NULL WHERE id = 1');
        }
    }
}
