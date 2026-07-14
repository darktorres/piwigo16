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
 * Fixture: 18 activity rows (activity_id 1-18). Row 1 is object='system',
 * action='install', performed_by=NULL. Rows 2-18 (17 rows) are all
 * performed_by=1 (fixture_admin), covering object types
 * user/album/photo/tag/group: user (2,3 login; 14,15 add), album (4,5 add),
 * photo (6-10 add), tag (11-13 add), group (16-18 add). Every row shares
 * the same fixture-wide timestamp (2026-08-01 00:00:00, matching
 * PIWIGO_TEST_NOW) -- tests needing genuinely distinguishable dates mutate
 * their own row(s), scoped to that test only. Read-only tests query this
 * fixture data directly; write tests insert their own disposable rows and
 * clean up via try/finally.
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

        self::assertSame(17, $counts[1]);
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
            self::assertSame(17, $this->repo->countByUser()[1], 'the system row must not be counted');
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action != 'install'");
        }
    }

    public function test_find_min_and_max_occured_on_match_the_fixture(): void
    {
        // Every fixture row shares the same timestamp -- push activity_id
        // 1 (the earliest-inserted row) genuinely earlier, scoped to this
        // test only, so min/max are actually distinguishable.
        $this->conn->executeStatement(
            "UPDATE " . Tables::activity() . " SET occured_on = '2026-07-07 00:00:00' WHERE activity_id = 1"
        );

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

        // user: 2 logins (activity_id 2,3) + 2 adds (14,15) = 4
        self::assertSame(4, $byObject['user']);
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

        // fixture: object='user' rows are activity_id 2, 3, 14, 15
        // (2 logins + 2 adds), all performed_by fixture_admin
        self::assertCount(4, $rows);

        foreach ($rows as $row) {
            self::assertSame('user', $row['object']);
            self::assertSame('fixture_admin', $row['username']);
        }

        // newest first
        self::assertGreaterThan($rows[count($rows) - 1]['activity_id'], $rows[0]['activity_id']);
    }

    public function test_find_system_object_log_with_usernames_uses_the_real_username_when_performed_by_is_a_real_user(): void
    {
        $this->repo->insertMany([[
            'object' => 'system',
            'objectId' => 0,
            'action' => 'maintenance',
            'performedBy' => 1,
            'sessionIdx' => 'sess-1',
            'ipAddress' => null,
            'occuredOn' => '2026-07-12 00:00:00',
            'details' => 'a:0:{}',
            'userAgent' => null,
        ]]);

        try {
            $rows = $this->repo->findSystemObjectLogWithUsernames('username', 'id');

            // The fixture's own row 1 (action='install') is also a
            // 'system' row -- filter to this test's own inserted row
            // rather than assuming it's the only one.
            $matching = array_values(array_filter($rows, static fn (array $row): bool => $row['action'] === 'maintenance'));

            self::assertCount(1, $matching);
            self::assertSame('fixture_admin', $matching[0]['username']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action != 'install'");
        }
    }

    public function test_find_system_object_log_with_usernames_renders_a_null_performed_by_as_system(): void
    {
        // A real, adversarially-verified bug fixed in this same batch (see
        // ActivityService::record()'s own fix note): activity.performed_by
        // has an ON DELETE SET NULL foreign key to users.id, and 0 (the
        // legacy "no known actor" sentinel) is not a valid user id --
        // writing it throws a real ForeignKeyConstraintViolationException.
        // null is the column's real "no known actor" value (e.g. a plugin
        // autoupdate that ran before $user was loaded); it must render as
        // "System" here, matching the intent the legacy
        // IF(performed_by = 0, 'System', username) expression could no
        // longer actually reach once this FK was added.
        $this->repo->insertMany([[
            'object' => 'system',
            'objectId' => 0,
            'action' => 'update',
            'performedBy' => null,
            'sessionIdx' => 'sess-1',
            'ipAddress' => null,
            'occuredOn' => '2026-07-12 00:00:00',
            'details' => 'a:0:{}',
            'userAgent' => null,
        ]]);

        try {
            $rows = $this->repo->findSystemObjectLogWithUsernames('username', 'id');

            // The fixture's own row 1 (action='install', also performed_by
            // NULL) legitimately renders "System" too -- filter to this
            // test's own inserted row rather than assuming it's the only one.
            $matching = array_values(array_filter($rows, static fn (array $row): bool => $row['action'] === 'update'));

            self::assertCount(1, $matching);
            self::assertNull($matching[0]['performed_by']);
            self::assertSame('System', $matching[0]['username']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action != 'install'");
        }
    }
}
