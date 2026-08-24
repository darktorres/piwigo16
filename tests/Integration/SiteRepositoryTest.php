<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Site\Projection\SiteCategoryImageCounts;
use Piwigo\Site\SiteEntity;
use Piwigo\Site\SiteRepository;
use Piwigo\Tests\Support\CurrentPathsTestFactory;

final class SiteRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SiteRepository $repo;

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

        $this->repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(SiteEntity::class), SiteRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        // delete()'s own test cleans up its own row directly (it IS the
        // thing under test) -- every other test here still inserts and
        // never deletes, so this catch-all stays for them.
        DbConnection::build()->executeStatement(
            "DELETE FROM sites WHERE galleries_url LIKE 'p17-test-%'"
        );

        parent::tearDown();
    }

    public function testCountByUrlReturnsZeroWhenUnused(): void
    {
        self::assertSame(0, $this->repo->countByUrl('p17-test-does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function testInsertThenCountByUrlRoundTrips(): void
    {
        $url = 'p17-test-' . bin2hex(random_bytes(4));

        $this->repo->insert($url);

        self::assertSame(1, $this->repo->countByUrl($url));
    }

    public function testFindGalleriesUrlByIdReturnsTheInsertedUrl(): void
    {
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);

        $id = $this->queryScalar(sprintf("SELECT id FROM sites WHERE galleries_url = '%s'", $url));

        self::assertSame($url, $this->repo->findGalleriesUrlById((int) $id));
    }

    public function testFindGalleriesUrlByIdReturnsNullWhenUnused(): void
    {
        // sites.id is a real tinyint unsigned column (MySQL) / smallint
        // (Postgres) -- 999_999 overflows both, but only Postgres errors
        // on an out-of-range comparison literal ("value ... is out of
        // range for type smallint"); MySQL tolerates it via lenient
        // comparison (just matches nothing). 254 fits either column's
        // real range and the fixture only ever seeds site id 1, so it's
        // still a genuinely unused id.
        self::assertNull($this->repo->findGalleriesUrlById(254));
    }

    public function testDeleteRemovesTheRow(): void
    {
        // Real DQL replacement for the now-deleted CategoryRepository::
        // deleteSiteRow() (a real deptrac boundary -- Category is
        // L2aCoreDomain, Site is L2bExtendedDomain).
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);
        $id = (int) $this->queryScalar(sprintf("SELECT id FROM sites WHERE galleries_url = '%s'", $url));

        $this->repo->delete($id);

        self::assertNull($this->repo->findGalleriesUrlById($id));
    }

    public function testDeleteOnAnUnknownIdIsASilentNoop(): void
    {
        $this->expectNotToPerformAssertions();

        // See test_find_galleries_url_by_id_returns_null_when_unused()'s
        // own comment for why 254, not 999_999.
        $this->repo->delete(254);
    }

    public function testFindAllGalleriesUrlsReturnsTheIdToUrlMap(): void
    {
        // Real DQL replacement for the now-deleted CategoryRepository::
        // findSiteGalleriesUrls() (a real deptrac boundary -- Category is
        // L2aCoreDomain, Site is L2bExtendedDomain).
        self::assertSame(
            [
                1 => CurrentPathsTestFactory::get()->root . 'galleries/',
            ],
            $this->repo->findAllGalleriesUrls()
        );
    }

    public function testFindGalleriesUrlForCategoryReturnsNullWhenTheCategoryHasNoLinkedSite(): void
    {
        // Both fixture categories have site_id NULL -- the join predicate
        // is never satisfied against a NULL, so the query returns no row
        // and this exercises the false/null branch. Real DQL replacement
        // for the now-deleted CategoryRepository::findGalleriesUrlForCategory().
        self::assertNull($this->repo->findGalleriesUrlForCategory(1));
    }

    public function testFindGalleriesUrlForCategoryReturnsTheJoinedSitesRow(): void
    {
        // Fixture has exactly one sites row (id 1); temporarily point
        // category 1 at it.
        $conn = DbConnection::build();
        $conn->executeStatement('UPDATE categories SET site_id = 1 WHERE id = 1');

        try {
            self::assertSame(
                CurrentPathsTestFactory::get()->root . 'galleries/',
                $this->repo->findGalleriesUrlForCategory(1)
            );
        } finally {
            $conn->executeStatement('UPDATE categories SET site_id = NULL WHERE id = 1');
        }
    }

    public function testFindAllIncludesTheSeededLocalSite(): void
    {
        $rows = $this->repo->findAllSites();

        self::assertNotSame([], $rows);
        $urls = array_column($rows, 'galleriesUrl');
        // InstallWizard::install() stores an absolute filesystem path
        // (Piwigo\Core\Paths::$root . 'galleries/'), not a portable literal
        // './galleries/' -- machine-specific, so computed here the same way
        // install itself computes it, rather than a hardcoded string.
        self::assertContains(CurrentPathsTestFactory::get()->root . 'galleries/', $urls);
    }

    public function testFindAllIncludesANewlyInsertedSite(): void
    {
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);

        $urls = array_column($this->repo->findAllSites(), 'galleriesUrl');

        self::assertContains($url, $urls);
    }

    public function testFindCategoryAndImageCountsBySiteGroupsBySiteAndIgnoresCategoriesWithNoSite(): void
    {
        // Every real fixture category has site_id NULL (a single-site
        // install) -- 2 disposable categories (of this method's own real
        // "multi-site synced gallery" shape) are the only way to reach
        // this method's own real work at all. One gets a storage-synced
        // image (storage_category_id, distinct from the image_category
        // link table every other fixture image uses) so nb_images is
        // genuinely non-zero, not just structurally present.
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);
        $siteId = (int) $this->queryScalar(sprintf("SELECT id FROM sites WHERE galleries_url = '%s'", $url));

        $conn = DbConnection::build();
        $conn->executeStatement(sprintf(
            "INSERT INTO categories (name, site_id, uppercats) VALUES ('p17-test-site-cat-with-image', %d, '999901')",
            $siteId
        ));
        $catWithImageId = (int) $conn->lastInsertId();
        $conn->executeStatement(sprintf(
            "INSERT INTO categories (name, site_id, uppercats) VALUES ('p17-test-site-cat-without-image', %d, '999902')",
            $siteId
        ));
        $catWithoutImageId = (int) $conn->lastInsertId();
        $conn->executeStatement(sprintf(
            "INSERT INTO images (file, path, storage_category_id) VALUES ('p17-test-site.jpg', 'p17-test-site.jpg', %d)",
            $catWithImageId
        ));
        $imageId = (int) $conn->lastInsertId();

        try {
            $counts = $this->repo->findCategoryAndImageCountsBySite();

            self::assertArrayHasKey($siteId, $counts);
            self::assertEquals(new SiteCategoryImageCounts(categories: 2, images: 1), $counts[$siteId]);
        } finally {
            $conn->executeStatement(sprintf('DELETE FROM images WHERE id = %d', $imageId));
            $conn->executeStatement(sprintf('DELETE FROM categories WHERE id IN (%d, %d)', $catWithImageId, $catWithoutImageId));
        }
    }
}
