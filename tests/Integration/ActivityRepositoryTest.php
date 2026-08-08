<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Activity\ActivityEntity;
use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityListCriteria;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\Projection\PaginatedActivityRow;
use Piwigo\Activity\Projection\SystemActionCount;
use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserAgentBreakdown;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ActivitySystem;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Fixture: 19 activity rows (activity_id 1-19). Row 1 is object='system',
 * action='activate' (activateCoreThemes()'s own real attempt to activate
 * the 'default' placeholder theme -- always a no-op on piwigo_themes
 * itself, see InstallService's own docblock, but still activity-logged
 * like any other extension action). Row 2 is object='system',
 * action='install', performed_by=NULL. Rows 3-19 (17 rows) are all
 * performed_by=1 (fixture_admin), covering object types
 * user/album/photo/tag/group: user (3,4 login; 15,16 add), album (5,6 add),
 * photo (7-11 add), tag (12-14 add), group (17-19 add). Every row shares
 * the same fixture-wide timestamp (2026-08-01 03:00:00) -- tests needing
 * genuinely distinguishable dates mutate their own row(s), scoped to that
 * test only. Read-only tests query this fixture data directly; write
 * tests insert their own disposable rows and clean up via try/finally.
 */
final class ActivityRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ActivityRepository $repo;

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
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class);
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
                    'ipAddress' => IpAddress::from('10.0.0.1'),
                    'occuredOn' => SqlDateTime::from('2026-07-12 00:00:00'),
                    'details' => [],
                    'userAgent' => 'test-agent',
                ],
                [
                    'object' => 'disposable',
                    'objectId' => 998,
                    'action' => 'test',
                    'performedBy' => 1,
                    'sessionIdx' => 'sess-1',
                    'ipAddress' => null,
                    'occuredOn' => SqlDateTime::from('2026-07-12 00:00:01'),
                    'details' => [],
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

    public function test_insert_many_with_an_empty_array_is_a_no_op(): void
    {
        $countBefore = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::activity());

        $this->repo->insertMany([]);

        $countAfter = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::activity());

        self::assertSame($countBefore, $countAfter);
    }

    public function test_insert_many_casts_a_numeric_string_object_id_to_int(): void
    {
        try {
            $this->repo->insertMany([[
                'object' => 'disposable',
                'objectId' => '777',
                'action' => 'test',
                'performedBy' => 1,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => '2026-07-12 00:00:00',
                'details' => [],
                'userAgent' => null,
            ]]);

            $objectId = $this->conn->createQueryBuilder()
                ->select('object_id')
                ->from(Tables::activity())
                ->where("object = 'disposable'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame(777, $objectId);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'disposable'");
        }
    }

    public function test_insert_many_defaults_a_non_numeric_object_id_to_zero(): void
    {
        try {
            $this->repo->insertMany([[
                'object' => 'disposable',
                'objectId' => 'not-a-number',
                'action' => 'test',
                'performedBy' => 1,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => '2026-07-12 00:00:00',
                'details' => [],
                'userAgent' => null,
            ]]);

            $objectId = $this->conn->createQueryBuilder()
                ->select('object_id')
                ->from(Tables::activity())
                ->where("object = 'disposable'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame(0, $objectId);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'disposable'");
        }
    }

    public function test_insert_many_stores_a_non_null_performed_by_as_the_user_id(): void
    {
        try {
            $this->repo->insertMany([[
                'object' => 'disposable',
                'objectId' => 1,
                'action' => 'test',
                'performedBy' => 1,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => '2026-07-12 00:00:00',
                'details' => [],
                'userAgent' => null,
            ]]);

            $performedBy = $this->conn->createQueryBuilder()
                ->select('performed_by')
                ->from(Tables::activity())
                ->where("object = 'disposable'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame(1, $performedBy);
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
            'occuredOn' => SqlDateTime::from('2026-07-12 00:00:00'),
            'details' => [],
            'userAgent' => null,
        ]]);

        try {
            self::assertSame(17, $this->repo->countByUser()[1], 'the system row must not be counted');
        } finally {
            // Scoped to this test's own inserted row (action = 'test',
            // matching the literal value passed to insertMany() above) --
            // the fixture now has a second, real, legitimate 'system' row
            // besides 'install' (activateCoreThemes()'s own 'activate'
            // entry, see InstallService's docblock), which a broader
            // `action != 'install'` filter would incorrectly delete too.
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action = 'test'");
        }
    }

    public function test_count_by_user_skips_a_row_with_a_null_performed_by(): void
    {
        // A non-'system' row whose acting user was since deleted (ON
        // DELETE SET NULL on activity.performed_by, see this method's own
        // docblock) genuinely has performed_by = NULL. The raw GROUP BY
        // query groups it under its own NULL bucket; the `continue` guard
        // (line 88) must skip that bucket rather than mis-cast NULL to an
        // int(0) group key that would collide with a real user id 0 could
        // never actually be.
        $this->repo->insertMany([[
            'object' => 'disposable',
            'objectId' => 1,
            'action' => 'test',
            'performedBy' => null,
            'sessionIdx' => 'sess-1',
            'ipAddress' => null,
            'occuredOn' => SqlDateTime::from('2026-07-12 00:00:00'),
            'details' => [],
            'userAgent' => null,
        ]]);

        try {
            $counts = $this->repo->countByUser();

            self::assertCount(1, $counts, 'the NULL-performed_by row must not add its own bucket');
            self::assertSame(17, $counts[1]);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'disposable'");
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

    public function test_find_occured_on_for_object_matches_by_object_id_object_and_action(): void
    {
        $this->repo->insertMany([[
            'object' => 'disposable',
            'objectId' => 4242,
            'action' => 'find-test',
            'performedBy' => null,
            'sessionIdx' => 'sess-1',
            'ipAddress' => null,
            'occuredOn' => '2026-07-15 00:00:00',
            'details' => [],
            'userAgent' => null,
        ]]);

        try {
            self::assertSame(
                '2026-07-15 00:00:00',
                $this->repo->findOccuredOnForObject(4242, 'disposable', 'find-test')
            );

            // A mismatch on any one of the three criteria must not match
            // this row -- each assertion below isolates one criterion
            // (objectId, object, action), catching a mutant that drops
            // that criterion from the findOneBy() filter (matching too
            // broadly) as well as one that replaces the null-safe operator
            // with a plain one (crashing on the resulting null instead of
            // returning it).
            self::assertNull($this->repo->findOccuredOnForObject(9999, 'disposable', 'find-test'), 'a non-matching objectId must not match');
            self::assertNull($this->repo->findOccuredOnForObject(4242, 'other-object', 'find-test'), 'a non-matching object must not match');
            self::assertNull($this->repo->findOccuredOnForObject(4242, 'disposable', 'other-action'), 'a non-matching action must not match');
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'disposable' AND action = 'find-test'");
        }
    }

    public function test_find_action_counts_without_a_filter(): void
    {
        $counts = $this->repo->findActionCounts(null);

        $byObject = [];
        foreach ($counts as $row) {
            $byObject[$row->object] = ($byObject[$row->object] ?? 0) + $row->counter;
        }

        // user: 2 logins (activity_id 3,4) + 2 adds (15,16) = 4
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
        self::assertSame('tag', $counts[0]->object);
        self::assertSame('add', $counts[0]->action);
        self::assertSame(3, $counts[0]->counter);
    }

    public function test_find_user_object_log_with_usernames(): void
    {
        $rows = $this->repo->findUserObjectLogWithUsernames();

        // fixture: object='user' rows are activity_id 3, 4, 15, 16
        // (2 logins + 2 adds), all performed_by fixture_admin
        self::assertCount(4, $rows);

        foreach ($rows as $row) {
            self::assertSame('user', $row->object);
            self::assertSame('fixture_admin', $row->username);
        }

        // newest first
        self::assertGreaterThan($rows[count($rows) - 1]->activityId, $rows[0]->activityId);

        // activity_id 4's own fixture row has a real details JSON payload
        // and a real ip_address -- both go through Doctrine's own custom
        // Type hydration (json/ip_address) that a bare object/username
        // assertion above would never observe if a conversion bug
        // silently dropped either back to null.
        $loginRow = null;
        foreach ($rows as $row) {
            if ($row->activityId === 4) {
                $loginRow = $row;
            }
        }
        self::assertNotNull($loginRow);
        self::assertNotNull($loginRow->ipAddress);
        self::assertSame('::1', $loginRow->ipAddress->value);
        self::assertIsString($loginRow->details);
        self::assertSame(['method' => 'pwg.session.login'], json_decode($loginRow->details, true));
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
            'occuredOn' => SqlDateTime::from('2026-07-12 00:00:00'),
            'details' => [],
            'userAgent' => null,
        ]]);

        try {
            $rows = $this->repo->findSystemObjectLogWithUsernames();

            // The fixture's own rows 1 (action='activate') and 2
            // (action='install') are also 'system' rows -- filter to this
            // test's own inserted row rather than assuming it's the only one.
            $matching = array_values(array_filter($rows, static fn (SystemActivityLogEntry $row): bool => $row->action === 'maintenance'));

            self::assertCount(1, $matching);
            self::assertSame('fixture_admin', $matching[0]->username);
        } finally {
            // Scoped to this test's own inserted row (action = 'maintenance')
            // -- see test_count_by_user_excludes_system_object's own comment
            // for why a broader `action != 'install'` filter is wrong now.
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action = 'maintenance'");
        }
    }

    public function test_find_system_object_log_with_usernames_renders_a_null_performed_by_as_system(): void
    {
        // activity.performed_by has an ON DELETE SET NULL foreign key to
        // users.id, and 0 is not a valid user id -- writing it throws a
        // real ForeignKeyConstraintViolationException. null is the
        // column's real "no known actor" value (e.g. a plugin autoupdate
        // that ran before $user was loaded); it must render as "System"
        // here.
        $this->repo->insertMany([[
            'object' => 'system',
            'objectId' => 0,
            'action' => 'update',
            'performedBy' => null,
            'sessionIdx' => 'sess-1',
            'ipAddress' => null,
            'occuredOn' => SqlDateTime::from('2026-07-12 00:00:00'),
            'details' => [],
            'userAgent' => null,
        ]]);

        try {
            $rows = $this->repo->findSystemObjectLogWithUsernames();

            // The fixture's own rows 1 (action='activate') and 2
            // (action='install'), both also performed_by NULL, legitimately
            // render "System" too -- filter to this test's own inserted row
            // rather than assuming it's the only one.
            $matching = array_values(array_filter($rows, static fn (SystemActivityLogEntry $row): bool => $row->action === 'update'));

            self::assertCount(1, $matching);
            self::assertNull($matching[0]->performedBy);
            self::assertSame('System', $matching[0]->username);
        } finally {
            // Scoped to this test's own inserted row (action = 'update') --
            // see test_count_by_user_excludes_system_object's own comment
            // for why a broader `action != 'install'` filter is wrong now.
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action = 'update'");
        }
    }

    public function test_find_core_update_history_returns_core_update_and_autoupdate_rows_oldest_first(): void
    {
        // The fixture's own row 2 (object='system', object_id=1=Core,
        // action='install') deliberately doesn't match the action IN
        // ('update', 'autoupdate') filter -- Admin\PiwigoInfosSender's own
        // "version upgrade history" telemetry only cares about the two
        // real upgrade-path actions, not the one-time install.
        $this->repo->insertMany([
            [
                'object' => 'system',
                'objectId' => ActivitySystem::Core,
                'action' => 'update',
                'performedBy' => null,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-10 00:00:00'),
                'details' => ['from_version' => '16.0.0', 'to_version' => '17.0.0'],
                'userAgent' => null,
            ],
            [
                'object' => 'system',
                'objectId' => ActivitySystem::Core,
                'action' => 'autoupdate',
                'performedBy' => null,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-11 00:00:00'),
                'details' => ['from_version' => '17.0.0', 'to_version' => '17.0.1'],
                'userAgent' => null,
            ],
        ]);

        try {
            $rows = $this->repo->findCoreUpdateHistory();

            self::assertCount(2, $rows, 'only the 2 Core update/autoupdate rows just inserted should match');

            self::assertSame('update', $rows[0]->action);
            self::assertSame('2026-07-10 00:00:00', $rows[0]->occuredOn);
            self::assertIsString($rows[0]->details);
            // MySQL's JSON column type reorders object members (by key
            // length, then lexicographically) independent of the original
            // insertion order -- ksort() both sides, matching this
            // codebase's own established convention for this gotcha (see
            // tests/Contract/WsImagesFilteredSearchTest.php's docblock).
            $expectedDetails = ['from_version' => '16.0.0', 'to_version' => '17.0.0'];
            $actualDetails = json_decode($rows[0]->details, true);
            ksort($expectedDetails);
            self::assertIsArray($actualDetails);
            ksort($actualDetails);
            self::assertSame($expectedDetails, $actualDetails);

            // oldest first (ORDER BY activity_id ASC)
            self::assertSame('autoupdate', $rows[1]->action);
            self::assertSame('2026-07-11 00:00:00', $rows[1]->occuredOn);
        } finally {
            $this->conn->executeStatement(
                "DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action IN ('update', 'autoupdate') AND object_id = " . ActivitySystem::Core
            );
        }
    }

    public function test_find_system_action_counts_by_object_id_groups_by_object_id_and_action(): void
    {
        // The fixture's own row 2 is object='system', object_id=1=Core,
        // action='install' -- object_id=Plugin here is chosen specifically
        // so this test's own group can't merge with it.
        $this->repo->insertMany([
            [
                'object' => 'system',
                'objectId' => ActivitySystem::Plugin,
                'action' => 'install',
                'performedBy' => null,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-10 00:00:00'),
                'details' => [],
                'userAgent' => null,
            ],
            [
                'object' => 'system',
                'objectId' => ActivitySystem::Plugin,
                'action' => 'install',
                'performedBy' => null,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-10 00:00:01'),
                'details' => [],
                'userAgent' => null,
            ],
        ]);

        try {
            $rows = $this->repo->findSystemActionCountsByObjectId();

            $matching = array_values(array_filter(
                $rows,
                static fn (SystemActionCount $row): bool => $row->objectId === ActivitySystem::Plugin && $row->action === 'install'
            ));

            self::assertCount(1, $matching, 'the 2 rows just inserted must collapse into a single grouped bucket');
            self::assertSame('system', $matching[0]->object);
            self::assertSame(2, $matching[0]->counter);
        } finally {
            $this->conn->executeStatement(
                "DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action = 'install' AND object_id = " . ActivitySystem::Plugin
            );
        }
    }

    public function test_find_user_agent_breakdown_excludes_browser_traffic_and_aggregates_by_user_agent(): void
    {
        $this->repo->insertMany([
            [
                'object' => 'disposable',
                'objectId' => 1,
                'action' => 'test',
                'performedBy' => 1,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-10 00:00:00'),
                'details' => [],
                'userAgent' => 'PiwigoRepoTestAgent/1.0',
            ],
            [
                'object' => 'disposable',
                'objectId' => 2,
                'action' => 'test',
                'performedBy' => 1,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-11 00:00:00'),
                'details' => [],
                'userAgent' => 'PiwigoRepoTestAgent/1.0',
            ],
            [
                // real browser traffic (Mozilla/5.x) -- must be excluded by
                // the `WHERE user_agent NOT LIKE 'Mozilla/5%'` filter.
                'object' => 'disposable',
                'objectId' => 3,
                'action' => 'test',
                'performedBy' => 1,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => SqlDateTime::from('2026-07-12 00:00:00'),
                'details' => [],
                'userAgent' => 'Mozilla/5.0 (a real browser)',
            ],
        ]);

        try {
            $rows = $this->repo->findUserAgentBreakdown();

            foreach ($rows as $row) {
                self::assertFalse(str_starts_with($row->userAgent ?? '', 'Mozilla/5'), 'browser traffic must be excluded');
            }

            $matching = array_values(array_filter($rows, static fn (UserAgentBreakdown $row): bool => $row->userAgent === 'PiwigoRepoTestAgent/1.0'));

            self::assertCount(1, $matching);
            self::assertSame(2, $matching[0]->counter);
            self::assertSame('2026-07-10 00:00:00', $matching[0]->firstEncounter);
            self::assertSame('2026-07-11 00:00:00', $matching[0]->lastEncounter);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'disposable'");
        }
    }

    /**
     * Covers a plain optional-eq filter, a min/max date range, and both
     * connectionsMode branches: 'admins_only' compiles to a nested
     * NOT(x AND y) DQL expression via Criteria::expr()->not()/andX().
     */
    public function test_find_paginated_filters_by_object(): void
    {
        $rows = $this->repo->findPaginated(new ActivityListCriteria(object: 'tag'), 100, 0);

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame('tag', $row->object);
        }
    }

    public function test_find_paginated_excludes_system_object_unconditionally(): void
    {
        $rows = $this->repo->findPaginated(new ActivityListCriteria(), 100, 0);

        self::assertCount(17, $rows);
        foreach ($rows as $row) {
            self::assertNotSame('system', $row->object);
        }
    }

    public function test_find_paginated_filters_by_date_range(): void
    {
        // Every fixture row shares the same 2026-08-01 03:00:00 timestamp
        // (see this class's own docblock) -- a range excluding it must
        // return nothing, one including it must return every non-system row.
        $excluding = $this->repo->findPaginated(new ActivityListCriteria(maxDate: SqlDateTime::from('2026-07-31 00:00:00')), 100, 0);
        $including = $this->repo->findPaginated(new ActivityListCriteria(minDate: SqlDateTime::from('2026-08-01 00:00:00'), maxDate: SqlDateTime::from('2026-08-01 23:59:59')), 100, 0);

        self::assertSame([], $excluding);
        self::assertCount(17, $including);
    }

    public function test_find_paginated_connections_mode_none_excludes_every_login(): void
    {
        $rows = $this->repo->findPaginated(new ActivityListCriteria(connectionsMode: 'none'), 100, 0);

        self::assertCount(15, $rows);
        foreach ($rows as $row) {
            self::assertNotSame('login', $row->action);
        }
    }

    public function test_find_paginated_connections_mode_admins_only_keeps_an_admin_login(): void
    {
        // activity_id 3/4 are 'login' rows with object_id=1 (fixture_admin's
        // own id) -- adminIds: [1] must keep them, unlike 'none'.
        $rows = $this->repo->findPaginated(new ActivityListCriteria(connectionsMode: 'admins_only', adminIds: [UserId::from(1)]), 100, 0);

        self::assertCount(17, $rows);
        $logins = array_values(array_filter($rows, static fn (PaginatedActivityRow $row): bool => $row->action === 'login'));
        self::assertCount(2, $logins);
    }

    public function test_find_paginated_connections_mode_admins_only_excludes_a_non_admin_login(): void
    {
        // Same 2 login rows (object_id=1), but adminIds: [999] doesn't
        // match -- NOT (action IN (login,logout) AND objectId NOT IN
        // (999)) must exclude them here, unlike the adminIds: [1] case
        // above.
        $rows = $this->repo->findPaginated(new ActivityListCriteria(connectionsMode: 'admins_only', adminIds: [UserId::from(999)]), 100, 0);

        self::assertCount(15, $rows);
        foreach ($rows as $row) {
            self::assertNotSame('login', $row->action);
        }
    }
}
