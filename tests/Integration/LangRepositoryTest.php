<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Lang\LangRepository;
use Piwigo\Lang\LanguageEntity;

/**
 * Piwigo\Lang\LangRepository -- had no dedicated test file (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1); only
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
        self::assertInstanceOf(LangRepository::class, $repo);
        $this->repo = $repo;
    }

    public function test_find_all_rows_returns_the_fixtures_installed_language(): void
    {
        $rows = $this->repo->findAllRows();

        self::assertSame([['id' => 'en_UK', 'name' => 'English (Great Britain)']], $rows);
    }

    public function test_find_all_rows_excludes_a_row_with_a_null_name(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::languages() . " (id, version, name) VALUES ('ct_no_name', '1.0.0', NULL)"
        );

        try {
            $rows = $this->repo->findAllRows();

            self::assertSame([['id' => 'en_UK', 'name' => 'English (Great Britain)']], $rows);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::languages() . " WHERE id = 'ct_no_name'");
        }
    }

    public function test_find_all_rows_orders_by_name(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::languages() . " (id, version, name) VALUES ('ct_aaa', '1.0.0', 'AAA First')"
        );

        try {
            $rows = $this->repo->findAllRows();

            self::assertSame(
                [['id' => 'ct_aaa', 'name' => 'AAA First'], ['id' => 'en_UK', 'name' => 'English (Great Britain)']],
                $rows
            );
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::languages() . " WHERE id = 'ct_aaa'");
        }
    }
}
