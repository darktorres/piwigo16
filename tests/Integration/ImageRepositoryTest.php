<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Image\CategoryImagesCriteria;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageTextField;
use Piwigo\Image\ImageUniquenessColumn;
use Piwigo\Image\MissingDerivativesCriteria;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\ImageCategoryLink;
use Piwigo\Image\Projection\ImageFormat;
use Piwigo\Image\Projection\ImageLookupRow;
use Piwigo\Image\Projection\MissingDerivativeRow;
use Piwigo\Image\Projection\MostRecentCategoryInfo;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\SqlCondition;

final class ImageRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ImageRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(ImageEntity::class);
    }

    #[Override]
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

        $this->repo->incrementVisitCounter(ImageId::from(1));

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

        $this->repo->incrementVisitCounter(ImageId::from(1));

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
        $this->repo->updateCoi(ImageId::from(1), 'ABCD');

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
        $this->repo->updateCoi(ImageId::from(1), 'ABCD');

        $this->repo->updateCoi(ImageId::from(1), null);

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
        $image = $this->repo->findById(ImageId::from(1));

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(1, $image->id->value);
        self::assertSame('fixture-photo-1.jpg', $image->file);
        self::assertSame(200, $image->width);
        self::assertSame(150, $image->height);
    }

    public function test_find_by_id_returns_null_for_a_nonexistent_image(): void
    {
        self::assertNull($this->repo->findById(ImageId::from(999999)));
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
        self::assertSame(1, $image->id->value);
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
            self::assertSame($key, $image->id->value);
            $ids[] = $image->id->value;
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
        self::assertSame(1, $format->imageId->value);
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

        $formats = $this->repo->findFormatsForImage(ImageId::from(1));

        self::assertCount(2, $formats);
        $exts = array_column($formats, 'ext');
        sort($exts);
        self::assertSame(['avif', 'webp'], $exts);
    }

    public function test_find_formats_for_image_returns_empty_for_an_image_with_no_formats(): void
    {
        self::assertSame([], $this->repo->findFormatsForImage(ImageId::from(1)));
    }

    public function test_find_full_formats_by_image_ids_returns_every_matching_row(): void
    {
        $this->insertFormat(1, 'webp', 100);
        $this->insertFormat(2, 'avif', 200);

        $formats = $this->repo->findFullFormatsByImageIds([1, 2]);

        self::assertCount(2, $formats);
        $imageIds = array_map(static fn (ImageFormat $format): int => $format->imageId->value, $formats);
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
        $this->repo->updateCoi(ImageId::from(999_999), 'ABCD');

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
            $rank = $this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank');
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::imageCategory() . " (image_id, category_id, {$rank}) VALUES (3, 1, 3)"
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
            $this->repo->updateRotation(ImageId::from(1), 3);

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
        $this->repo->updateRotation(ImageId::from(999_999), 2);

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

        $this->repo->updateDescriptiveFields(ImageId::from(999_999), name: 'Should Not Apply');

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
            $this->repo->updateDescriptiveFields(ImageId::from(1), dateCreation: '2020-05-01 00:00:00');

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

    /**
     * The one confirmed-risky case the graceful `sql_datetime_graceful`
     * Doctrine Type ({@see \Piwigo\Db\Type\GracefulSqlDateTimeType})
     * exists for: a real, pre-existing MySQL zero-date
     * ('0000-00-00 00:00:00') sentinel in legacy EXIF/IPTC-synced data
     * (see {@see \Piwigo\Image\ImageEntity}'s own docblock). PostgreSQL
     * has no equivalent sentinel at all -- confirmed live,
     * `'0000-00-00 00:00:00'::timestamp` throws a real "date/time field
     * value out of range" error at the SQL level, so there is no raw row
     * to seed on that driver.
     */
    public function test_find_by_id_gracefully_returns_null_date_creation_for_a_legacy_mysql_zero_date_row(): void
    {
        if ($this->dbDriver === 'pgsql') {
            self::markTestSkipped("PostgreSQL rejects '0000-00-00 00:00:00' as an invalid timestamp outright -- this legacy sentinel can only ever exist in real MySQL data.");
        }

        try {
            $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_creation = '0000-00-00 00:00:00' WHERE id = 1");

            $image = $this->repo->findById(ImageId::from(1));

            self::assertNotNull($image);
            self::assertNull($image->dateCreation);
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

        $this->repo->updateTextFieldForImages([], ImageTextField::Name, 'Should Not Apply');

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

        $this->repo->updateFields(ImageId::from(1), []);

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

        $this->repo->deleteImageCategoryLinksForCategoryIds(ImageId::from(1), []);

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

    public function test_find_ids_added_same_day_as_latest_excludes_older_images(): void
    {
        // Every fixture image already shares the same date_available (the
        // "latest" day) -- add one genuinely older image to prove the
        // DATE_SUB()-based lower bound really excludes it, not just
        // "returns everything by accident."
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('older-photo.jpg', 'upload/older-photo.jpg', '2020-01-01 00:00:00')"
            );
            $olderId = (int) $this->conn->lastInsertId();

            $ids = $this->repo->findIdsAddedSameDayAsLatest();

            self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], $ids);
            self::assertNotContains($olderId, $ids);
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

    public function test_find_most_recent_image_category_info_returns_the_real_joined_row(): void
    {
        // This method uses a blind `instanceof CategoryId` check with a
        // silent `return null` fallback, so a wrong VO-hydration
        // assumption would be indistinguishable from "no image is
        // linked" without this test. Fixture: image 5 (the highest id
        // with an image_category link) is in category 2, whose
        // uppercats is '1,2'.
        self::assertEquals(
            new MostRecentCategoryInfo(2, '1,2'),
            $this->repo->findMostRecentImageCategoryInfo()
        );
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
        self::assertSame([1], $this->repo->findIdsByFilenameInCategory('fixture-photo-1.jpg', CategoryId::from(1)));
    }

    public function test_find_ids_by_filename_in_category_returns_empty_for_a_filename_not_in_that_category(): void
    {
        // Image 1 matches the filename but is only linked to category 1,
        // not category 2 (category 2 holds images 4 and 5).
        self::assertSame([], $this->repo->findIdsByFilenameInCategory('fixture-photo-1.jpg', CategoryId::from(2)));
    }

    public function test_find_upload_result_info_by_id_returns_null_for_a_nonexistent_image(): void
    {
        self::assertNull($this->repo->findUploadResultInfoById(ImageId::from(999_999)));
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
        self::assertSame([], $this->repo->findCategoryLinksForImageIdsWithCondition([], self::noPermissionRestriction()));
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

            $byMethod = array_column($breakdown, null, 'addMethod');
            self::assertArrayHasKey('sync', $byMethod);
            self::assertArrayHasKey('api', $byMethod);
            self::assertSame(1, $byMethod['sync']->nbFiles);
            self::assertSame(4, $byMethod['api']->nbFiles);
            // Every fixture image shares the same date_available, so both
            // groups' MAX(date_available) resolves to that same value --
            // proves the is_string($row['last_added_on'] ?? null) branch
            // maps a real, non-null value rather than only being exercised
            // by the null-coalescing default.
            self::assertSame('2026-08-01 00:00:00', $byMethod['sync']->lastAddedOn);
            self::assertSame('2026-08-01 00:00:00', $byMethod['api']->lastAddedOn);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET storage_category_id = NULL WHERE id = 1');
        }
    }

    /**
     * [Mutation] findAddMethodBreakdown() computes a running max in PHP
     * across unordered rows (no ORDER BY -- see its own docblock), so an
     * implementation that always overwrites `last_added_on` instead of
     * comparing, or compares the wrong way, must end up wrong here
     * regardless of which of these 3 rows PostgreSQL's unordered scan
     * happens to visit first or last: the group's true maximum date sits
     * strictly between the other two dates, so it can only be captured by
     * a real running-max comparison, never by "whichever row happened to
     * be first/last processed".
     */
    public function test_find_add_method_breakdown_tracks_the_true_maximum_and_returns_a_reindexed_list(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::images() . " (file, date_available) VALUES ('p18-earliest.jpg', '2010-01-01 00:00:00')"
            );
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::images() . " (file, date_available) VALUES ('p18-max.jpg', '2030-01-01 00:00:00')"
            );
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::images() . " (file, date_available) VALUES ('p18-middle.jpg', '2020-01-01 00:00:00')"
            );

            $breakdown = $this->repo->findAddMethodBreakdown();

            // findAddMethodBreakdown()'s own array_values() re-keying has
            // no external observer: its only consumer (PiwigoInfosSender)
            // reads it via foreach, which is indifferent to list vs.
            // string-keyed shape, and it's never JSON-encoded anywhere --
            // so this test only needs the by-add_method values below, not
            // a separate list-shape assertion.
            $byMethod = array_column($breakdown, null, 'addMethod');
            self::assertSame('2030-01-01 00:00:00', $byMethod['api']->lastAddedOn);
        } finally {
            $this->conn->rollBack();
        }
    }

    // Fixture shape: image_category links images 1/2/3 to category 1,
    // images 4/5 to category 2 (both categories
    // commentable=1/visible=1/status=public, per
    // tests/Fixtures/piwigo-17.0.sql); image 1's md5sum is
    // '2e7ee450c4a4cffe42945205029782b9'; every fixture image's `author`
    // is NULL.

    public function test_find_by_id_or_file_pattern_matches_by_id(): void
    {
        $row = $this->repo->findByIdOrFilePattern(1, null);

        self::assertInstanceOf(ImageLookupRow::class, $row);
        self::assertSame('fixture-photo-1.jpg', $row->file);
    }

    public function test_find_by_id_or_file_pattern_matches_by_file_pattern(): void
    {
        $row = $this->repo->findByIdOrFilePattern(0, 'fixture-photo-2');

        self::assertInstanceOf(ImageLookupRow::class, $row);
        self::assertSame(2, $row->id->value);
    }

    public function test_find_by_id_or_file_pattern_returns_false_for_no_match(): void
    {
        self::assertFalse($this->repo->findByIdOrFilePattern(999_999, null));
    }

    /**
     * [SEC-20] regression: a file-pattern value containing SQL syntax
     * (exactly the shape a crafted picture.php URL path segment could
     * produce, per Controller\PictureController's own "already escaped"
     * claim that only neutralized LIKE wildcards, not quotes) is now
     * always bound as a literal LIKE value -- it matches nothing rather
     * than tautologically matching every row.
     */
    public function test_find_by_id_or_file_pattern_treats_sql_syntax_as_a_literal_value(): void
    {
        self::assertFalse($this->repo->findByIdOrFilePattern(0, "fixture-photo-1' OR '1'='1"));
    }

    public function test_find_ids_by_md5sum_returns_the_matching_image(): void
    {
        self::assertSame([1], $this->repo->findIdsByMd5sum('2e7ee450c4a4cffe42945205029782b9'));
    }

    public function test_find_ids_by_md5sum_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findIdsByMd5sum('no-such-md5sum'));
    }

    /**
     * [Mutation] Every fixture image already has a real md5sum -- null one
     * out within a rolled-back transaction to reach the real, non-empty
     * branch. getSingleColumnResult() bypasses Doctrine's own Type
     * conversion, so the id comes back as a raw driver scalar that must be
     * explicitly cast to int, not already an int/ImageId.
     */
    public function test_find_image_ids_without_md5sum_returns_the_real_ids_as_ints(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET md5sum = NULL WHERE id = 2');

            self::assertSame([2], $this->repo->findImageIdsWithoutMd5sum());
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * [SEC-20] regression: findIdsByMd5sum()'s own real caller
     * (Ws\PwgImages::add()'s `original_sum` param) has zero WS-level type
     * constraints -- a value containing SQL syntax must be treated as a
     * literal, matching nothing, not injected as SQL structure.
     */
    public function test_find_ids_by_md5sum_treats_sql_syntax_as_a_literal_value(): void
    {
        self::assertSame([], $this->repo->findIdsByMd5sum("x' OR '1'='1"));
    }

    public function test_exists_with_column_value_is_true_for_a_matching_md5sum(): void
    {
        self::assertTrue($this->repo->existsWithColumnValue(ImageUniquenessColumn::Md5sum, '2e7ee450c4a4cffe42945205029782b9'));
    }

    public function test_exists_with_column_value_is_true_for_a_matching_file(): void
    {
        self::assertTrue($this->repo->existsWithColumnValue(ImageUniquenessColumn::File, 'fixture-photo-1.jpg'));
    }

    public function test_exists_with_column_value_is_false_for_no_match(): void
    {
        self::assertFalse($this->repo->existsWithColumnValue(ImageUniquenessColumn::Md5sum, 'no-such-md5sum'));
    }

    /**
     * [SEC-20] regression: existsWithColumnValue()'s own real caller
     * (Ws\PwgImages::add()'s unvalidated original_sum/original_filename
     * params) has zero WS-level type constraints -- a value containing SQL
     * syntax must be treated as a literal, matching nothing, not injected
     * as a tautology.
     */
    public function test_exists_with_column_value_treats_sql_syntax_as_a_literal_value(): void
    {
        self::assertFalse($this->repo->existsWithColumnValue(ImageUniquenessColumn::File, "fixture-photo-1.jpg' OR '1'='1"));
    }

    /**
     * A {@see PermissionCriteria} with every dimension null means no
     * restriction on any dimension.
     */
    private static function noPermissionRestriction(): PermissionCriteria
    {
        return new PermissionCriteria(null, null, null, null, null, null);
    }

    public function test_is_image_accessible_with_condition_is_true_with_no_restriction(): void
    {
        self::assertTrue($this->repo->isImageAccessibleWithCondition(ImageId::from(1), self::noPermissionRestriction()));
    }

    public function test_is_image_accessible_with_condition_applies_the_given_condition(): void
    {
        // fixture: image 1 belongs to category 1 -- excluding it via
        // forbiddenCategoryIds (checked against ic.category_id) excludes
        // image 1.
        self::assertFalse($this->repo->isImageAccessibleWithCondition(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_find_row_with_condition_returns_the_matching_row(): void
    {
        $row = $this->repo->findRowWithCondition(ImageId::from(1), self::noPermissionRestriction());

        self::assertNotNull($row);
        self::assertSame('fixture-photo-1.jpg', $row['file']);
    }

    public function test_find_row_with_condition_returns_null_when_the_condition_excludes_it(): void
    {
        self::assertNull($this->repo->findRowWithCondition(ImageId::from(1), new PermissionCriteria(null, null, [999_999], null, null, null)));
    }

    public function test_find_related_categories_for_image_returns_matching_rows(): void
    {
        $rows = $this->repo->findRelatedCategoriesForImage(ImageId::from(1), self::noPermissionRestriction());

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertTrue($rows[0]['commentable']);
    }

    public function test_find_related_categories_for_image_applies_the_given_condition(): void
    {
        self::assertSame([], $this->repo->findRelatedCategoriesForImage(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_is_image_commentable_with_condition_is_true_for_a_commentable_category(): void
    {
        self::assertTrue($this->repo->isImageCommentableWithCondition(ImageId::from(1), self::noPermissionRestriction()));
    }

    public function test_is_image_commentable_with_condition_applies_the_given_condition(): void
    {
        self::assertFalse($this->repo->isImageCommentableWithCondition(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_find_visible_categories_for_image_returns_matching_rows(): void
    {
        $rows = $this->repo->findVisibleCategoriesForImage(ImageId::from(1), self::noPermissionRestriction());

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
    }

    public function test_find_visible_categories_for_image_applies_the_given_condition(): void
    {
        self::assertSame([], $this->repo->findVisibleCategoriesForImage(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_has_accessible_image_with_author_is_false_when_no_image_has_an_author(): void
    {
        self::assertFalse($this->repo->hasAccessibleImageWithAuthor(self::noPermissionRestriction()));
    }

    public function test_has_accessible_image_with_author_is_true_once_one_image_has_an_author(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET author = 'fixture-author' WHERE id = 1");

        try {
            self::assertTrue($this->repo->hasAccessibleImageWithAuthor(self::noPermissionRestriction()));
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET author = NULL WHERE id = 1');
        }
    }

    public function test_is_image_accessible_via_category_with_condition_is_true_with_no_restriction(): void
    {
        self::assertTrue($this->repo->isImageAccessibleViaCategoryWithCondition(ImageId::from(1), self::noPermissionRestriction()));
    }

    public function test_is_image_accessible_via_category_with_condition_applies_the_given_condition(): void
    {
        self::assertFalse($this->repo->isImageAccessibleViaCategoryWithCondition(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_find_with_conditions_paginated_returns_matching_rows_and_total(): void
    {
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(), [1], new SqlCondition(''));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 10, 0);

        self::assertCount(3, $result->rows);
        self::assertSame(3, $result->total);
    }

    public function test_find_with_conditions_paginated_respects_the_limit(): void
    {
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(), [1], new SqlCondition(''));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 1, 0);

        self::assertCount(1, $result->rows);
        self::assertSame(3, $result->total);
    }

    public function test_find_with_conditions_paginated_applies_the_filter_condition(): void
    {
        // fixture: category 1's images are 1 (rating_score 4.50), 2
        // (3.00), 3 (5.00) -- minRate: 4.0 keeps only 1 and 3.
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(minRate: 4.0), [1], new SqlCondition(''));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 10, 0);

        self::assertCount(2, $result->rows);
        self::assertSame(2, $result->total);
    }

    public function test_find_with_conditions_paginated_applies_the_visible_images_condition(): void
    {
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(), [1], new SqlCondition('i.id = -1'));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 10, 0);

        self::assertSame([], $result->rows);
        self::assertSame(0, $result->total);
    }

    public function test_find_category_links_for_image_ids_with_condition_returns_matching_rows(): void
    {
        $rows = $this->repo->findCategoryLinksForImageIdsWithCondition([1, 2], self::noPermissionRestriction());

        $pairs = array_map(static fn (ImageCategoryLink $row): string => $row->imageId . ':' . $row->categoryId, $rows);
        sort($pairs);

        self::assertSame(['1:1', '2:1'], $pairs);
    }

    public function test_find_category_links_for_image_ids_with_condition_applies_the_given_condition(): void
    {
        self::assertSame([], $this->repo->findCategoryLinksForImageIdsWithCondition([1, 2], new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function test_find_for_missing_derivatives_matches_the_given_filter_condition(): void
    {
        // fixture ratings: 1=>4.50, 2=>3.00, 3=>5.00, 4=>2.00, 5=>NULL --
        // minRate: 4.6 keeps only image 3 (a NULL rating never satisfies a
        // >= comparison).
        $criteria = new MissingDerivativesCriteria(new ImageFilterCriteria(minRate: 4.6));
        $rows = $this->repo->findForMissingDerivatives($criteria, 999_999, 10);

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->id);
    }

    public function test_find_for_missing_derivatives_filters_by_ids(): void
    {
        $criteria = new MissingDerivativesCriteria(new ImageFilterCriteria(), [2, 3]);
        $rows = $this->repo->findForMissingDerivatives($criteria, 999_999, 10);

        $ids = array_map(static fn (MissingDerivativeRow $row): int => $row->id, $rows);
        sort($ids);
        self::assertSame([2, 3], $ids);
    }

    public function test_count_lounge_images_pending_for_category_counts_unlinked_lounge_rows(): void
    {
        // `lounge.image_id` FK-references `images.id`, and every fixture
        // image (1-5) already has an image_category link -- a disposable
        // image row (with none) is the only way to reach the "pending"
        // (not yet linked into image_category) branch this method counts.
        $this->conn->insert(Tables::images(), ['file' => 'p18-test-lounge-pending.jpg']);
        $imageId = (int) $this->conn->lastInsertId();
        $this->conn->insert(Tables::lounge(), ['image_id' => $imageId, 'category_id' => 1]);

        try {
            self::assertSame(1, $this->repo->countLoungeImagesPendingForCategory(CategoryId::from(1)));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
    }

    public function test_is_image_in_category_is_true_for_a_real_link(): void
    {
        self::assertTrue($this->repo->isImageInCategory(ImageId::from(1), CategoryId::from(1)));
    }

    public function test_is_image_in_category_is_false_for_no_link(): void
    {
        self::assertFalse($this->repo->isImageInCategory(ImageId::from(1), CategoryId::from(2)));
    }

    public function test_find_max_rank_for_category_returns_the_highest_rank(): void
    {
        self::assertSame(3, $this->repo->findMaxRankForCategory(CategoryId::from(1)));
    }

    public function test_find_max_rank_for_category_returns_null_for_no_ranked_images(): void
    {
        self::assertNull($this->repo->findMaxRankForCategory(CategoryId::from(999_999)));
    }

    public function test_increment_ranks_from_for_category_bumps_ranks_at_or_above_the_given_rank(): void
    {
        $rankIdentifier = $this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank');
        try {
            $this->repo->incrementRanksFromForCategory(CategoryId::from(1), 2);

            $ranks = $this->conn->createQueryBuilder()
                ->select('image_id', $rankIdentifier)
                ->from(Tables::imageCategory())
                ->where('category_id = 1')
                ->executeQuery()
                ->fetchAllKeyValue();
            self::assertSame(1, $ranks[1]);
            self::assertSame(3, $ranks[2]);
            self::assertSame(4, $ranks[3]);
        } finally {
            // Fixture's own image_category rows for category 1: image_id
            // and rank are numerically identical by construction (1/1,
            // 2/2, 3/3) -- restoring rank = image_id is exact, not an
            // approximation.
            $this->conn->executeStatement("UPDATE " . Tables::imageCategory() . " SET {$rankIdentifier} = image_id WHERE category_id = 1");
        }
    }

    public function test_update_rank_for_image_in_category_sets_the_rank(): void
    {
        $rankIdentifier = $this->conn->getDatabasePlatform()->quoteSingleIdentifier('rank');
        try {
            $this->repo->updateRankForImageInCategory(ImageId::from(1), CategoryId::from(1), 99);

            $rank = $this->conn->createQueryBuilder()
                ->select($rankIdentifier)
                ->from(Tables::imageCategory())
                ->where('image_id = 1')
                ->andWhere('category_id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(99, $rank);
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::imageCategory() . " SET {$rankIdentifier} = 1 WHERE image_id = 1 AND category_id = 1");
        }
    }

    public function test_count_images_in_category_counts_linked_images(): void
    {
        self::assertSame(3, $this->repo->countImagesInCategory(CategoryId::from(1)));
    }

    public function test_find_associated_category_ids_returns_the_real_categories(): void
    {
        self::assertSame([1], $this->repo->findAssociatedCategoryIds(ImageId::from(1)));
    }

    public function test_update_level_for_images_sets_the_level_and_returns_the_affected_count(): void
    {
        try {
            $affected = $this->repo->updateLevelForImages([1, 2], 5);

            self::assertSame(2, $affected);
            self::assertSame(
                5,
                $this->conn->createQueryBuilder()->select('level')->from(Tables::images())->where('id = 1')->executeQuery()->fetchOne()
            );
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET level = 0 WHERE id IN (1, 2)');
        }
    }

    public function test_find_paths_for_file_deletion_returns_the_matching_rows(): void
    {
        $rows = $this->repo->findPathsForFileDeletion([1, 2]);

        $ids = array_column($rows, 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function test_find_next_id_returns_one_more_than_the_current_max(): void
    {
        // findNextId() returns COALESCE(MAX(id)+1, 1), verified here
        // against the real, non-empty fixture table -- the empty-table
        // branch isn't practically testable against this shared fixture
        // DB.
        $maxId = $this->conn->fetchOne('SELECT MAX(id) FROM ' . Tables::images());

        self::assertSame((is_numeric($maxId) ? (int) $maxId : 0) + 1, $this->repo->findNextId());
    }

    public function test_find_ids_not_in_categories_excludes_linked_images(): void
    {
        // The non-empty branch's DQL NOT IN (subquery) is built via a
        // separate QueryBuilder's own getDQL() string interpolated into
        // the outer query. Fixture: images 1-3 are in category 1, images
        // 4-5 are in category 2.
        $ids = $this->repo->findIdsNotInCategories([1]);
        sort($ids);
        self::assertSame([4, 5], $ids);
    }

    public function test_find_ids_not_in_categories_returns_every_image_for_no_categories(): void
    {
        $ids = $this->repo->findIdsNotInCategories([]);
        sort($ids);
        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_find_ids_not_in_categories_returns_empty_when_every_image_is_linked(): void
    {
        self::assertSame([], $this->repo->findIdsNotInCategories([1, 2]));
    }

    public function test_find_ids_in_categories_returns_linked_images(): void
    {
        $ids = $this->repo->findIdsInCategories([2]);
        sort($ids);
        self::assertSame([4, 5], $ids);
    }

    public function test_find_ids_in_categories_returns_empty_for_no_categories(): void
    {
        self::assertSame([], $this->repo->findIdsInCategories([]));
    }

    public function test_find_existing_associations_returns_real_values_not_an_empty_array(): void
    {
        // This method uses a *blind* `instanceof CategoryId` check (no
        // raw-int fallback, unlike sibling methods in this file) -- if
        // the VO-hydration assumption is wrong, every row is silently
        // skipped and this returns [] instead of throwing, so asserting
        // the real non-empty shape is the only way to catch that.
        $existing = $this->repo->findExistingAssociations([1, 2, 4], [1, 2]);

        self::assertArrayHasKey(1, $existing);
        self::assertArrayHasKey(2, $existing);
        $sortedCat1 = $existing[1];
        sort($sortedCat1);
        self::assertSame([1, 2], $sortedCat1);
        self::assertSame([4], $existing[2]);
    }

    public function test_find_max_ranks_by_category_returns_real_values_not_an_empty_array(): void
    {
        self::assertSame(
            ['1' => 3, '2' => 2],
            $this->repo->findMaxRanksByCategory([1, 2])
        );
    }

    /**
     * [Mutation] getSingleColumnResult() bypasses Doctrine's own custom-
     * Type conversion (it fetches the raw driver column directly), so
     * `c.id` comes back as a raw scalar that must be explicitly mapped to
     * int here, not already an int/ImageId.
     */
    public function test_find_represented_category_ids_returns_the_real_category(): void
    {
        // Fixture: category 1's representative_picture_id is image 1;
        // category 2's is image 4.
        self::assertSame([1], $this->repo->findRepresentedCategoryIds([1]));
        self::assertSame([2], $this->repo->findRepresentedCategoryIds([4]));
    }

    /**
     * [Mutation] 'not-a-number' must be defensively mapped to 0 before
     * binding as an ArrayParameterType::INTEGER parameter --
     * representative_picture_id is a foreign key into images.id (always a
     * real, positive id), so 0 can never match a real category, but 1
     * legitimately does (this fixture's own category 1) -- an unguarded
     * pass-through of the raw value would error against the database, and
     * a wrong default could spuriously match a real category.
     */
    public function test_find_represented_category_ids_treats_non_numeric_ids_as_a_harmless_zero(): void
    {
        self::assertSame([], $this->repo->findRepresentedCategoryIds(['not-a-number']));
    }

    public function test_find_virtually_associated_category_rows_returns_real_categories(): void
    {
        // Every fixture image has storage_category_id NULL, so the "OR
        // i.storageCategoryId IS NULL" branch always applies -- image 1's
        // real category_id membership (category 1) must come back, not [].
        $rows = $this->repo->findVirtuallyAssociatedCategoryRows([1]);

        self::assertSame([['id' => 1]], $rows);
    }

    public function test_find_category_links_for_image_returns_the_real_category(): void
    {
        $rows = $this->repo->findCategoryLinksForImage(ImageId::from(1));

        self::assertSame([[
            'category_id' => 1,
            'uppercats' => '1',
            'dir' => null,
        ]], $rows);
    }

    public function test_find_lounge_rows_returns_the_real_rows(): void
    {
        // No fixture lounge data exists, so this inserts its own within
        // a rolled-back transaction.
        $this->conn->beginTransaction();

        try {
            $this->conn->insert(Tables::lounge(), ['image_id' => 1, 'category_id' => 2]);

            self::assertEquals(
                [new ImageCategoryLink(1, 2)],
                $this->repo->findLoungeRows()
            );
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_delete_lounge_up_to_removes_matching_rows_only(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->insert(Tables::lounge(), ['image_id' => 1, 'category_id' => 2]);
            $this->conn->insert(Tables::lounge(), ['image_id' => 3, 'category_id' => 2]);

            $this->repo->deleteLoungeUpTo(1);

            self::assertSame([3], $this->repo->findLoungedImageIds());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_find_lounged_image_ids_returns_the_real_ids(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->insert(Tables::lounge(), ['image_id' => 4, 'category_id' => 1]);

            self::assertSame([4], $this->repo->findLoungedImageIds());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_find_dissociable_image_ids_returns_images_not_stored_under_the_category(): void
    {
        // Fixture: images 1-3 are in category 1, none has a
        // storage_category_id set, so all 3 are dissociable from it.
        $ids = $this->repo->findDissociableImageIds([1, 2, 3], 1);
        sort($ids);
        self::assertSame([1, 2, 3], $ids);
    }

    public function test_delete_image_category_links_removes_only_the_given_images_and_category(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->repo->deleteImageCategoryLinks([1], 1);

            self::assertFalse($this->repo->isImageInCategory(ImageId::from(1), CategoryId::from(1)));
            self::assertTrue($this->repo->isImageInCategory(ImageId::from(2), CategoryId::from(1)));
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_count_images_in_categories_counts_distinct_images(): void
    {
        // Fixture: 5 distinct images across image_category.
        self::assertSame(5, $this->repo->countImagesInCategories());
    }

    public function test_count_image_category_links_counts_every_row(): void
    {
        // Fixture has 5 image_category rows, all distinct images -- same
        // figure as countImagesInCategories() here, but a genuinely
        // different query (no DISTINCT).
        self::assertSame(5, $this->repo->countImageCategoryLinks());
    }

    /**
     * [Mutation] A bare `COUNT(i.id)` DQL aggregate isn't a Doctrine
     * TypedExpression, so the ORM can't infer its real numeric type and
     * hydrates it through the generic scalar 'string' type instead --
     * getSingleScalarResult() returns a raw string here, not an int. This
     * method's own `(int)` cast is what actually produces the declared
     * `int` return type; without it, strict_types=1 would throw a
     * TypeError on this exact call rather than silently pass.
     */
    public function test_count_all_images_returns_the_real_total(): void
    {
        self::assertSame(5, $this->repo->countAllImages());
    }

    /**
     * [Mutation] This method stays on raw DBAL (`Connection::
     * fetchFirstColumn()`), which never applies Doctrine's own Type
     * conversion, so the ids come back as raw driver scalars that must be
     * explicitly mapped to int.
     */
    public function test_find_ids_visible_in_categories_recently_available_returns_the_real_ids_as_ints(): void
    {
        // Fixture: images 1-3 are in category 1, all dated 2026-08-01 --
        // a 10-year lookback comfortably covers that against Env::now()'s
        // frozen test clock.
        $recentPeriodExpr = SqlDialect::getRecentPeriodExpression(3650);

        $ids = $this->repo->findIdsVisibleInCategoriesRecentlyAvailable('1', $recentPeriodExpr);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_thumbnail_rows_for_category_ordered_by_rank_returns_real_rows_in_rank_order(): void
    {
        // Fixture: category 1 has images 1,2,3 at ranks 1,2,3.
        $rows = $this->repo->findThumbnailRowsForCategoryOrderedByRank(CategoryId::from(1));

        self::assertSame([1, 2, 3], array_column($rows, 'id'));
    }

    public function test_find_image_ids_ordered_by_rank_for_category_returns_real_ids_in_rank_order(): void
    {
        self::assertSame([1, 2, 3], $this->repo->findImageIdsOrderedByRankForCategory(CategoryId::from(1)));
    }

    public function test_find_category_ids_for_image_returns_the_real_categories(): void
    {
        self::assertSame([1], $this->repo->findCategoryIdsForImage(ImageId::from(1)));
    }

    public function test_find_orphan_image_category_link_ids_returns_empty_when_every_link_has_a_real_image(): void
    {
        self::assertSame([], $this->repo->findOrphanImageCategoryLinkIds());
    }

    public function test_find_orphan_image_category_link_ids_returns_links_with_no_real_image(): void
    {
        // image_category.image_id FK-references images.id ON DELETE
        // CASCADE, so a genuine orphan can only exist the way it would in
        // real life -- imported/fixed-up data that bypassed the FK, here
        // simulated by disabling FK checks for one insert.
        $this->conn->beginTransaction();

        try {
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->insert(Tables::imageCategory(), ['image_id' => 999999, 'category_id' => 1]);
            $this->enableForeignKeyChecks($this->conn);

            self::assertSame([999999], $this->repo->findOrphanImageCategoryLinkIds());
        } finally {
            $this->conn->rollBack();
        }
    }
}
