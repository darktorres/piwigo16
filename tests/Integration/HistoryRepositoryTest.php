<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;

/**
 * `history`/`history_summary` are both empty in the fixture, so every test
 * inserts its own rows and cleans them up via try/finally -- same
 * isolation discipline as CaddieRepositoryTest, needed here too since
 * tests within this class would otherwise share mutable state.
 */
final class HistoryRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private HistoryRepository $repo;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new HistoryRepository($this->conn);
    }

    public function test_find_last_summary_with_history_id_to_returns_null_when_empty(): void
    {
        self::assertNull($this->repo->findLastSummaryWithHistoryIdTo());
    }

    public function test_find_last_summary_with_history_id_to_returns_the_highest(): void
    {
        $this->insertSummary(2026, 7, 12, 3, 10, 1, 100);
        $this->insertSummary(2026, 7, 12, 4, 5, 101, 200);

        try {
            $found = $this->repo->findLastSummaryWithHistoryIdTo();

            self::assertNotNull($found);
            self::assertSame(200, $found->historyIdTo);
            self::assertSame(4, $found->hour);
        } finally {
            $this->clearSummary();
        }
    }

    public function test_find_min_history_id_returns_null_when_empty(): void
    {
        self::assertNull($this->repo->findMinHistoryId());
    }

    public function test_find_min_history_id_returns_the_lowest(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        try {
            self::assertSame($this->minHistoryId(), $this->repo->findMinHistoryId());
        } finally {
            $this->clearHistory();
        }
    }

    public function test_find_grouped_counts_since_buckets_by_date_and_hour(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:10:00');
        $this->insertHistoryLine(1, '2026-07-12', '03:50:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        try {
            $groups = $this->repo->findGroupedCountsSince(0, null);

            self::assertCount(2, $groups);
            self::assertSame('2026-07-12', $groups[0]['date']);
            self::assertSame(3, $groups[0]['hour']);
            self::assertSame(2, $groups[0]['nbPages']);
            self::assertSame(4, $groups[1]['hour']);
            self::assertSame(1, $groups[1]['nbPages']);
        } finally {
            $this->clearHistory();
        }
    }

    public function test_find_grouped_counts_since_respects_the_max_id(): void
    {
        $id1 = $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        try {
            $groups = $this->repo->findGroupedCountsSince(0, $id1);

            self::assertCount(1, $groups);
            self::assertSame(3, $groups[0]['hour']);
        } finally {
            $this->clearHistory();
        }
    }

    public function test_find_summary_rows_for_hierarchy_returns_every_existing_level(): void
    {
        $this->insertSummary(2026, null, null, null, 100, 1, 50); // year-only
        $this->insertSummary(2026, 7, null, null, 40, 1, 30); // year+month
        $this->insertSummary(2026, 8, null, null, 10, 40, 50); // different month -- should not match

        try {
            $rows = $this->repo->findSummaryRowsForHierarchy(2026, 7, 12, 3);

            $keys = array_map(
                static fn (\Piwigo\History\Projection\HistorySummaryCount $r): string => $r->year . '-' . ($r->month ?? 'x') . '-' . ($r->day ?? 'x') . '-' . ($r->hour ?? 'x'),
                $rows
            );
            sort($keys);

            self::assertSame(['2026-7-x-x', '2026-x-x-x'], $keys);
        } finally {
            $this->clearSummary();
        }
    }

    public function test_update_summary_rows_updates_the_matching_null_inclusive_key(): void
    {
        $this->insertSummary(2026, null, null, null, 100, 1, 50);

        try {
            $this->repo->updateSummaryRows([
                ['year' => 2026, 'month' => null, 'day' => null, 'hour' => null, 'nbPages' => 150, 'historyIdTo' => 80],
            ]);

            $row = $this->fetchSummary(2026, null, null, null);
            self::assertSame(150, $row['nb_pages']);
            self::assertSame(80, $row['history_id_to']);
        } finally {
            $this->clearSummary();
        }
    }

    public function test_insert_summary_rows_inserts_new_rows(): void
    {
        try {
            $this->repo->insertSummaryRows([
                ['year' => 2026, 'month' => 7, 'day' => 12, 'hour' => 3, 'nbPages' => 5, 'historyIdFrom' => 1, 'historyIdTo' => 5],
            ]);

            $row = $this->fetchSummary(2026, 7, 12, 3);
            self::assertSame(5, $row['nb_pages']);
        } finally {
            $this->clearSummary();
        }
    }

    public function test_count_all_and_latest_and_oldest_and_delete_before(): void
    {
        $id1 = $this->insertHistoryLine(1, '2026-07-10', '03:00:00');
        $id2 = $this->insertHistoryLine(1, '2026-07-11', '03:00:00');
        $id3 = $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        try {
            self::assertSame(3, $this->repo->countAll());
            self::assertSame($id3, $this->repo->findLatestHistoryId());
            self::assertSame($id1, $this->repo->findOldestHistoryId());

            $this->repo->deleteBefore($id3);

            self::assertSame(1, $this->repo->countAll());
            self::assertSame($id3, $this->repo->findOldestHistoryId());
        } finally {
            $this->clearHistory();
        }
    }

    public function test_find_image_ids_by_filename(): void
    {
        $ids = $this->repo->findImageIdsByFilename('fixture-photo-1%');

        self::assertSame([1], $ids);
    }

    public function test_search_filters_by_user_id(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(3, '2026-07-12', '03:00:00');

        try {
            $rows = $this->repo->search(null, null, null, [], 1, null, null, null);

            self::assertCount(1, $rows);
            self::assertSame(1, $rows[0]['user_id']);
        } finally {
            $this->clearHistory();
        }
    }

    public function test_search_with_no_criteria_returns_everything(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(3, '2026-07-12', '03:00:00');

        try {
            self::assertCount(2, $this->repo->search(null, null, null, [], null, null, null, null));
        } finally {
            $this->clearHistory();
        }
    }

    public function test_search_with_an_unmatched_filename_returns_nothing(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        try {
            self::assertSame([], $this->repo->search(null, null, null, [], null, null, [], null));
        } finally {
            $this->clearHistory();
        }
    }

    private function insertHistoryLine(int $userId, string $date, string $time): int
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::history())
            ->values([
                'date' => ':date',
                'time' => ':time',
                'user_id' => ':userId',
                'IP' => "'127.0.0.1'",
            ])
            ->setParameter('date', $date)
            ->setParameter('time', $time)
            ->setParameter('userId', $userId)
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    private function minHistoryId(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('MIN(id)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function insertSummary(int $year, ?int $month, ?int $day, ?int $hour, int $nbPages, int $idFrom, int $idTo): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::historySummary())
            ->values([
                'year' => ':year',
                'month' => ':month',
                'day' => ':day',
                'hour' => ':hour',
                'nb_pages' => ':nbPages',
                'history_id_from' => ':idFrom',
                'history_id_to' => ':idTo',
            ])
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('day', $day)
            ->setParameter('hour', $hour)
            ->setParameter('nbPages', $nbPages)
            ->setParameter('idFrom', $idFrom)
            ->setParameter('idTo', $idTo)
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSummary(int $year, ?int $month, ?int $day, ?int $hour): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::historySummary())
            ->where('year = :year')
            ->setParameter('year', $year);
        $qb->andWhere($month === null ? 'month IS NULL' : 'month = ' . $month);
        $qb->andWhere($day === null ? 'day IS NULL' : 'day = ' . $day);
        $qb->andWhere($hour === null ? 'hour IS NULL' : 'hour = ' . $hour);

        $row = $qb->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function clearHistory(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::history());
    }

    private function clearSummary(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::historySummary());
    }
}
