<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Calendar\CalendarRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;

final class CalendarRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CalendarRepository $repo;

    private Connection $conn;

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

        $this->conn = DbConnection::build();
        $this->repo = new CalendarRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn));
    }

    public function test_find_image_ids_returns_matching_ids_in_order(): void
    {
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id IN (3, 1, 2)'),
            new SqlCondition(''),
            'ORDER BY id ASC'
        );

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_image_ids_returns_empty_for_no_match(): void
    {
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id = 999999'),
            new SqlCondition(''),
            ''
        );

        self::assertSame([], $ids);
    }

    public function test_find_image_ids_orders_by_a_column_outside_the_select_list(): void
    {
        // Matches the real production shape ($conf['order_by']'s default
        // is 'ORDER BY date_available DESC, file ASC, id ASC') -- proves
        // the query is valid under ONLY_FULL_GROUP_BY (GROUP BY id, not
        // SELECT DISTINCT id, which has no functional-dependency exception
        // for ORDER BY columns not in the SELECT list). A real regression
        // here previously 500'd every live calendar page.
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id IN (1, 2, 3)'),
            new SqlCondition(''),
            'ORDER BY date_available DESC, file ASC, id ASC'
        );

        sort($ids);
        self::assertSame([1, 2, 3], $ids);
    }

    public function test_find_image_ids_deduplicates_rows_from_a_join(): void
    {
        // Real production inner_sql always INNER JOINs image_category
        // (one row per category an image belongs to) -- GROUP BY id must
        // still collapse that back down to one id per image.
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id = image_id WHERE category_id IN (1, 2)'),
            new SqlCondition(''),
            'ORDER BY id ASC'
        );

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_find_image_ids_applies_the_date_where_continuation(): void
    {
        // get_date_where() (despite its name) returns a WHERE-clause
        // *continuation* fragment ("AND (...)"), meant to land right after
        // $fromWhereSql's own WHERE and before GROUP BY -- a real
        // regression once concatenated it after GROUP BY/next to ORDER BY
        // instead, producing invalid SQL for every live calendar page.
        // The fixture seeds every image at the same uniform date_available
        // (2026-08-01 00:00:00, matching PIWIGO_TEST_NOW) -- image 3's is
        // pushed a day later here, scoped to this test only, so images 1
        // and 2 are the only ones matching the filter below.
        $this->conn->executeStatement(
            "UPDATE " . Tables::images() . " SET date_available = '2026-08-02 00:00:00' WHERE id = 3"
        );

        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id IN (1, 2, 3)'),
            new SqlCondition("AND (date_available = '2026-08-01 00:00:00')"),
            'ORDER BY id ASC'
        );

        self::assertSame([1, 2], $ids);
    }
}
