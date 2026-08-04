<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

/**
 * Two of logVisit()'s/autopurge()'s own defensive branches are
 * unreachable through any legitimate call and deliberately not chased
 * here (same "confirmed unreachable" treatment as RateRepositoryTest's
 * own identical note):
 *  - logVisit()'s `! is_array($cachedSections)` fallback: every writer
 *    of CurrentConfig::$historySectionsCache (both the reflection-setter
 *    path in confUpdateParam(updateGlobal: true) and ConfigService::
 *    hydrate()'s own 'array' match arm) always coerces to a real array
 *    or leaves it null -- there is no code path that leaves it non-null
 *    and non-array.
 *  - autopurge()'s `$latestId === null` guard: only reachable after
 *    already confirming `countAll() > keepLines` (so at least one real
 *    row exists) two lines above, with no intervening write that could
 *    empty the table in a single-threaded request.
 */
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
        // logVisit()'s own isLoggingAllowed() defaults log_conf to false --
        // real production traffic gets it from a genuine DB-loaded config
        // row (never exercised here, this class never calls
        // ConfigService::loadConfFromDb()), so logVisit() tests need it on
        // explicitly. A 'normal' (non-admin, non-guest) CurrentUser leaves
        // it as this exact value (isLoggingAllowed()'s admin/guest
        // overrides don't apply).
        CurrentConfig::setLogConf(true);
        CurrentUser::current()->set(User::fromUserArray(['id' => 1, 'status' => 'normal', 'username' => 'fixture_admin']));
        PageState::current()->reset();
        $GLOBALS['logger'] = new Logger(['severity' => Logger::OFF]);

        $this->conn = DbConnection::build();
        $currentLogger = new CurrentLogger();
        $currentLogger->set(new Logger(['severity' => Logger::OFF]));
        $this->service = new HistoryService(\Piwigo\Auth\AccessControl::current(), EntityManagerFactory::build($this->conn)->getRepository(HistoryEntity::class), new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher()), $currentLogger, new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current());
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

    /**
     * $types (the *full* possible image-type universe the caller passes,
     * e.g. every real extension + 'none') always has more entries than
     * $search['fields']['types'] (the user's own narrower filter) in any
     * real search -- every type NOT in that narrower filter is skipped
     * while building the SQL OR-clauses, not just the ones that happen to
     * be first/last.
     */
    public function test_get_history_filters_by_image_type_skipping_types_outside_the_search_filter(): void
    {
        // image_type is enum('picture','high','other') -- not a real file
        // extension.
        $this->conn->insert(Tables::history(), ['date' => '2026-07-12', 'time' => '03:00:00', 'user_id' => 1, 'IP' => '127.0.0.1', 'image_type' => 'picture']);
        $this->conn->insert(Tables::history(), ['date' => '2026-07-12', 'time' => '04:00:00', 'user_id' => 1, 'IP' => '127.0.0.1', 'image_type' => 'high']);
        $this->conn->insert(Tables::history(), ['date' => '2026-07-12', 'time' => '05:00:00', 'user_id' => 1, 'IP' => '127.0.0.1', 'image_type' => null]);

        $result = $this->service->getHistory([], ['fields' => ['types' => ['picture']]], ['none', 'picture', 'high']);

        self::assertSame(['picture'], array_column($result, 'image_type'));
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

    /**
     * Summarize()'s own $needUpdate accumulation has to track the true
     * minimum history id per higher-level bucket (year/month/day), not
     * just whichever group happens to be processed first -- the
     * underlying query orders groups by *date*, not by id, and id/date
     * are otherwise completely decoupled (both set explicitly here, not
     * left to autoincrement-follows-insertion-order coincidence): the
     * FIRST-inserted row (smaller id) is deliberately given the LATER
     * date, so it's the SECOND group processed for the shared month
     * bucket, forcing the "found an even smaller id" branch to run.
     */
    public function test_summarize_tracks_the_true_minimum_history_id_across_out_of_order_groups(): void
    {
        $smallerId = $this->insertHistoryLine(1, '2026-07-15', '10:00:00');
        $largerId = $this->insertHistoryLine(1, '2026-07-10', '05:00:00');
        self::assertLessThan($largerId, $smallerId);

        $this->service->summarize();

        self::assertSame($smallerId, $this->fetchSummaryHistoryIdFrom(2026, 7, null, null));
        self::assertSame($largerId, $this->fetchSummaryHistoryIdFrom(2026, 7, 10, null));
        self::assertSame($smallerId, $this->fetchSummaryHistoryIdFrom(2026, 7, 15, null));
    }

    // --- logVisit() -------------------------------------------------------

    /**
     * A tag_ids string over 50 chars (the DB column's own length limit) is
     * truncated at exactly 50, then trimmed back to the last full comma so
     * a partially-cut tag id is never stored.
     */
    public function test_log_visit_truncates_an_over_long_tag_ids_string(): void
    {
        $tagIds = range(100, 130);

        $this->service->logVisit(section: 'tags', tagIds: $tagIds);

        self::assertSame(
            '100,101,102,103,104,105,106,107,108,109,110,111',
            $this->fetchLastHistoryColumn('tag_ids')
        );
    }

    /**
     * A full 8-group IPv6 address with an embedded IPv4 tail (a real,
     * filter_var()-valid form -- see IpAddress::from()) can be 45 chars,
     * over the IP column's own 39-char limit -- truncated the same way
     * the docblock above logVisit()'s own truncation describes.
     */
    public function test_log_visit_truncates_an_over_long_ip_address(): void
    {
        $longIp = '0000:0000:0000:0000:0000:ffff:192.168.100.100';
        self::assertGreaterThan(39, strlen($longIp));
        $_SERVER['REMOTE_ADDR'] = $longIp;

        try {
            $this->service->logVisit();

            self::assertSame(substr($longIp, 0, 39), $this->fetchLastHistoryColumn('IP'));
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    /**
     * A page section matching an existing enum option only by case (not
     * exact string equality) is still accepted, rather than triggering
     * the enum-widening ALTER TABLE path -- but what's actually stored
     * back is the enum's own defined casing ('tags'), not the original
     * input's casing ('Tags'): confirmed live, MySQL's ENUM type stores
     * only the matched index internally, so the exact defined string is
     * always what a later SELECT reads back, regardless of which casing
     * variant was in the INSERT.
     */
    public function test_log_visit_accepts_a_known_section_case_insensitively(): void
    {
        $this->service->logVisit(section: 'Tags');

        self::assertSame('tags', $this->fetchLastHistoryColumn('section'));
    }

    /**
     * A brand new page section (not in the enum at all, e.g. a plugin-
     * registered one) widens the `section` column's ENUM definition on
     * the fly via a real ALTER TABLE, then stores it -- restores the
     * original enum list and clears the now-stale cache afterwards so
     * later tests (and other test classes sharing this DB) see the
     * schema exactly as the fixture left it.
     */
    public function test_log_visit_widens_the_section_enum_for_a_brand_new_section(): void
    {
        $repo = EntityManagerFactory::build($this->conn)->getRepository(HistoryEntity::class);
        self::assertInstanceOf(HistoryRepository::class, $repo);
        $originalOptions = $repo->getSectionEnumOptions();
        self::assertNotContains('my_custom_section', $originalOptions);

        try {
            $this->service->logVisit(section: 'my_custom_section');

            self::assertSame('my_custom_section', $this->fetchLastHistoryColumn('section'));
            self::assertContains('my_custom_section', $repo->getSectionEnumOptions());
        } finally {
            // Restoring the narrower, original enum list while a row still
            // holds 'my_custom_section' would itself fail under strict SQL
            // mode ("Data truncated for column 'section'") -- confirmed
            // live, MySQL must be able to fit every existing row's value
            // into the new, narrower enum definition. Delete that row
            // first.
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::history() . " WHERE section = 'my_custom_section'"
            );
            $repo->alterSectionEnum($originalOptions);
            $configService = new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher());
            $configService->confDeleteParam('history_sections_cache');
            CurrentConfig::setHistorySectionsCache(null);
        }
    }

    /**
     * history_autopurge_every > 0 triggers autopurge() on every insert
     * whose new id is an exact multiple of it -- set to 1 so this always
     * fires, regardless of whatever id the insert actually lands on. 2
     * pre-existing, already-summarized lines are needed (not just 1): the
     * most recent summarized line always survives as autopurge()'s own
     * boundary (its own historyIdTo caps deleteBeforeId), so only the
     * OLDER of the two gets purged -- proving real deletion happened, not
     * just that autopurge() silently no-op'd.
     */
    public function test_log_visit_triggers_autopurge_when_the_new_id_is_a_multiple_of_autopurge_every(): void
    {
        CurrentConfig::setHistoryAutopurgeEvery(1);
        CurrentConfig::setHistoryAutopurgeKeepLines(1);
        CurrentConfig::setHistoryAutopurgeBlocksize(1);
        $oldId1 = $this->insertHistoryLine(1, '2026-01-01', '00:00:00');
        $oldId2 = $this->insertHistoryLine(1, '2026-01-01', '01:00:00');
        $this->service->summarize();

        $this->service->logVisit();

        // autopurge() ran as a direct side effect of this logVisit() call
        // (not a separately-called autopurge()): the oldest already-
        // summarized line is gone; the newer summarized one and the brand
        // new visit both survive.
        self::assertSame(2, $this->countHistory());
        $remaining = $this->allHistoryIds();
        self::assertNotContains($oldId1, $remaining);
        self::assertContains($oldId2, $remaining);
    }

    /**
     * add()'s own `$historyId % 1000 === 0` summarize(50000) trigger is a
     * hardcoded constant (unlike history_autopurge_every, which the sibling
     * test above sets to 1 to fire on every insert) -- the only way to land
     * a real logVisit() insert exactly on a multiple of 1000 deterministically,
     * without actually inserting 999 real rows first, is to force the
     * table's own AUTO_INCREMENT counter directly.
     */
    public function test_log_visit_summarizes_when_the_new_id_lands_on_a_multiple_of_1000(): void
    {
        $this->insertHistoryLine(1, '2026-07-12', '03:00:00');
        self::assertNull($this->fetchSummaryNbPages(2026, 7, 12, null));

        $this->conn->executeStatement('ALTER TABLE ' . Tables::history() . ' AUTO_INCREMENT = 1000');

        self::assertTrue($this->service->logVisit());

        $newIds = $this->allHistoryIds();
        self::assertContains(1000, $newIds);

        // summarize(50000) ran as a direct side effect of that logVisit()
        // call (never called separately in this test) -- the earlier,
        // previously-unsummarized 2026-07-12 line is now rolled up into a
        // real history_summary row.
        self::assertSame(1, $this->fetchSummaryNbPages(2026, 7, 12, null));
    }

    private function fetchLastHistoryColumn(string $column): mixed
    {
        return $this->conn->createQueryBuilder()
            ->select($column)
            ->from(Tables::history())
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private function fetchSummaryHistoryIdFrom(int $year, ?int $month, ?int $day, ?int $hour): ?int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('history_id_from')
            ->from(Tables::historySummary())
            ->where('year = :year')
            ->setParameter('year', $year);
        $qb->andWhere($month === null ? 'month IS NULL' : 'month = ' . $month);
        $qb->andWhere($day === null ? 'day IS NULL' : 'day = ' . $day);
        $qb->andWhere($hour === null ? 'hour IS NULL' : 'hour = ' . $hour);

        $value = $qb->executeQuery()->fetchOne();

        return is_numeric($value) ? (int) $value : null;
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
