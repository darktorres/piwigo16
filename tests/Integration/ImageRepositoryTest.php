<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SortRenderer;
use Piwigo\Db\SqlDialect;
use Piwigo\Image\CategoryImagesCriteria;
use Piwigo\Image\ImageDuplicateField;
use Piwigo\Image\ImageEntity;
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
        $this->conn->executeStatement('UPDATE images SET hit = 0, coi = NULL WHERE id IN (1, 2)');
        $this->conn->executeStatement('DELETE FROM image_format');
        parent::tearDown();
    }

    public function testIncrementVisitCounterIncrementsHit(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        $before = is_numeric($before) ? (int) $before : 0;

        $this->repo->incrementVisitCounter(ImageId::from(1));

        $after = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        $after = is_numeric($after) ? (int) $after : 0;

        self::assertSame($before + 1, $after);
    }

    public function testIncrementVisitCounterDoesNotChangeOtherRows(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from('images')
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        $before = is_numeric($before) ? (int) $before : 0;

        $this->repo->incrementVisitCounter(ImageId::from(1));

        $after = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from('images')
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        $after = is_numeric($after) ? (int) $after : 0;

        self::assertSame($before, $after);
    }

    public function testUpdateCoiSetsTheColumn(): void
    {
        $this->repo->updateCoi(ImageId::from(1), 'ABCD');

        $coi = $this->conn->createQueryBuilder()
            ->select('coi')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame('ABCD', $coi);
    }

    public function testUpdateCoiWithNullClearsTheColumn(): void
    {
        $this->repo->updateCoi(ImageId::from(1), 'ABCD');

        $this->repo->updateCoi(ImageId::from(1), null);

        $coi = $this->conn->createQueryBuilder()
            ->select('coi')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertNull($coi);
    }

    public function testFindByIdReturnsATypedImageProjection(): void
    {
        $image = $this->repo->findById(ImageId::from(1));

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(1, $image->id->value);
        self::assertSame('fixture-photo-1.jpg', $image->file);
        self::assertSame(200, $image->width);
        self::assertSame(150, $image->height);
    }

    public function testFindByIdReturnsNullForANonexistentImage(): void
    {
        self::assertNull($this->repo->findById(ImageId::from(999999)));
    }

    public function testFindByPathReturnsTheMatchingImage(): void
    {
        $path = $this->conn->createQueryBuilder()
            ->select('path')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        self::assertIsString($path);

        $image = $this->repo->findByPath($path);

        self::assertInstanceOf(Image::class, $image);
        self::assertSame(1, $image->id->value);
        self::assertSame($path, $image->path);
    }

    public function testFindByPathReturnsNullForAnUnknownPath(): void
    {
        self::assertNull($this->repo->findByPath('upload/does/not/exist.jpg'));
    }

    public function testFindByIdsReturnsTypedImagesKeyedById(): void
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

    public function testFindByIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findByIds([]));
    }

    public function testFindFormatByIdReturnsATypedImageFormat(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 12345);

        $format = $this->repo->findFormatById($formatId);

        self::assertNotNull($format);
        self::assertSame($formatId, $format->formatId);
        self::assertSame(1, $format->imageId->value);
        self::assertSame('webp', $format->ext);
        self::assertSame(12345, $format->filesize);
    }

    public function testFindFormatByIdReturnsNullForAMissingFormat(): void
    {
        self::assertNull($this->repo->findFormatById(999_999));
    }

    public function testFindFormatsForImageReturnsEveryFormatForThatImage(): void
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

    public function testFindFormatsForImageReturnsEmptyForAnImageWithNoFormats(): void
    {
        self::assertSame([], $this->repo->findFormatsForImage(ImageId::from(1)));
    }

    public function testFindFullFormatsByImageIdsReturnsEveryMatchingRow(): void
    {
        $this->insertFormat(1, 'webp', 100);
        $this->insertFormat(2, 'avif', 200);

        $formats = $this->repo->findFullFormatsByImageIds([1, 2]);

        self::assertCount(2, $formats);
        $imageIds = array_map(static fn (ImageFormat $format): int => $format->imageId->value, $formats);
        sort($imageIds);
        self::assertSame([1, 2], $imageIds);
    }

    public function testFindFullFormatsByImageIdsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findFullFormatsByImageIds([]));
    }

    private function insertFormat(int $imageId, string $ext, int $filesize): int
    {
        $this->conn->createQueryBuilder()
            ->insert('image_format')
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

    public function testTryAcquireLoungeLockThenFindLoungeLockValueRoundTrips(): void
    {
        try {
            $this->repo->tryAcquireLoungeLock('exec123-1700000000');

            self::assertSame('exec123-1700000000', $this->repo->findLoungeLockValue());
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'empty_lounge_running'");
        }
    }

    public function testTryAcquireLoungeLockIsANoopOnceHeld(): void
    {
        try {
            $this->repo->tryAcquireLoungeLock('exec123-1700000000');
            $this->repo->tryAcquireLoungeLock('exec456-1700000001');

            self::assertSame('exec123-1700000000', $this->repo->findLoungeLockValue());
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'empty_lounge_running'");
        }
    }

    public function testFindLoungeLockValueReturnsNullWhenNoLockHeld(): void
    {
        self::assertNull($this->repo->findLoungeLockValue());
    }

    public function testUpdateCoiIsANoopForANonexistentImage(): void
    {
        $this->repo->updateCoi(ImageId::from(999_999), 'ABCD');

        $coi = $this->conn->createQueryBuilder()
            ->select('coi')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertNull($coi);
    }

    public function testFindFormatsByImageIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findFormatsByImageIds([]));
    }

    public function testDeleteImagesIsANoopForEmptyIds(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM images');

        $this->repo->deleteImages([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM images');
        self::assertSame($before, $after);
    }

    public function testFindOrphanImageIdsExcludesIdsPresentInLoungedIds(): void
    {
        // Every fixture image already belongs to exactly one album -- break
        // image 3's own link so it's a real orphan for this test, then
        // restore the exact original row.
        $this->conn->executeStatement(
            'DELETE FROM image_category WHERE image_id = 3 AND category_id = 1'
        );

        try {
            $withoutLoungedIds = $this->repo->findOrphanImageIds([]);
            self::assertContains(3, $withoutLoungedIds);

            $withLoungedIds = $this->repo->findOrphanImageIds([3]);
            self::assertNotContains(3, $withLoungedIds);
        } finally {
            $rank = $this->conn->getDatabasePlatform()
                ->quoteSingleIdentifier('rank');
            $this->conn->executeStatement(
                "INSERT INTO image_category (image_id, category_id, {$rank}) VALUES (3, 1, 3)"
            );
        }
    }

    public function testTouchLastmodifiedIsANoopForEmptyIds(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('lastmodified')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->touchLastmodified([]);

        $after = $this->conn->createQueryBuilder()
            ->select('lastmodified')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testUpdateRotationSetsTheColumnForAnExistingImage(): void
    {
        try {
            $this->repo->updateRotation(ImageId::from(1), 3);

            $rotation = $this->conn->createQueryBuilder()
                ->select('rotation')
                ->from('images')
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();

            self::assertSame(3, is_numeric($rotation) ? (int) $rotation : null);
        } finally {
            $this->conn->executeStatement('UPDATE images SET rotation = 0 WHERE id = 1');
        }
    }

    public function testUpdateRotationIsANoopForANonexistentImage(): void
    {
        $this->repo->updateRotation(ImageId::from(999_999), 2);

        $rotation = $this->conn->createQueryBuilder()
            ->select('rotation')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, is_numeric($rotation) ? (int) $rotation : null);
    }

    public function testMassInsertLoungeIsANoopForEmptyInserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM lounge');

        $this->repo->massInsertLounge([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM lounge');
        self::assertSame($before, $after);
    }

    public function testMassInsertImageCategoryIsANoopForEmptyInserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM image_category');

        $this->repo->massInsertImageCategory([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM image_category');
        self::assertSame($before, $after);
    }

    public function testUpdateDimensionsSetsWidthAndHeightForAnExistingImage(): void
    {
        try {
            $this->repo->updateDimensions(1, 999, 888);

            $row = $this->conn->createQueryBuilder()
                ->select('width', 'height')
                ->from('images')
                ->where('id = 1')
                ->executeQuery()
                ->fetchAssociative();

            self::assertIsArray($row);
            self::assertSame(999, is_numeric($row['width']) ? (int) $row['width'] : null);
            self::assertSame(888, is_numeric($row['height']) ? (int) $row['height'] : null);
        } finally {
            $this->conn->executeStatement('UPDATE images SET width = 200, height = 150 WHERE id = 1');
        }
    }

    public function testUpdateDimensionsIsANoopForANonexistentImage(): void
    {
        $this->repo->updateDimensions(999_999, 111, 222);

        $row = $this->conn->createQueryBuilder()
            ->select('width', 'height')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(200, is_numeric($row['width']) ? (int) $row['width'] : null);
        self::assertSame(150, is_numeric($row['height']) ? (int) $row['height'] : null);
    }

    public function testUpdateDescriptiveFieldsIsANoopForANonexistentImage(): void
    {
        $nameBefore = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateDescriptiveFields(ImageId::from(999_999), name: 'Should Not Apply');

        $nameAfter = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($nameBefore, $nameAfter);
    }

    public function testUpdateDescriptiveFieldsSetsDateCreationWhenSupplied(): void
    {
        try {
            $this->repo->updateDescriptiveFields(ImageId::from(1), dateCreation: '2020-05-01 00:00:00');

            $dateCreation = $this->conn->createQueryBuilder()
                ->select('date_creation')
                ->from('images')
                ->where('id = 1')
                ->executeQuery()
                ->fetchOne();

            self::assertSame('2020-05-01 00:00:00', $dateCreation);
        } finally {
            $this->conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id = 1');
        }
    }

    public function testUpdateFormatFilesizeUpdatesAnExistingFormat(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 100);

        $this->repo->updateFormatFilesize($formatId, 999);

        $filesize = $this->conn->createQueryBuilder()
            ->select('filesize')
            ->from('image_format')
            ->where('format_id = ' . $formatId)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(999, is_numeric($filesize) ? (int) $filesize : null);
    }

    public function testUpdateFormatFilesizeIsANoopForANonexistentFormat(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 100);

        $this->repo->updateFormatFilesize(999_999, 555);

        $filesize = $this->conn->createQueryBuilder()
            ->select('filesize')
            ->from('image_format')
            ->where('format_id = ' . $formatId)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(100, is_numeric($filesize) ? (int) $filesize : null);
    }

    public function testMassInsertFormatsIsANoopForEmptyInserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM image_format');

        $this->repo->massInsertFormats([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM image_format');
        self::assertSame($before, $after);
    }

    public function testFindImageIdsAndExtsByFormatIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findImageIdsAndExtsByFormatIds([]));
    }

    public function testDeleteFormatsByIdsIsANoopForEmptyIds(): void
    {
        $formatId = $this->insertFormat(1, 'webp', 100);

        $this->repo->deleteFormatsByIds([]);

        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM image_format WHERE format_id = ' . $formatId);
        self::assertSame(1, $count);
    }

    public function testFindPathsAndLevelForIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findPathsAndLevelForIds([]));
    }

    public function testUpdateTextFieldForImagesIsANoopForEmptyIds(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateTextFieldForImages([], ImageTextField::Name, 'Should Not Apply');

        $after = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testMassUpdateFieldsIsANoopForEmptyDatas(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->massUpdateFields([
            'primary' => ['id'],
            'update' => ['name'],
        ], []);

        $after = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testUpdateFieldsIsANoopForEmptyUpdates(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateFields(ImageId::from(1), []);

        $after = $this->conn->createQueryBuilder()
            ->select('name')
            ->from('images')
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testMassInsertImagesIsANoopForEmptyInserts(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM images');

        $this->repo->massInsertImages([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM images');
        self::assertSame($before, $after);
    }

    public function testFindOldestLoungeAgeInfoReturnsNullWhenTheOldestLoungedImagesDateAvailableIsNotScalar(): void
    {
        // date_available is nullable on `images` -- a lounge row pointing at
        // an image whose date_available is genuinely NULL must trip the
        // `! is_scalar($dateAvailable)` guard rather than returning a
        // non-string value. The fixture's own lounge table starts empty, so
        // this single row is guaranteed to be the "oldest" (ORDER BY
        // image_id ASC LIMIT 1) one found.
        $this->conn->executeStatement(
            "INSERT INTO images (file, path, date_available) VALUES ('lounge-null-date.jpg', 'upload/lounge-null-date.jpg', NULL)"
        );
        $imageId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            'INSERT INTO lounge (image_id, category_id) VALUES (?, ?)',
            [$imageId, 1]
        );

        try {
            self::assertNull($this->repo->findOldestLoungeAgeInfo());
        } finally {
            $this->conn->executeStatement('DELETE FROM lounge WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
    }

    public function testDeleteImageCategoryLinksForCategoryIdsIsANoopForEmptyCategoryIds(): void
    {
        // Fixture: image 1 is linked to category 1 (image_category rows
        // (1,1),(2,1),(3,1),(4,2),(5,2)).
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM image_category WHERE image_id = 1');

        $this->repo->deleteImageCategoryLinksForCategoryIds(ImageId::from(1), []);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM image_category WHERE image_id = 1');
        self::assertSame($before, $after);
    }

    public function testFindEarliestDateAvailableReturnsNullWhenNoImagesExist(): void
    {
        // Same beginTransaction()/rollBack() convention as
        // FilterResolverTest's own "images table is empty" tests --
        // guarantees the wipe never survives past this test regardless of
        // how the assertion resolves.
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('DELETE FROM images');

            self::assertNull($this->repo->findEarliestDateAvailable());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testFindIdsAddedSameDayAsLatestExcludesOlderImages(): void
    {
        // Every fixture image already shares the same date_available (the
        // "latest" day) -- add one genuinely older image to prove the
        // DATE_SUB()-based lower bound really excludes it, not just
        // "returns everything by accident."
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement(
                "INSERT INTO images (file, path, date_available) VALUES ('older-photo.jpg', 'upload/older-photo.jpg', '2020-01-01 00:00:00')"
            );
            $olderId = (int) $this->conn->lastInsertId();

            $ids = $this->repo->findIdsAddedSameDayAsLatest();

            self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], $ids);
            self::assertNotContains($olderId, $ids);
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testFindHistoryDisplayInfoByIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findHistoryDisplayInfoByIds([]));
    }

    public function testFindHistoryDisplayInfoByIdsReturnsTheRealRowKeyedById(): void
    {
        $rows = $this->repo->findHistoryDisplayInfoByIds([1]);

        self::assertSame([
            1 => [
                'id' => 1,
                'label' => 'Photo 1',
                'filesize' => 1,
                'file' => 'fixture-photo-1.jpg',
                'path' => 'upload/2026/08/01/20260801000000-2e7e6c90.jpg',
                'representative_ext' => null,
            ],
        ], $rows);
    }

    public function testFindDistinctDimensionsReturnsTheRealDistinctPairs(): void
    {
        // Every fixture image shares width=200/height=150 -- exactly one
        // distinct pair, proving the query + the int/int narrowing.
        self::assertSame([
            [
                'width' => 200,
                'height' => 150,
            ],
        ], $this->repo->findDistinctDimensions());
    }

    public function testFindDistinctFilesizesReturnsTheRealDistinctValues(): void
    {
        // Every fixture image shares filesize=1 -- exactly one distinct
        // value, proving the query + the int narrowing.
        self::assertSame([
            [
                'filesize' => 1,
            ],
        ], $this->repo->findDistinctFilesizes());
    }

    public function testFindIdsAndDatesForBatchUnitSaveReturnsTheRealRows(): void
    {
        $rows = $this->repo->findIdsAndDatesForBatchUnitSave([1, 2]);

        self::assertSame([
            [
                'id' => 1,
                'date_creation' => null,
            ],
            [
                'id' => 2,
                'date_creation' => null,
            ],
        ], $rows);
    }

    public function testFindMostRecentImageCategoryInfoReturnsNullWhenNoImageIsLinkedToACategory(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('DELETE FROM image_category');

            self::assertNull($this->repo->findMostRecentImageCategoryInfo());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testFindMostRecentImageCategoryInfoReturnsTheRealJoinedRow(): void
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

    public function testDeleteImageCategoryRowsForImageIdsIsANoopForEmptyIds(): void
    {
        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM image_category');

        $this->repo->deleteImageCategoryRowsForImageIds([]);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM image_category');
        self::assertSame($before, $after);
    }

    public function testFindIdsByFilenameInCategoryReturnsMatchingImageIds(): void
    {
        // Fixture: image 1 is 'fixture-photo-1.jpg', linked to category 1.
        self::assertSame([1], $this->repo->findIdsByFilenameInCategory('fixture-photo-1.jpg', CategoryId::from(1)));
    }

    public function testFindIdsByFilenameInCategoryReturnsEmptyForAFilenameNotInThatCategory(): void
    {
        // Image 1 matches the filename but is only linked to category 1,
        // not category 2 (category 2 holds images 4 and 5).
        self::assertSame([], $this->repo->findIdsByFilenameInCategory('fixture-photo-1.jpg', CategoryId::from(2)));
    }

    public function testFindUploadResultInfoByIdReturnsNullForANonexistentImage(): void
    {
        self::assertNull($this->repo->findUploadResultInfoById(ImageId::from(999_999)));
    }

    public function testFindIdsByMd5sumsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findIdsByMd5sums([]));
    }

    public function testFindIdsByFilenamesReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findIdsByFilenames([]));
    }

    public function testFindExistingIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findExistingIds([]));
    }

    public function testFindCategoryLinksForImageIdsWithConditionReturnsEmptyArrayForEmptyImageIds(): void
    {
        self::assertSame([], $this->repo->findCategoryLinksForImageIdsWithCondition([], self::noPermissionRestriction()));
    }

    public function testFindIdsAndPathsByStorageCategoryIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findIdsAndPathsByStorageCategoryIds([]));
    }

    public function testFindAddMethodBreakdownGroupsByStorageCategoryPresence(): void
    {
        // Every fixture image has storage_category_id NULL (added via the
        // API, never a filesystem sync) -- temporarily give image 1 a real
        // storage_category_id so both the 'sync' and 'api' IF() branches,
        // and both array_map() mapping passes, actually run against real
        // grouped data.
        try {
            $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id = 1');

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
            $this->conn->executeStatement('UPDATE images SET storage_category_id = NULL WHERE id = 1');
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
    public function testFindAddMethodBreakdownTracksTheTrueMaximumAndReturnsAReindexedList(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement(
                "INSERT INTO images (file, date_available) VALUES ('p18-earliest.jpg', '2010-01-01 00:00:00')"
            );
            $this->conn->executeStatement(
                "INSERT INTO images (file, date_available) VALUES ('p18-max.jpg', '2030-01-01 00:00:00')"
            );
            $this->conn->executeStatement(
                "INSERT INTO images (file, date_available) VALUES ('p18-middle.jpg', '2020-01-01 00:00:00')"
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

    public function testFindByIdOrFilePatternMatchesById(): void
    {
        $row = $this->repo->findByIdOrFilePattern(1, null);

        self::assertInstanceOf(ImageLookupRow::class, $row);
        self::assertSame('fixture-photo-1.jpg', $row->file);
    }

    public function testFindByIdOrFilePatternMatchesByFilePattern(): void
    {
        $row = $this->repo->findByIdOrFilePattern(0, 'fixture-photo-2');

        self::assertInstanceOf(ImageLookupRow::class, $row);
        self::assertSame(2, $row->id->value);
    }

    public function testFindByIdOrFilePatternReturnsFalseForNoMatch(): void
    {
        self::assertFalse($this->repo->findByIdOrFilePattern(999_999, null));
    }

    /**
     * `_` is a single-character `LIKE` wildcard. 'fixture_photo-2' (the
     * real 'fixture-photo-2' with its first `-` swapped for `_`) would
     * match 'fixture-photo-2.jpg' if `_` were left as a live wildcard --
     * this pins that it is escaped to a literal instead, so a filename
     * that merely resembles the pattern does not match.
     */
    public function testFindByIdOrFilePatternEscapesLikeWildcardsInTheFileValue(): void
    {
        self::assertFalse($this->repo->findByIdOrFilePattern(0, 'fixture_photo-2'));
    }

    /**
     * [SEC-20] regression: a file-pattern value containing SQL syntax
     * (exactly the shape a crafted picture.php URL path segment could
     * produce, per Controller\PictureController's own "already escaped"
     * claim that only neutralized LIKE wildcards, not quotes) is now
     * always bound as a literal LIKE value -- it matches nothing rather
     * than tautologically matching every row.
     */
    public function testFindByIdOrFilePatternTreatsSqlSyntaxAsALiteralValue(): void
    {
        self::assertFalse($this->repo->findByIdOrFilePattern(0, "fixture-photo-1' OR '1'='1"));
    }

    public function testFindIdsByMd5sumReturnsTheMatchingImage(): void
    {
        self::assertSame([1], $this->repo->findIdsByMd5sum('2e7ee450c4a4cffe42945205029782b9'));
    }

    public function testFindIdsByMd5sumReturnsEmptyForNoMatch(): void
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
    public function testFindImageIdsWithoutMd5sumReturnsTheRealIdsAsInts(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('UPDATE images SET md5sum = NULL WHERE id = 2');

            self::assertSame([2], $this->repo->findImageIdsWithoutMd5sum());
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * [SEC-20] regression: findIdsByMd5sum()'s own real caller
     * (Ws\Images::add()'s `original_sum` param) has zero WS-level type
     * constraints -- a value containing SQL syntax must be treated as a
     * literal, matching nothing, not injected as SQL structure.
     */
    public function testFindIdsByMd5sumTreatsSqlSyntaxAsALiteralValue(): void
    {
        self::assertSame([], $this->repo->findIdsByMd5sum("x' OR '1'='1"));
    }

    public function testExistsWithColumnValueIsTrueForAMatchingMd5sum(): void
    {
        self::assertTrue($this->repo->existsWithColumnValue(ImageUniquenessColumn::Md5sum, '2e7ee450c4a4cffe42945205029782b9'));
    }

    public function testExistsWithColumnValueIsTrueForAMatchingFile(): void
    {
        self::assertTrue($this->repo->existsWithColumnValue(ImageUniquenessColumn::File, 'fixture-photo-1.jpg'));
    }

    public function testExistsWithColumnValueIsFalseForNoMatch(): void
    {
        self::assertFalse($this->repo->existsWithColumnValue(ImageUniquenessColumn::Md5sum, 'no-such-md5sum'));
    }

    /**
     * [SEC-20] regression: existsWithColumnValue()'s own real caller
     * (Ws\Images::add()'s unvalidated original_sum/original_filename
     * params) has zero WS-level type constraints -- a value containing SQL
     * syntax must be treated as a literal, matching nothing, not injected
     * as a tautology.
     */
    public function testExistsWithColumnValueTreatsSqlSyntaxAsALiteralValue(): void
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

    public function testIsImageAccessibleWithConditionIsTrueWithNoRestriction(): void
    {
        self::assertTrue($this->repo->isImageAccessibleWithCondition(ImageId::from(1), self::noPermissionRestriction()));
    }

    public function testIsImageAccessibleWithConditionAppliesTheGivenCondition(): void
    {
        // fixture: image 1 belongs to category 1 -- excluding it via
        // forbiddenCategoryIds (checked against ic.category_id) excludes
        // image 1.
        self::assertFalse($this->repo->isImageAccessibleWithCondition(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function testFindRowWithConditionReturnsTheMatchingRow(): void
    {
        $row = $this->repo->findRowWithCondition(ImageId::from(1), self::noPermissionRestriction());

        self::assertNotNull($row);
        self::assertSame('fixture-photo-1.jpg', $row['file']);
    }

    public function testFindRowWithConditionReturnsNullWhenTheConditionExcludesIt(): void
    {
        self::assertNull($this->repo->findRowWithCondition(ImageId::from(1), new PermissionCriteria(null, null, [999_999], null, null, null)));
    }

    public function testFindRelatedCategoriesForImageReturnsMatchingRows(): void
    {
        $rows = $this->repo->findRelatedCategoriesForImage(ImageId::from(1), self::noPermissionRestriction());

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertTrue($rows[0]['commentable']);
    }

    public function testFindRelatedCategoriesForImageAppliesTheGivenCondition(): void
    {
        self::assertSame([], $this->repo->findRelatedCategoriesForImage(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function testIsImageCommentableWithConditionIsTrueForACommentableCategory(): void
    {
        self::assertTrue($this->repo->isImageCommentableWithCondition(ImageId::from(1), self::noPermissionRestriction()));
    }

    public function testIsImageCommentableWithConditionAppliesTheGivenCondition(): void
    {
        self::assertFalse($this->repo->isImageCommentableWithCondition(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function testFindVisibleCategoriesForImageReturnsMatchingRows(): void
    {
        $rows = $this->repo->findVisibleCategoriesForImage(ImageId::from(1), self::noPermissionRestriction());

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
    }

    public function testFindVisibleCategoriesForImageAppliesTheGivenCondition(): void
    {
        self::assertSame([], $this->repo->findVisibleCategoriesForImage(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function testHasAccessibleImageWithAuthorIsFalseWhenNoImageHasAnAuthor(): void
    {
        self::assertFalse($this->repo->hasAccessibleImageWithAuthor(self::noPermissionRestriction()));
    }

    public function testHasAccessibleImageWithAuthorIsTrueOnceOneImageHasAnAuthor(): void
    {
        $this->conn->executeStatement("UPDATE images SET author = 'fixture-author' WHERE id = 1");

        try {
            self::assertTrue($this->repo->hasAccessibleImageWithAuthor(self::noPermissionRestriction()));
        } finally {
            $this->conn->executeStatement('UPDATE images SET author = NULL WHERE id = 1');
        }
    }

    public function testIsImageAccessibleViaCategoryWithConditionIsTrueWithNoRestriction(): void
    {
        self::assertTrue($this->repo->isImageAccessibleViaCategoryWithCondition(ImageId::from(1), self::noPermissionRestriction()));
    }

    public function testIsImageAccessibleViaCategoryWithConditionAppliesTheGivenCondition(): void
    {
        self::assertFalse($this->repo->isImageAccessibleViaCategoryWithCondition(ImageId::from(1), new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function testFindWithConditionsPaginatedReturnsMatchingRowsAndTotal(): void
    {
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(), [1], new SqlCondition(''));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 10, 0);

        self::assertCount(3, $result->rows);
        self::assertSame(3, $result->total);
    }

    public function testFindWithConditionsPaginatedRespectsTheLimit(): void
    {
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(), [1], new SqlCondition(''));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 1, 0);

        self::assertCount(1, $result->rows);
        self::assertSame(3, $result->total);
    }

    public function testFindWithConditionsPaginatedAppliesTheFilterCondition(): void
    {
        // fixture: category 1's images are 1 (rating_score 4.50), 2
        // (3.00), 3 (5.00) -- minRate: 4.0 keeps only 1 and 3.
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(minRate: 4.0), [1], new SqlCondition(''));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 10, 0);

        self::assertCount(2, $result->rows);
        self::assertSame(2, $result->total);
    }

    public function testFindWithConditionsPaginatedAppliesTheVisibleImagesCondition(): void
    {
        $criteria = new CategoryImagesCriteria(new ImageFilterCriteria(), [1], new SqlCondition('i.id = -1'));
        $result = $this->repo->findWithConditionsPaginated($criteria, '', 10, 0);

        self::assertSame([], $result->rows);
        self::assertSame(0, $result->total);
    }

    public function testFindCategoryLinksForImageIdsWithConditionReturnsMatchingRows(): void
    {
        $rows = $this->repo->findCategoryLinksForImageIdsWithCondition([1, 2], self::noPermissionRestriction());

        $pairs = array_map(static fn (ImageCategoryLink $row): string => $row->imageId . ':' . $row->categoryId, $rows);
        sort($pairs);

        self::assertSame(['1:1', '2:1'], $pairs);
    }

    public function testFindCategoryLinksForImageIdsWithConditionAppliesTheGivenCondition(): void
    {
        self::assertSame([], $this->repo->findCategoryLinksForImageIdsWithCondition([1, 2], new PermissionCriteria([1], null, null, null, null, null)));
    }

    public function testFindForMissingDerivativesMatchesTheGivenFilterCondition(): void
    {
        // fixture ratings: 1=>4.50, 2=>3.00, 3=>5.00, 4=>2.00, 5=>NULL --
        // minRate: 4.6 keeps only image 3 (a NULL rating never satisfies a
        // >= comparison).
        $criteria = new MissingDerivativesCriteria(new ImageFilterCriteria(minRate: 4.6));
        $rows = $this->repo->findForMissingDerivatives($criteria, 999_999, 10);

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->id);
    }

    public function testFindForMissingDerivativesFiltersByIds(): void
    {
        $criteria = new MissingDerivativesCriteria(new ImageFilterCriteria(), [2, 3]);
        $rows = $this->repo->findForMissingDerivatives($criteria, 999_999, 10);

        $ids = array_map(static fn (MissingDerivativeRow $row): int => $row->id, $rows);
        sort($ids);
        self::assertSame([2, 3], $ids);
    }

    public function testCountLoungeImagesPendingForCategoryCountsUnlinkedLoungeRows(): void
    {
        // `lounge.image_id` FK-references `images.id`, and every fixture
        // image (1-5) already has an image_category link -- a disposable
        // image row (with none) is the only way to reach the "pending"
        // (not yet linked into image_category) branch this method counts.
        $this->conn->insert('images', [
            'file' => 'p18-test-lounge-pending.jpg',
        ]);
        $imageId = (int) $this->conn->lastInsertId();
        $this->conn->insert('lounge', [
            'image_id' => $imageId,
            'category_id' => 1,
        ]);

        try {
            self::assertSame(1, $this->repo->countLoungeImagesPendingForCategory(CategoryId::from(1)));
        } finally {
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
    }

    public function testIsImageInCategoryIsTrueForARealLink(): void
    {
        self::assertTrue($this->repo->isImageInCategory(ImageId::from(1), CategoryId::from(1)));
    }

    public function testIsImageInCategoryIsFalseForNoLink(): void
    {
        self::assertFalse($this->repo->isImageInCategory(ImageId::from(1), CategoryId::from(2)));
    }

    public function testFindMaxRankForCategoryReturnsTheHighestRank(): void
    {
        self::assertSame(3, $this->repo->findMaxRankForCategory(CategoryId::from(1)));
    }

    public function testFindMaxRankForCategoryReturnsNullForNoRankedImages(): void
    {
        self::assertNull($this->repo->findMaxRankForCategory(CategoryId::from(999_999)));
    }

    public function testIncrementRanksFromForCategoryBumpsRanksAtOrAboveTheGivenRank(): void
    {
        $rankIdentifier = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        try {
            $this->repo->incrementRanksFromForCategory(CategoryId::from(1), 2);

            $ranks = $this->conn->createQueryBuilder()
                ->select('image_id', $rankIdentifier)
                ->from('image_category')
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
            $this->conn->executeStatement("UPDATE image_category SET {$rankIdentifier} = image_id WHERE category_id = 1");
        }
    }

    public function testUpdateRankForImageInCategorySetsTheRank(): void
    {
        $rankIdentifier = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        try {
            $this->repo->updateRankForImageInCategory(ImageId::from(1), CategoryId::from(1), 99);

            $rank = $this->conn->createQueryBuilder()
                ->select($rankIdentifier)
                ->from('image_category')
                ->where('image_id = 1')
                ->andWhere('category_id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertSame(99, $rank);
        } finally {
            $this->conn->executeStatement("UPDATE image_category SET {$rankIdentifier} = 1 WHERE image_id = 1 AND category_id = 1");
        }
    }

    public function testCountImagesInCategoryCountsLinkedImages(): void
    {
        self::assertSame(3, $this->repo->countImagesInCategory(CategoryId::from(1)));
    }

    public function testFindAssociatedCategoryIdsReturnsTheRealCategories(): void
    {
        self::assertSame([1], $this->repo->findAssociatedCategoryIds(ImageId::from(1)));
    }

    public function testUpdateLevelForImagesSetsTheLevelAndReturnsTheAffectedCount(): void
    {
        try {
            $affected = $this->repo->updateLevelForImages([1, 2], 5);

            self::assertSame(2, $affected);
            self::assertSame(
                5,
                $this->conn->createQueryBuilder()
                    ->select('level')
                    ->from('images')
                    ->where('id = 1')
                    ->executeQuery()
                    ->fetchOne()
            );
        } finally {
            $this->conn->executeStatement('UPDATE images SET level = 0 WHERE id IN (1, 2)');
        }
    }

    public function testFindPathsForFileDeletionReturnsTheMatchingRows(): void
    {
        $rows = $this->repo->findPathsForFileDeletion([1, 2]);

        $ids = array_column($rows, 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function testFindNextIdReturnsOneMoreThanTheCurrentMax(): void
    {
        // findNextId() returns COALESCE(MAX(id)+1, 1), verified here
        // against the real, non-empty fixture table -- the empty-table
        // branch isn't practically testable against this shared fixture
        // DB.
        $maxId = $this->conn->fetchOne('SELECT MAX(id) FROM images');

        self::assertSame((is_numeric($maxId) ? $maxId : 0) + 1, $this->repo->findNextId());
    }

    public function testFindIdsNotInCategoriesExcludesLinkedImages(): void
    {
        // The non-empty branch's DQL NOT IN (subquery) is built via a
        // separate QueryBuilder's own getDQL() string interpolated into
        // the outer query. Fixture: images 1-3 are in category 1, images
        // 4-5 are in category 2.
        $ids = $this->repo->findIdsNotInCategories([1]);
        sort($ids);
        self::assertSame([4, 5], $ids);
    }

    public function testFindIdsNotInCategoriesReturnsEveryImageForNoCategories(): void
    {
        $ids = $this->repo->findIdsNotInCategories([]);
        sort($ids);
        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testFindIdsNotInCategoriesReturnsEmptyWhenEveryImageIsLinked(): void
    {
        self::assertSame([], $this->repo->findIdsNotInCategories([1, 2]));
    }

    public function testFindIdsInCategoriesReturnsLinkedImages(): void
    {
        $ids = $this->repo->findIdsInCategories([2]);
        sort($ids);
        self::assertSame([4, 5], $ids);
    }

    public function testFindIdsInCategoriesReturnsEmptyForNoCategories(): void
    {
        self::assertSame([], $this->repo->findIdsInCategories([]));
    }

    public function testFindExistingAssociationsReturnsRealValuesNotAnEmptyArray(): void
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

    public function testFindMaxRanksByCategoryReturnsRealValuesNotAnEmptyArray(): void
    {
        self::assertSame(
            [
                '1' => 3,
                '2' => 2,
            ],
            $this->repo->findMaxRanksByCategory([1, 2])
        );
    }

    /**
     * [Mutation] getSingleColumnResult() bypasses Doctrine's own custom-
     * Type conversion (it fetches the raw driver column directly), so
     * `c.id` comes back as a raw scalar that must be explicitly mapped to
     * int here, not already an int/ImageId.
     */
    public function testFindRepresentedCategoryIdsReturnsTheRealCategory(): void
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
    public function testFindRepresentedCategoryIdsTreatsNonNumericIdsAsAHarmlessZero(): void
    {
        self::assertSame([], $this->repo->findRepresentedCategoryIds(['not-a-number']));
    }

    public function testFindVirtuallyAssociatedCategoryRowsReturnsRealCategories(): void
    {
        // Every fixture image has storage_category_id NULL, so the "OR
        // i.storageCategory IS NULL" branch always applies -- image 1's
        // real category_id membership (category 1) must come back, not [].
        $rows = $this->repo->findVirtuallyAssociatedCategoryRows([1]);

        self::assertSame([[
            'id' => 1,
        ]], $rows);
    }

    public function testFindCategoryLinksForImageReturnsTheRealCategory(): void
    {
        $rows = $this->repo->findCategoryLinksForImage(ImageId::from(1));

        self::assertSame([[
            'category_id' => 1,
            'uppercats' => '1',
            'dir' => null,
        ]], $rows);
    }

    public function testFindLoungeRowsReturnsTheRealRows(): void
    {
        // No fixture lounge data exists, so this inserts its own within
        // a rolled-back transaction.
        $this->conn->beginTransaction();

        try {
            $this->conn->insert('lounge', [
                'image_id' => 1,
                'category_id' => 2,
            ]);

            self::assertEquals(
                [new ImageCategoryLink(1, 2)],
                $this->repo->findLoungeRows()
            );
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testDeleteLoungeUpToRemovesMatchingRowsOnly(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->insert('lounge', [
                'image_id' => 1,
                'category_id' => 2,
            ]);
            $this->conn->insert('lounge', [
                'image_id' => 3,
                'category_id' => 2,
            ]);

            $this->repo->deleteLoungeUpTo(1);

            self::assertSame([3], $this->repo->findLoungedImageIds());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testFindLoungedImageIdsReturnsTheRealIds(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->conn->insert('lounge', [
                'image_id' => 4,
                'category_id' => 1,
            ]);

            self::assertSame([4], $this->repo->findLoungedImageIds());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testFindDissociableImageIdsReturnsImagesNotStoredUnderTheCategory(): void
    {
        // Fixture: images 1-3 are in category 1, none has a
        // storage_category_id set, so all 3 are dissociable from it.
        $ids = $this->repo->findDissociableImageIds([1, 2, 3], 1);
        sort($ids);
        self::assertSame([1, 2, 3], $ids);
    }

    public function testDeleteImageCategoryLinksRemovesOnlyTheGivenImagesAndCategory(): void
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

    public function testCountImagesInCategoriesCountsDistinctImages(): void
    {
        // Fixture: 5 distinct images across image_category.
        self::assertSame(5, $this->repo->countImagesInCategories());
    }

    public function testCountImageCategoryLinksCountsEveryRow(): void
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
    public function testCountAllImagesReturnsTheRealTotal(): void
    {
        self::assertSame(5, $this->repo->countAllImages());
    }

    /**
     * [Mutation] This method stays on raw DBAL (`Connection::
     * fetchFirstColumn()`), which never applies Doctrine's own Type
     * conversion, so the ids come back as raw driver scalars that must be
     * explicitly mapped to int.
     */
    public function testFindIdsVisibleInCategoriesRecentlyAvailableReturnsTheRealIdsAsInts(): void
    {
        // Fixture: images 1-3 are in category 1, all dated 2026-08-01 --
        // a 10-year lookback comfortably covers that against Env::now()'s
        // frozen test clock.
        $recentPeriodExpr = SqlDialect::getRecentPeriodExpression(3650);

        $ids = $this->repo->findIdsVisibleInCategoriesRecentlyAvailable('1', $recentPeriodExpr);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function testFindThumbnailRowsForCategoryOrderedByRankReturnsRealRowsInRankOrder(): void
    {
        // Fixture: category 1 has images 1,2,3 at ranks 1,2,3.
        $rows = $this->repo->findThumbnailRowsForCategoryOrderedByRank(CategoryId::from(1));

        self::assertSame([1, 2, 3], array_column($rows, 'id'));
    }

    public function testFindImageIdsOrderedByRankForCategoryReturnsRealIdsInRankOrder(): void
    {
        self::assertSame([1, 2, 3], $this->repo->findImageIdsOrderedByRankForCategory(CategoryId::from(1)));
    }

    public function testFindCategoryIdsForImageReturnsTheRealCategories(): void
    {
        self::assertSame([1], $this->repo->findCategoryIdsForImage(ImageId::from(1)));
    }

    public function testFindOrphanImageCategoryLinkIdsReturnsEmptyWhenEveryLinkHasARealImage(): void
    {
        self::assertSame([], $this->repo->findOrphanImageCategoryLinkIds());
    }

    public function testFindOrphanImageCategoryLinkIdsReturnsLinksWithNoRealImage(): void
    {
        // image_category.image_id FK-references images.id ON DELETE
        // CASCADE, so a genuine orphan can only exist the way it would in
        // real life -- imported/fixed-up data that bypassed the FK, here
        // simulated by disabling FK checks for one insert.
        $this->conn->beginTransaction();

        try {
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->insert('image_category', [
                'image_id' => 999999,
                'category_id' => 1,
            ]);
            $this->enableForeignKeyChecks($this->conn);

            self::assertSame([999999], $this->repo->findOrphanImageCategoryLinkIds());
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function unitRowIds(array $rows): array
    {
        return array_map(
            static fn (array $row): int => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            $rows
        );
    }

    public function testFindBatchManagerUnitRowsRestrictsToTheGivenCategory(): void
    {
        // Fixture: image_category links images 1/2/3 to category 1 and 4/5
        // to category 2 -- a non-null $categoryId adds the JOIN, null omits
        // it entirely.
        self::assertSame(
            [1, 2, 3],
            self::unitRowIds($this->repo->findBatchManagerUnitRows([1, 2, 3, 4, 5], 1, 'ORDER BY id ASC', 100, 0))
        );
        self::assertSame(
            [1, 2, 3, 4, 5],
            self::unitRowIds($this->repo->findBatchManagerUnitRows([1, 2, 3, 4, 5], null, 'ORDER BY id ASC', 100, 0))
        );
    }

    public function testFindBatchManagerUnitRowsSelectsOnlyImagesColumnsWhenJoined(): void
    {
        // `SELECT images.*`, not a bare `SELECT *`: the category JOIN would
        // otherwise leak all three of image_category's own columns into the
        // row, making its column set depend on $categoryId.
        $rows = $this->repo->findBatchManagerUnitRows([1, 2, 3], 1, 'ORDER BY id ASC', 100, 0);

        self::assertNotSame([], $rows);
        foreach (['image_id', 'category_id', 'rank'] as $joinedColumn) {
            self::assertArrayNotHasKey($joinedColumn, $rows[0]);
        }

        // A real images column is still present, proving the assertion above
        // isn't passing against an empty/short row shape.
        self::assertArrayHasKey('storage_category_id', $rows[0]);
    }

    public function testFindBatchManagerUnitRowsCanOrderByTheJoinedRankColumn(): void
    {
        // `rank` lives only on image_category, so this exercises the JOIN's
        // second, load-bearing purpose: without it the query fails outright
        // with "Unknown column 'rank' in 'order clause'". The fixture's own
        // ranks (1,2,3) match the id order exactly, so they are inverted
        // here to make the assertion discriminate between rank-ordering and
        // id-ordering rather than passing either way.
        $rankOrderBy = 'ORDER BY ' . new SortRenderer($this->conn)->rankColumn() . ' ASC';

        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('UPDATE image_category SET `rank` = 3 WHERE image_id = 1 AND category_id = 1');
            $this->conn->executeStatement('UPDATE image_category SET `rank` = 1 WHERE image_id = 3 AND category_id = 1');

            self::assertSame(
                [3, 2, 1],
                self::unitRowIds($this->repo->findBatchManagerUnitRows([1, 2, 3], 1, $rankOrderBy, 100, 0))
            );
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * A duplicate group far larger than `group_concat_max_len` (1024 bytes
     * by default) must return every member and nothing else.
     *
     * This is the case the previous `GROUP_CONCAT(i.id)` implementation got
     * wrong twice over. Truncation cuts at a byte boundary, so the tail of
     * the concatenated list was not merely lost: a cut mid-number turned
     * `...,12345` into `...,123`, a valid id belonging to an unrelated
     * image, which passed the `is_numeric()` filter and entered the
     * prefilter as a false positive.
     */
    public function testFindIdsGroupedByDuplicateFieldsHandlesAGroupBeyondGroupConcatMaxLen(): void
    {
        $this->conn->beginTransaction();

        try {
            // 400 rows sharing one filename: ~6 bytes per id once the ids
            // reach five digits, so the concatenated list runs well past
            // 1024 bytes.
            $expected = [];
            for ($i = 0; $i < 400; $i++) {
                $this->conn->executeStatement(
                    "INSERT INTO images (file, date_available, path, md5sum, level, width, height)
                     VALUES ('dupe-overflow.jpg', '2026-08-01 00:00:00', :path, :md5, 0, 200, 150)",
                    [
                        'path' => 'upload/dupe/' . $i . '.jpg',
                        'md5' => str_pad((string) $i, 32, '0', STR_PAD_LEFT),
                    ],
                );
                $expected[] = (int) $this->conn->lastInsertId();
            }

            $ids = $this->repo->findIdsGroupedByDuplicateFields([ImageDuplicateField::File]);

            foreach ($expected as $id) {
                self::assertContains($id, $ids, "duplicate group member {$id} was dropped");
            }

            // No fabricated members: every returned id must be a real row
            // that genuinely shares a filename with another.
            $realIds = array_map(
                intval(...),
                $this->conn->fetchFirstColumn(
                    'SELECT id FROM images WHERE file IN (SELECT file FROM images GROUP BY file HAVING COUNT(*) > 1)'
                )
            );
            sort($realIds);
            $returned = $ids;
            sort($returned);

            self::assertSame($realIds, $returned, 'returned ids must match the real duplicate set exactly');
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * `md5sum` hydrates as a Md5Sum value object, not a scalar, so the
     * grouping key must handle Stringable. Treating an unrecognised value as
     * null keyed every row identically and reported the whole table as one
     * duplicate group -- the exact opposite of the correct answer, and
     * invisible to a test that only groups on plain-typed columns.
     */
    public function testFindIdsGroupedByDuplicateFieldsHandlesValueObjectTypedColumns(): void
    {
        // Every fixture image has a distinct md5sum, so grouping on it alone
        // must find nothing.
        self::assertSame(
            [],
            $this->repo->findIdsGroupedByDuplicateFields([ImageDuplicateField::Md5sum]),
            'distinct md5sums must not group together'
        );

        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement(
                "UPDATE images SET md5sum = 'ffffffffffffffffffffffffffffffff' WHERE id IN (1, 2)"
            );

            $ids = $this->repo->findIdsGroupedByDuplicateFields([ImageDuplicateField::Md5sum]);
            sort($ids);

            self::assertSame([1, 2], $ids, 'images sharing an md5sum must group together');
        } finally {
            $this->conn->rollBack();
        }
    }

    /**
     * `GROUP BY` puts all NULLs in one group, while `=` is false for NULL --
     * so a self-join rewrite would silently drop NULL-keyed groups. This
     * pins that they are still detected.
     */
    public function testFindIdsGroupedByDuplicateFieldsGroupsNullValuesTogether(): void
    {
        $this->conn->beginTransaction();

        try {
            $ids = [];
            foreach (['null-dupe-a', 'null-dupe-b'] as $index => $slug) {
                $this->conn->executeStatement(
                    "INSERT INTO images (file, date_available, path, level, width, height, date_creation)
                     VALUES (:file, '2026-08-01 00:00:00', :path, 0, 200, 150, NULL)",
                    [
                        'file' => $slug . '.jpg',
                        'path' => 'upload/' . $slug . '.jpg',
                    ],
                );
                $ids[] = (int) $this->conn->lastInsertId();
            }

            $found = $this->repo->findIdsGroupedByDuplicateFields([ImageDuplicateField::DateCreation]);

            foreach ($ids as $id) {
                self::assertContains($id, $found, 'rows sharing a NULL date_creation must group together');
            }
        } finally {
            $this->conn->rollBack();
        }
    }
}
