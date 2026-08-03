<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Site\SiteRepository;

final class SiteRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SiteRepository $repo;

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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->repo = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Site\SiteEntity::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // delete()'s own test cleans up its own row directly (it IS the
        // thing under test) -- every other test here still inserts and
        // never deletes, so this catch-all stays for them.
        $db = $this->newMysqli($this->dbName);
        $db->query(sprintf("DELETE FROM `%ssites` WHERE galleries_url LIKE 'p17-test-%%'", $this->dbPrefix));
        $db->close();

        parent::tearDown();
    }

    public function test_count_by_url_returns_zero_when_unused(): void
    {
        self::assertSame(0, $this->repo->countByUrl('p17-test-does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function test_insert_then_count_by_url_round_trips(): void
    {
        $url = 'p17-test-' . bin2hex(random_bytes(4));

        $this->repo->insert($url);

        self::assertSame(1, $this->repo->countByUrl($url));
    }

    public function test_find_galleries_url_by_id_returns_the_inserted_url(): void
    {
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);

        $id = $this->queryScalar(sprintf("SELECT id FROM `%ssites` WHERE galleries_url = '%s'", $this->dbPrefix, $url));

        self::assertSame($url, $this->repo->findGalleriesUrlById((int) $id));
    }

    public function test_find_galleries_url_by_id_returns_null_when_unused(): void
    {
        self::assertNull($this->repo->findGalleriesUrlById(999_999));
    }

    public function test_delete_removes_the_row(): void
    {
        // Item 16E: real DQL replacement for CategoryRepository::
        // deleteSiteRow() (a real deptrac boundary -- Category is
        // L2aCoreDomain, Site is L2bExtendedDomain -- deleted the same
        // commit this method was added), had zero existing coverage.
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);
        $id = (int) $this->queryScalar(sprintf("SELECT id FROM `%ssites` WHERE galleries_url = '%s'", $this->dbPrefix, $url));

        $this->repo->delete($id);

        self::assertNull($this->repo->findGalleriesUrlById($id));
    }

    public function test_delete_on_an_unknown_id_is_a_silent_noop(): void
    {
        $this->expectNotToPerformAssertions();

        $this->repo->delete(999_999);
    }

    public function test_find_all_galleries_urls_returns_the_id_to_url_map(): void
    {
        // Item 16F: real DQL replacement for CategoryRepository::
        // findSiteGalleriesUrls() (a real deptrac boundary -- Category is
        // L2aCoreDomain, Site is L2bExtendedDomain -- deleted the same
        // commit this method was added).
        self::assertSame(
            [1 => \Piwigo\Core\CurrentPaths::get()->root . 'galleries/'],
            $this->repo->findAllGalleriesUrls()
        );
    }

    public function test_find_galleries_url_for_category_returns_null_when_the_category_has_no_linked_site(): void
    {
        // Both fixture categories have site_id NULL -- the join predicate
        // is never satisfied against a NULL, so the query returns no row
        // and this exercises the false/null branch. Item 16F: real DQL
        // replacement for CategoryRepository::findGalleriesUrlForCategory().
        self::assertNull($this->repo->findGalleriesUrlForCategory(1));
    }

    public function test_find_galleries_url_for_category_returns_the_joined_sites_row(): void
    {
        // Fixture has exactly one sites row (id 1); temporarily point
        // category 1 at it.
        $db = $this->newMysqli($this->dbName);
        $db->query(sprintf('UPDATE `%1$scategories` SET site_id = 1 WHERE id = 1', $this->dbPrefix));

        try {
            self::assertSame(
                \Piwigo\Core\CurrentPaths::get()->root . 'galleries/',
                $this->repo->findGalleriesUrlForCategory(1)
            );
        } finally {
            $db->query(sprintf('UPDATE `%1$scategories` SET site_id = NULL WHERE id = 1', $this->dbPrefix));
            $db->close();
        }
    }

    public function test_find_all_includes_the_seeded_local_site(): void
    {
        $rows = $this->repo->findAllSites();

        self::assertNotSame([], $rows);
        $urls = array_column($rows, 'galleriesUrl');
        // InstallWizard::install() stores an absolute filesystem path
        // (Piwigo\Core\Paths::$root . 'galleries/'), not a portable literal
        // './galleries/' -- machine-specific, so computed here the same way
        // install itself computes it, rather than a hardcoded string.
        self::assertContains(\Piwigo\Core\CurrentPaths::get()->root . 'galleries/', $urls);
    }

    public function test_find_all_includes_a_newly_inserted_site(): void
    {
        $url = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->insert($url);

        $urls = array_column($this->repo->findAllSites(), 'galleriesUrl');

        self::assertContains($url, $urls);
    }

    public function test_find_category_and_image_counts_by_site_groups_by_site_and_ignores_categories_with_no_site(): void
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
        $siteId = (int) $this->queryScalar(sprintf("SELECT id FROM `%ssites` WHERE galleries_url = '%s'", $this->dbPrefix, $url));

        $db = $this->newMysqli($this->dbName);
        $db->query(sprintf(
            "INSERT INTO `%1\$scategories` (name, site_id, uppercats) VALUES ('p17-test-site-cat-with-image', %2\$d, '999901')",
            $this->dbPrefix,
            $siteId
        ));
        $catWithImageId = (int) $db->insert_id;
        $db->query(sprintf(
            "INSERT INTO `%1\$scategories` (name, site_id, uppercats) VALUES ('p17-test-site-cat-without-image', %2\$d, '999902')",
            $this->dbPrefix,
            $siteId
        ));
        $catWithoutImageId = (int) $db->insert_id;
        $db->query(sprintf(
            "INSERT INTO `%1\$simages` (file, path, storage_category_id) VALUES ('p17-test-site.jpg', 'p17-test-site.jpg', %2\$d)",
            $this->dbPrefix,
            $catWithImageId
        ));
        $imageId = (int) $db->insert_id;

        try {
            $counts = $this->repo->findCategoryAndImageCountsBySite();

            self::assertArrayHasKey($siteId, $counts);
            self::assertSame(['nb_categories' => 2, 'nb_images' => 1], $counts[$siteId]);
        } finally {
            $db->query(sprintf('DELETE FROM `%1$simages` WHERE id = %2$d', $this->dbPrefix, $imageId));
            $db->query(sprintf('DELETE FROM `%1$scategories` WHERE id IN (%2$d, %3$d)', $this->dbPrefix, $catWithImageId, $catWithoutImageId));
            $db->close();
        }
    }
}
