<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Theme\ThemeRepository;

/**
 * Real-DB integration tests for {@see ThemeRepository}. The fixture seeds
 * no theme rows; each test sets up its own.
 */
final class ThemeRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private ThemeRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new ThemeRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_activate_then_existsById(): void
    {
        $this->repo->activate('modus', '17.0.0', 'Modus');

        self::assertTrue($this->repo->existsById('modus'));
        self::assertFalse($this->repo->existsById('missing-theme'));
    }

    public function test_findIdNameMap_returns_activated_themes(): void
    {
        $this->repo->activate('modus', '17.0.0', 'Modus');
        $this->repo->activate('elegant', '2.4', 'Elegant');

        $map = $this->repo->findIdNameMap();

        self::assertSame('Modus', $map['modus']);
        self::assertSame('Elegant', $map['elegant']);
    }

    public function test_deactivate_removes_row(): void
    {
        $this->repo->activate('temp-theme', '1.0', 'Temp');
        self::assertTrue($this->repo->existsById('temp-theme'));

        $this->repo->deactivate('temp-theme');

        self::assertFalse($this->repo->existsById('temp-theme'));
    }

    public function test_findAnyOtherThemeId_returns_alternative(): void
    {
        $this->repo->activate('modus', '17.0.0', 'Modus');
        $this->repo->activate('elegant', '2.4', 'Elegant');

        self::assertSame('elegant', $this->repo->findAnyOtherThemeId('modus'));
        self::assertSame('modus', $this->repo->findAnyOtherThemeId('elegant'));
    }

    public function test_findAnyOtherThemeId_returns_null_when_only_one(): void
    {
        $this->repo->activate('modus', '17.0.0', 'Modus');

        self::assertNull($this->repo->findAnyOtherThemeId('modus'));
    }
}
