<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Site\SiteRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see SiteRepository}. The fixture seeds
 * one site (id 1, galleries_url='./galleries/').
 */
final class SiteRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private SiteRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new SiteRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findGalleriesUrlById_returns_seeded_site(): void
    {
        $url = $this->repo->findGalleriesUrlById(1);
        self::assertIsString($url);
        self::assertStringContainsString('galleries', $url);
    }

    public function test_findGalleriesUrlById_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findGalleriesUrlById(9999));
    }

    public function test_insert_round_trips(): void
    {
        $newId = $this->repo->insert('http://remote.example/gallery/');

        self::assertGreaterThan(1, $newId);
        self::assertSame('http://remote.example/gallery/', $this->repo->findGalleriesUrlById($newId));
    }

    public function test_countByUrl_and_findIdToGalleriesUrlMap(): void
    {
        $this->repo->insert('http://second.example/');

        self::assertSame(1, $this->repo->countByUrl('http://second.example/'));
        self::assertSame(0, $this->repo->countByUrl('http://missing.example/'));

        $map = $this->repo->findIdToGalleriesUrlMap();
        self::assertGreaterThanOrEqual(2, count($map));
    }
}
