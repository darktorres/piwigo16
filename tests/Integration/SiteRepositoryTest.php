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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->repo = new SiteRepository(DbConnection::build());
    }

    #[\Override]
    protected function tearDown(): void
    {
        // SiteRepository has no delete method (out of P17's scope --
        // admin/site_manager.php's delete action stays on delete_site(),
        // a raw-query function not migrated this phase), so tests that
        // insert() clean up directly against the DB.
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

    public function test_find_all_includes_the_seeded_local_site(): void
    {
        $rows = $this->repo->findAll();

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

        $urls = array_column($this->repo->findAll(), 'galleriesUrl');

        self::assertContains($url, $urls);
    }
}
