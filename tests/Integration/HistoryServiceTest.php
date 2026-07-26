<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;

final class HistoryServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private HistoryService $service;

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

        CurrentConfig::setHistoryAutopurgeKeepLines(0);
        CurrentConfig::setHistoryAutopurgeBlocksize(50);
        $GLOBALS['logger'] = new Logger(['severity' => Logger::OFF]);

        $this->conn = DbConnection::build();
        $this->service = new HistoryService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\History\HistoryEntity::class), new ConfigService($this->buildConfigRepository()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::history());
        $this->conn->executeStatement('DELETE FROM ' . Tables::historySummary());
        parent::tearDown();
    }

    public function test_history_compare_orders_by_date_then_time(): void
    {
        $earlier = ['date' => '2026-07-11', 'time' => '10:00:00'];
        $later = ['date' => '2026-07-12', 'time' => '09:00:00'];

        self::assertLessThan(0, $this->service->historyCompare($earlier, $later));
        self::assertGreaterThan(0, $this->service->historyCompare($later, $earlier));
        self::assertSame(0, $this->service->historyCompare($earlier, $earlier));
    }

    public function test_get_history_appends_matching_rows_to_the_given_data(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(3, '2026-07-12', '03:00:00');

        $data = [['seed' => true]];
        $result = $this->service->getHistory($data, ['fields' => ['user' => 1]], []);

        self::assertCount(2, $result);
        self::assertTrue($result[0]['seed']);
        self::assertSame(1, $result[1]['user_id']);
    }

    public function test_get_history_with_no_search_fields_returns_every_line(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(3, '2026-07-12', '03:00:00');

        $result = $this->service->getHistory([], [], []);

        self::assertCount(2, $result);
    }

    public function test_summarize_creates_new_summary_rows_from_scratch(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:10:00');
        $this->insertHistoryLine(1, '2026-07-12', '03:50:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        $this->service->summarize();

        self::assertSame(3, $this->fetchSummaryNbPages(2026, null, null, null));
        self::assertSame(3, $this->fetchSummaryNbPages(2026, 7, null, null));
        self::assertSame(3, $this->fetchSummaryNbPages(2026, 7, 12, null));
        self::assertSame(2, $this->fetchSummaryNbPages(2026, 7, 12, 3));
        self::assertSame(1, $this->fetchSummaryNbPages(2026, 7, 12, 4));
    }

    public function test_summarize_merges_into_an_existing_partial_summary_on_a_later_run(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->service->summarize();

        // a later batch, same day
        $this->insertHistoryLine(1, '2026-07-12', '03:30:00');
        $this->insertHistoryLine(1, '2026-07-12', '05:00:00');
        $this->service->summarize();

        // the day-level and year-level buckets accumulate across both runs;
        // the hour-3 bucket accumulates across both lines it saw; hour-5 is
        // new this run only.
        self::assertSame(3, $this->fetchSummaryNbPages(2026, null, null, null));
        self::assertSame(3, $this->fetchSummaryNbPages(2026, 7, null, null));
        self::assertSame(3, $this->fetchSummaryNbPages(2026, 7, 12, null));
        self::assertSame(2, $this->fetchSummaryNbPages(2026, 7, 12, 3));
        self::assertSame(1, $this->fetchSummaryNbPages(2026, 7, 12, 5));
    }

    public function test_summarize_respects_max_lines(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(1, '2026-07-13', '03:00:00');

        // $maxLines=1 caps the window to $historyMinId+1, i.e. exactly the
        // one id after the (empty-summary) starting point -- the first
        // inserted line only, regardless of what its actual auto_increment
        // value happens to be (this test class doesn't reset it between
        // tests, since tearDown() only DELETEs rows).
        $this->service->summarize(1);

        self::assertSame(1, $this->fetchSummaryNbPages(2026, 7, 12, null));
        self::assertArrayNotHasKeySummary(2026, 7, 13, null);
    }

    public function test_autopurge_is_a_no_op_when_keep_lines_is_zero(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        $this->service->autopurge();

        self::assertSame(1, $this->countHistory());
    }

    public function test_autopurge_is_a_no_op_when_under_the_keep_lines_threshold(): void
    {
        CurrentConfig::setHistoryAutopurgeKeepLines(10);
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        $this->service->autopurge();

        self::assertSame(1, $this->countHistory());
    }

    public function test_autopurge_is_a_no_op_when_nothing_is_summarized_yet(): void
    {
        CurrentConfig::setHistoryAutopurgeKeepLines(1);
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        $this->insertHistoryLine(1, '2026-07-12', '04:00:00');

        $this->service->autopurge();

        self::assertSame(2, $this->countHistory());
    }

    public function test_autopurge_deletes_old_summarized_lines(): void
    {
        CurrentConfig::setHistoryAutopurgeKeepLines(1);
        CurrentConfig::setHistoryAutopurgeBlocksize(1);
        $id1 = $this->insertHistoryLine(1, '2026-07-10', '03:00:00');
        $id2 = $this->insertHistoryLine(1, '2026-07-11', '03:00:00');
        $id3 = $this->insertHistoryLine(1, '2026-07-12', '03:00:00');

        $this->service->summarize();
        $this->service->autopurge();

        // deleteBeforeId = min(lastSummary.historyIdTo=$id3,
        // latestId-keepLines=$id3-1=$id2, oldestId+blocksize=$id1+1=$id2)
        // = $id2, so only $id1 (id < $id2) is purged.
        self::assertSame([$id2, $id3], $this->allHistoryIds());
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

    private function countHistory(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<int>
     */
    private function allHistoryIds(): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::history())
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $ids
        );
    }

    private function fetchSummaryNbPages(int $year, ?int $month, ?int $day, ?int $hour): ?int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('nb_pages')
            ->from(Tables::historySummary())
            ->where('year = :year')
            ->setParameter('year', $year);
        $qb->andWhere($month === null ? 'month IS NULL' : 'month = ' . $month);
        $qb->andWhere($day === null ? 'day IS NULL' : 'day = ' . $day);
        $qb->andWhere($hour === null ? 'hour IS NULL' : 'hour = ' . $hour);

        $value = $qb->executeQuery()->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    private function assertArrayNotHasKeySummary(int $year, ?int $month, ?int $day, ?int $hour): void
    {
        self::assertNull($this->fetchSummaryNbPages($year, $month, $day, $hour));
    }
}
