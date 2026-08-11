<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Metadata\MetadataRepository;

/**
 * Fixture shape: images 1-5 (real paths, no representative_ext); category
 * 1 "Sample Album" (root), category 2 "Nested Sub Album" (child of 1).
 * Both categories' site_id/dir are NULL in the stock fixture -- tests
 * needing findCategoryIds()/findImagesByStorageCategoryIds() to actually
 * match set them temporarily (cleaned up in tearDown).
 */
final class MetadataRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private MetadataRepository $repo;

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
        $this->repo = new MetadataRepository(EntityManagerFactory::build($this->conn));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE categories SET site_id = NULL, dir = NULL');
        $this->conn->executeStatement('UPDATE images' . " SET storage_category_id = NULL, date_metadata_update = '2026-07-07'");
        parent::tearDown();
    }

    public function testFindImagesByIdsReturnsMatchingRows(): void
    {
        $rows = $this->repo->findImagesByIds([1, 3]);

        $ids = array_column($rows, 'id');
        sort($ids);
        self::assertSame([1, 3], $ids);
        // Asserts the stable date-based upload path, not the filename's
        // opaque content-hash suffix (regenerated whenever the fixture SQL
        // itself is regenerated, unlike the rest of this row's data).
        // images.path is stored root-relative with no leading slash (see
        // UploadService::addUploadedFile()'s own preg_replace() against
        // Paths::$root) -- this assertion's leading '/' never matched
        // anything, pre-existing and unrelated to the sibling double-slash
        // bug (CurrentConfig::uploadDir() already ending in '/') fixed alongside
        // this same investigation.
        self::assertStringContainsString('upload/2026/08/01/', $rows[0]->path . $rows[1]->path);
    }

    public function testFindImagesByIdsReturnsEmptyForNoIds(): void
    {
        self::assertSame([], $this->repo->findImagesByIds([]));
    }

    public function testFindCategoryIdsMatchesASpecificCategory(): void
    {
        $this->conn->executeStatement('UPDATE categories' . " SET site_id = 1, dir = 'sample_album' WHERE id = 1");
        $this->conn->executeStatement('UPDATE categories' . " SET site_id = 1, dir = 'nested' WHERE id = 2");

        $ids = $this->repo->findCategoryIds(1, 1, false);

        self::assertSame([1], $ids);
    }

    public function testFindCategoryIdsRecursiveIncludesSubcategories(): void
    {
        $this->conn->executeStatement('UPDATE categories' . " SET site_id = 1, dir = 'sample_album', uppercats = '1' WHERE id = 1");
        $this->conn->executeStatement('UPDATE categories' . " SET site_id = 1, dir = 'nested', uppercats = '1,2' WHERE id = 2");

        $ids = $this->repo->findCategoryIds(1, 1, true);
        sort($ids);

        self::assertSame([1, 2], $ids);
    }

    public function testFindCategoryIdsReturnsEmptyWhenDirIsNull(): void
    {
        // stock fixture: dir is NULL for every category.
        self::assertSame([], $this->repo->findCategoryIds(1, '', false));
    }

    public function testFindImagesByStorageCategoryIdsKeysById(): void
    {
        $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id IN (1, 2, 3)');
        $this->conn->executeStatement('UPDATE images SET storage_category_id = 2 WHERE id IN (4, 5)');

        $result = $this->repo->findImagesByStorageCategoryIds([1], false);

        self::assertSame([1, 2, 3], array_keys($result));
        self::assertSame(1, $result[1]->id);
    }

    public function testFindImagesByStorageCategoryIdsFiltersOnlyNew(): void
    {
        // The fixture's own default already sets date_metadata_update to a
        // real date for every image -- null out 2/3 to simulate "not yet
        // synced", leaving image 1 as "already synced".
        $this->conn->executeStatement('UPDATE images SET storage_category_id = 1 WHERE id IN (1, 2, 3)');
        $this->conn->executeStatement('UPDATE images SET date_metadata_update = NULL WHERE id IN (2, 3)');

        $result = $this->repo->findImagesByStorageCategoryIds([1], true);

        self::assertSame([2, 3], array_keys($result));
    }

    public function testFindImagesByStorageCategoryIdsReturnsEmptyForNoCategories(): void
    {
        self::assertSame([], $this->repo->findImagesByStorageCategoryIds([], false));
    }
}
