<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryRepository;
use Piwigo\History\Projection\HistorySummaryCount;

/**
 * `history`/`history_summary` are both empty in the fixture, so every test
 * inserts its own rows and cleans them up via try/finally -- same
 * isolation discipline as CaddieRepositoryTest, needed here too since
 * tests within this class would otherwise share mutable state.
 *
 * getSectionEnumOptions()'s own "column not found" `return [];` fallback
 * is unreachable through any real call -- `DESC history` always finds the
 * real `section` column on this project's actual (Doctrine-Migrations-
 * created) schema. Not worth a forced test; see HistoryServiceTest's own
 * identical-shaped note for its 2 unreachable branches.
 * getSectionEnumOptions()'s "found" path and alterSectionEnum() (the real
 * ALTER TABLE) are both exercised by HistoryServiceTest::
 * test_log_visit_widens_the_section_enum_for_a_brand_new_section() instead
 * of here, since that's the one real call site that needs both together.
 */
final class HistoryRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private HistoryRepository $repo;

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
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(HistoryEntity::class);
    }

    public function testFindLastSummaryWithHistoryIdToReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->repo->findLastSummaryWithHistoryIdTo());
    }

    public function testFindLastSummaryWithHistoryIdToReturnsTheHighest(): void
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

    public function testFindMinHistoryIdReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->repo->findMinHistoryId());
    }

    public function testFindMinHistoryIdReturnsTheLowest(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        try {
            self::assertSame($this->minHistoryId(), $this->repo->findMinHistoryId());
        } finally {
            $this->clearHistory();
        }
    }

    public function testFindGroupedCountsSinceBucketsByDateAndHour(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:10:00');
        $this->insertHistoryLine(1, '2026-07-12', '03:50:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        try {
            $groups = $this->repo->findGroupedCountsSince(0, null);

            self::assertCount(2, $groups);
            self::assertSame('2026-07-12', $groups[0]->date);
            self::assertSame(3, $groups[0]->hour);
            self::assertSame(2, $groups[0]->nbPages);
            self::assertSame(4, $groups[1]->hour);
            self::assertSame(1, $groups[1]->nbPages);
        } finally {
            $this->clearHistory();
        }
    }

    public function testFindGroupedCountsSinceRespectsTheMaxId(): void
    {
        $id1 = $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        try {
            $groups = $this->repo->findGroupedCountsSince(0, $id1);

            self::assertCount(1, $groups);
            self::assertSame(3, $groups[0]->hour);
        } finally {
            $this->clearHistory();
        }
    }

    public function testFindSummaryRowsForHierarchyReturnsEveryExistingLevel(): void
    {
        $this->insertSummary(2026, null, null, null, 100, 1, 50); // year-only
        $this->insertSummary(2026, 7, null, null, 40, 1, 30); // year+month
        $this->insertSummary(2026, 8, null, null, 10, 40, 50); // different month -- should not match

        try {
            $rows = $this->repo->findSummaryRowsForHierarchy(2026, 7, 12, 3);

            $keys = array_map(
                static fn (HistorySummaryCount $r): string => $r->year . '-' . ($r->month ?? 'x') . '-' . ($r->day ?? 'x') . '-' . ($r->hour ?? 'x'),
                $rows
            );
            sort($keys);

            self::assertSame(['2026-7-x-x', '2026-x-x-x'], $keys);
        } finally {
            $this->clearSummary();
        }
    }

    public function testUpdateSummaryRowsUpdatesTheMatchingNullInclusiveKey(): void
    {
        $this->insertSummary(2026, null, null, null, 100, 1, 50);

        try {
            $this->repo->updateSummaryRows([
                [
                    'year' => 2026,
                    'month' => null,
                    'day' => null,
                    'hour' => null,
                    'nbPages' => 150,
                    'historyIdTo' => 80,
                ],
            ]);

            $row = $this->fetchSummary(2026, null, null, null);
            self::assertSame(150, $row['nb_pages']);
            self::assertSame(80, $row['history_id_to']);
        } finally {
            $this->clearSummary();
        }
    }

    public function testInsertSummaryRowsInsertsNewRows(): void
    {
        try {
            $this->repo->insertSummaryRows([
                [
                    'year' => 2026,
                    'month' => 7,
                    'day' => 12,
                    'hour' => 3,
                    'nbPages' => 5,
                    'historyIdFrom' => 1,
                    'historyIdTo' => 5,
                ],
            ]);

            $row = $this->fetchSummary(2026, 7, 12, 3);
            self::assertSame(5, $row['nb_pages']);
        } finally {
            $this->clearSummary();
        }
    }

    public function testSumPageViewsSumsOnlyYearOnlyRows(): void
    {
        // month IS NULL is summarize()'s own "whole year" rollup row --
        // the month-level row below must not also get counted, or the
        // sum would double.
        $this->insertSummary(2026, null, null, null, 100, 1, 50);
        $this->insertSummary(2027, null, null, null, 25, 51, 60);
        $this->insertSummary(2026, 7, null, null, 999, 1, 30);

        try {
            self::assertSame(125, $this->repo->sumPageViews());
        } finally {
            $this->clearSummary();
        }
    }

    public function testCountAllAndLatestAndOldestAndDeleteBefore(): void
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

    public function testFindImageIdsByFilename(): void
    {
        $ids = $this->repo->findImageIdsByFilename('fixture-photo-1%');

        self::assertSame([1], $ids);
    }

    public function testSearchFiltersByUserId(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(3, '2026-07-12', '03:00:00');

        try {
            $rows = $this->repo->search(null, null, null, [], 1, null, null, null);

            self::assertCount(1, $rows);
            self::assertSame(1, $rows[0]->userId);
        } finally {
            $this->clearHistory();
        }
    }

    public function testSearchWithNoCriteriaReturnsEverything(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(3, '2026-07-12', '03:00:00');

        try {
            self::assertCount(2, $this->repo->search(null, null, null, [], null, null, null, null));
        } finally {
            $this->clearHistory();
        }
    }

    public function testSearchWithAnUnmatchedFilenameReturnsNothing(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        try {
            self::assertSame([], $this->repo->search(null, null, null, [], null, null, [], null));
        } finally {
            $this->clearHistory();
        }
    }

    /**
     * h.ip is now ip_address_graceful-Typed -- a raw LIKE pattern (not a
     * full valid IpAddress) bound against it must bypass that Type's own
     * convertToDatabaseValue() (which only accepts null|IpAddress), or
     * this throws instead of matching.
     */
    public function testSearchFiltersByIpLikePattern(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        try {
            $rows = $this->repo->search(null, null, null, [], null, null, null, '127.%');

            self::assertCount(1, $rows);
            self::assertSame('127.0.0.1', $rows[0]->ip);
        } finally {
            $this->clearHistory();
        }
    }

    /**
     * findLastByType()/findMonthlyRows()/findDailyRowsForMonths()/
     * findAverageDailyPageViewsSince() are covered directly here --
     * Admin\StatsPageRenderer is their one real caller, and its own
     * Browser test (tests/Browser/StatsPageRendererTest.php) can't run
     * in this worktree (Playwright isn't installed here).
     */
    public function testFindLastByTypeFiltersAndOrdersPerHierarchyLevel(): void
    {
        $this->insertSummary(2025, 6, 10, 2, 5, 1, 10);
        $this->insertSummary(2026, 7, 12, 3, 20, 11, 30);
        $this->insertSummary(2026, 7, 12, 4, 15, 31, 40);
        $this->insertSummary(2026, null, null, null, 35, 1, 40); // month IS NULL "whole year" row

        try {
            $hours = $this->repo->findLastByType('hour', 10);
            self::assertCount(3, $hours);
            self::assertSame(4, $hours[0]->hour);
            self::assertSame(3, $hours[1]->hour);
            self::assertSame(2, $hours[2]->hour);

            $limited = $this->repo->findLastByType('hour', 1);
            self::assertCount(1, $limited);

            $default = $this->repo->findLastByType('year', 10);
            self::assertCount(1, $default);
            self::assertSame(2026, $default[0]->year);
            self::assertNull($default[0]->month);
        } finally {
            $this->clearSummary();
        }
    }

    public function testFindMonthlyRowsReturnsMonthLevelRowsMostRecentFirstAndRespectsLimit(): void
    {
        $this->insertSummary(2025, 6, null, null, 5, 1, 10);
        $this->insertSummary(2026, 7, null, null, 20, 11, 30);
        $this->insertSummary(2026, 7, 12, null, 20, 11, 30); // day-level, excluded

        try {
            $rows = $this->repo->findMonthlyRows(null);
            self::assertCount(2, $rows);
            self::assertSame(2026, $rows[0]->year);
            self::assertSame(2025, $rows[1]->year);

            self::assertCount(1, $this->repo->findMonthlyRows(1));
        } finally {
            $this->clearSummary();
        }
    }

    public function testFindDailyRowsForMonthsMatchesOnlyTheThreeGivenYearMonthPairs(): void
    {
        $this->insertSummary(2026, 7, 5, null, 5, 1, 10);
        $this->insertSummary(2026, 6, 5, null, 6, 11, 20);
        $this->insertSummary(2025, 7, 5, null, 7, 21, 30);
        $this->insertSummary(2024, 1, 5, null, 8, 31, 40); // not one of the 3 pairs, excluded

        try {
            $rows = $this->repo->findDailyRowsForMonths(2026, 7, 2026, 6, 2025, 7);

            self::assertCount(3, $rows);
            $years = array_column($rows, 'year');
            sort($years);
            self::assertSame([2025, 2026, 2026], $years);
        } finally {
            $this->clearSummary();
        }
    }

    public function testFindAverageDailyPageViewsSinceAveragesMatchingDayLevelRows(): void
    {
        $this->insertSummary(2026, 3, 1, null, 10, 1, 10);
        $this->insertSummary(2026, 3, 2, null, 20, 11, 20);
        $this->insertSummary(2025, 11, 1, null, 999, 21, 30); // previousYear but month <= afterMonth, excluded
        $this->insertSummary(2025, 12, 1, null, 30, 31, 40); // previousYear, month > afterMonth, included

        try {
            $avg = $this->repo->findAverageDailyPageViewsSince(2026, 2025, 11);

            self::assertNotNull($avg);
            self::assertEqualsWithDelta(20.0, $avg, 0.001);
        } finally {
            $this->clearSummary();
        }
    }

    public function testFindAverageDailyPageViewsSinceReturnsNullWhenNothingMatches(): void
    {
        self::assertNull($this->repo->findAverageDailyPageViewsSince(2026, 2025, 11));
    }

    private function insertHistoryLine(int $userId, string $date, string $time): int
    {
        $this->conn->createQueryBuilder()
            ->insert('history')
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
            ->from('history')
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function insertSummary(int $year, ?int $month, ?int $day, ?int $hour, int $nbPages, int $idFrom, int $idTo): void
    {
        $this->conn->createQueryBuilder()
            ->insert('history_summary')
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
            ->from('history_summary')
            ->where('year = :year')
            ->setParameter('year', $year);
        $qb->andWhere($month === null ? 'month IS NULL' : 'month = ' . $month);
        $qb->andWhere($day === null ? 'day IS NULL' : 'day = ' . $day);
        $qb->andWhere($hour === null ? 'hour IS NULL' : 'hour = ' . $hour);

        $row = $qb->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function clearHistory(): void
    {
        $this->conn->executeStatement('DELETE FROM history');
    }

    private function clearSummary(): void
    {
        $this->conn->executeStatement('DELETE FROM history_summary');
    }

    public function testUpdateLastVisitNowSetsLastVisitOnTheRealUserInfosRow(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('last_visit')
            ->from('user_infos')
            ->where('user_id = 4')
            ->executeQuery()
            ->fetchOne();
        self::assertNull($before);

        $this->repo->updateLastVisitNow(4);

        $after = $this->conn->createQueryBuilder()
            ->select('last_visit')
            ->from('user_infos')
            ->where('user_id = 4')
            ->executeQuery()
            ->fetchOne();
        self::assertIsString($after);
        self::assertNotSame('', $after);

        $this->conn->executeStatement('UPDATE user_infos SET last_visit = NULL WHERE user_id = 4');
    }
}
