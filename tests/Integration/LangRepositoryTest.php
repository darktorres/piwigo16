<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\LangRepository;
use Piwigo\Lang\LanguageEntity;
use Piwigo\Lang\Projection\LanguageListing;

/**
 * Piwigo\Lang\LangRepository -- had no dedicated test file; only
 * ever exercised indirectly through LangService::getLanguages().
 */
final class LangRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private LangRepository $repo;

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

        $this->conn = DbConnection::build();
        $repo = EntityManagerFactory::build($this->conn)->getRepository(LanguageEntity::class);
        $this->repo = $repo;
    }

    public function testFindAllRowsReturnsTheFixturesInstalledLanguage(): void
    {
        $rows = $this->repo->findAllRows();

        self::assertEquals([new LanguageListing('en_UK', 'English (Great Britain)')], $rows);
    }

    public function testFindAllRowsExcludesARowWithANullName(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO languages' . " (id, version, name) VALUES ('zz_NM', '1.0.0', NULL)"
        );

        try {
            $rows = $this->repo->findAllRows();

            self::assertEquals([new LanguageListing('en_UK', 'English (Great Britain)')], $rows);
        } finally {
            $this->conn->executeStatement('DELETE FROM languages' . " WHERE id = 'zz_NM'");
        }
    }

    public function testFindAllRowsOrdersByName(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO languages' . " (id, version, name) VALUES ('zz_AA', '1.0.0', 'AAA First')"
        );

        try {
            $rows = $this->repo->findAllRows();

            self::assertEquals(
                [new LanguageListing('zz_AA', 'AAA First'), new LanguageListing('en_UK', 'English (Great Britain)')],
                $rows
            );
        } finally {
            $this->conn->executeStatement('DELETE FROM languages' . " WHERE id = 'zz_AA'");
        }
    }
}
