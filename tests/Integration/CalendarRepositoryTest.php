<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Calendar\CalendarQueryScope;
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
        $this->repo = new CalendarRepository(EntityManagerFactory::build($this->conn));
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
        // for ORDER BY columns not in the SELECT list). A regression here
        // breaks every live calendar page.
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
        // *continuation* fragment ("AND (...)"), which must land right
        // after $fromWhereSql's own WHERE and before GROUP BY --
        // concatenating it after GROUP BY or next to ORDER BY instead
        // produces invalid SQL for every live calendar page.
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

    /**
     * Passing the DQL-shaped $dqlScope/$dqlDateWhere counterparts attempts
     * real DQL when $orderBySql parses.
     */
    public function test_find_image_ids_runs_dql_when_dql_scope_and_order_by_are_given(): void
    {
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id IN (1, 2, 3)'),
            new SqlCondition(''),
            'ORDER BY id DESC',
            new CalendarQueryScope(
                new SqlCondition(''),
                false,
                new SqlCondition('i.id IN (:ids)', ['ids' => [1, 2, 3]], ['ids' => ArrayParameterType::INTEGER])
            ),
            new SqlCondition('')
        );

        self::assertSame([3, 2, 1], $ids);
    }

    /**
     * Mirrors CalendarRenderer::render()'s own real, non-superOrderBy
     * composition: $calendar->date_field prepended ahead of
     * CurrentConfig::orderBy()'s own entries -- confirms
     * PhotoSortField::resolveDqlOrderBy() parses that dynamically-composed
     * string just as well as a plain config value.
     */
    public function test_find_image_ids_runs_dql_for_a_date_field_prepended_order(): void
    {
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id IN (1, 2, 3)'),
            new SqlCondition(''),
            'ORDER BY id DESC, file ASC',
            new CalendarQueryScope(
                new SqlCondition(''),
                false,
                new SqlCondition('i.id IN (:ids)', ['ids' => [1, 2, 3]], ['ids' => ArrayParameterType::INTEGER])
            ),
            new SqlCondition('')
        );

        self::assertSame([3, 2, 1], $ids);
    }

    public function test_find_image_ids_falls_back_to_raw_dbal_when_dql_scope_is_given_but_order_by_does_not_parse(): void
    {
        // $orderBySql doesn't match the bounded $sort_fields vocabulary --
        // must still return the right members via the raw-DBAL fallback,
        // not throw, even though a $dqlScope is given.
        $ids = $this->repo->findImageIds(
            new SqlCondition(' FROM ' . Tables::images() . ' WHERE id IN (1, 2, 3)'),
            new SqlCondition(''),
            'ORDER BY RAND()',
            new CalendarQueryScope(
                new SqlCondition(''),
                false,
                new SqlCondition('i.id IN (:ids)', ['ids' => [1, 2, 3]], ['ids' => ArrayParameterType::INTEGER])
            ),
            new SqlCondition('')
        );
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }
}
