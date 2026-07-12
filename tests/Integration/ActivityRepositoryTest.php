<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Fixture: 18 activity rows (ids 2-19), all performed_by=1
 * (fixture_admin), covering object types user/album/photo/tag/group, none
 * of them 'system'. Read-only tests query this fixture data directly;
 * write tests insert their own disposable rows and clean up via
 * try/finally.
 */
final class ActivityRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ActivityRepository $repo;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new ActivityRepository($this->conn);
    }

    public function test_insert_many_inserts_every_row(): void
    {
        try {
            $this->repo->insertMany([
                [
                    'object' => 'disposable',
                    'objectId' => 999,
                    'action' => 'test',
                    'performedBy' => 1,
                    'sessionIdx' => 'sess-1',
                    'ipAddress' => '10.0.0.1',
                    'occuredOn' => '2026-07-12 00:00:00',
                    'details' => 'a:0:{}',
                    'userAgent' => 'test-agent',
                ],
                [
                    'object' => 'disposable',
                    'objectId' => 998,
                    'action' => 'test',
                    'performedBy' => 1,
                    'sessionIdx' => 'sess-1',
                    'ipAddress' => null,
                    'occuredOn' => '2026-07-12 00:00:01',
                    'details' => 'a:0:{}',
                    'userAgent' => null,
                ],
            ]);

            $rows = $this->conn->createQueryBuilder()
                ->select('object_id', 'ip_address', 'user_agent')
                ->from(Tables::activity())
                ->where("object = 'disposable'")
                ->orderBy('object_id', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            self::assertCount(2, $rows);
            self::assertSame(998, $rows[0]['object_id']);
            self::assertNull($rows[0]['ip_address']);
            self::assertSame(999, $rows[1]['object_id']);
            self::assertSame('10.0.0.1', $rows[1]['ip_address']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'disposable'");
        }
    }

    public function test_count_by_user_matches_the_fixture(): void
    {
        $counts = $this->repo->countByUser();

        self::assertSame(18, $counts[1]);
    }

    public function test_count_by_user_excludes_system_object(): void
    {
        $this->repo->insertMany([[
            'object' => 'system',
            'objectId' => 1,
            'action' => 'test',
            'performedBy' => 1,
            'sessionIdx' => 'sess-1',
            'ipAddress' => null,
            'occuredOn' => '2026-07-12 00:00:00',
            'details' => 'a:0:{}',
            'userAgent' => null,
        ]]);

        try {
            self::assertSame(18, $this->repo->countByUser()[1], 'the system row must not be counted');
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system'");
        }
    }

    public function test_find_min_and_max_occured_on_match_the_fixture(): void
    {
        // `occured_on` is a TIMESTAMP column (unlike history's plain
        // DATETIME date/time columns) -- MySQL converts it to the reading
        // session's own time_zone on SELECT, so a literal fixture value
        // read back through this test's own connection legitimately
        // differs from what was written by the fixture-load session.
        // activity_id 2 (2026-07-07) is the earliest row, activity_id 19
        // (2026-08-01) is the latest -- assert only their relative order,
        // not an exact literal.
        self::assertLessThan($this->repo->findMaxOccuredOn(), $this->repo->findMinOccuredOn());
        self::assertStringStartsWith('2026-07-07', $this->repo->findMinOccuredOn() ?? '');
        self::assertStringStartsWith('2026-08-01', $this->repo->findMaxOccuredOn() ?? '');
    }

    public function test_find_action_counts_without_a_filter(): void
    {
        $counts = $this->repo->findActionCounts(null);

        $byObject = [];
        foreach ($counts as $row) {
            $byObject[$row['object']] = ($byObject[$row['object']] ?? 0) + $row['counter'];
        }

        // user: 3 logins (activity_id 2,3,19) + 2 adds (14,15) = 5
        self::assertSame(5, $byObject['user']);
        self::assertSame(5, $byObject['photo']);
        self::assertSame(3, $byObject['tag']);
        self::assertSame(3, $byObject['group']);
        self::assertSame(2, $byObject['album']);
    }

    public function test_find_action_counts_with_a_filter(): void
    {
        $counts = $this->repo->findActionCounts('tag');

        self::assertCount(1, $counts);
        self::assertSame('tag', $counts[0]['object']);
        self::assertSame('add', $counts[0]['action']);
        self::assertSame(3, $counts[0]['counter']);
    }

    public function test_find_user_object_log_with_usernames(): void
    {
        $rows = $this->repo->findUserObjectLogWithUsernames('username', 'id');

        // fixture: object='user' rows are activity_id 2, 3, 14, 15, 19
        // (3 logins + 2 adds), all performed_by fixture_admin
        self::assertCount(5, $rows);

        foreach ($rows as $row) {
            self::assertSame('user', $row['object']);
            self::assertSame('fixture_admin', $row['username']);
        }

        // newest first
        self::assertGreaterThan($rows[count($rows) - 1]['activity_id'], $rows[0]['activity_id']);
    }
}
