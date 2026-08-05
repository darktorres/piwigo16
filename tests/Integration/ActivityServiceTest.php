<?php

declare(strict_types=1);

// script_basename() stub removed -- ActivityService::record() now calls
// Piwigo\Core\PageFilterHelper::scriptBasename() directly (P23 batch 8d),
// a real class method a same-named bare-function stub can no longer
// intercept, so the stub was unreachable dead code.
namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ActivitySystem;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

final class ActivityServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ActivityService $service;

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

        $currentConfig->setPhpExtensionInUrls(false);
        CurrentUser::current()->set(User::fromUserArray(['id' => 1]));
        CurrentUser::current()->markRealUserResolved();
        unset($_REQUEST['method'], $_REQUEST['action'], $_GET['page'], $_POST['destination_tag'], $_SESSION['connected_with']);
        $_SERVER['SCRIPT_NAME'] = '/some/script.php';

        $this->conn = DbConnection::build();
        $this->service = new ActivityService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Activity\ActivityEntity::class));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object LIKE 'test-%'");
        parent::tearDown();
    }

    public function test_record_is_a_no_op_for_an_automatic_upload_async_login(): void
    {
        // object_id 888888 is disposable/non-colliding -- the fixture
        // already has 3 real user/1/login rows (activity_id 2, 3, 19), so
        // asserting an absolute 0 for that exact combination would be
        // wrong regardless of this test's own outcome.
        $_REQUEST['method'] = 'pwg.images.uploadAsync';

        $this->service->record('user', 888888, 'login');

        self::assertSame(0, $this->countRows('user', 888888, 'login'));
    }

    public function test_record_is_a_no_op_for_a_mismatched_perform_action(): void
    {
        // matches the real calling convention (src/Piwigo/Admin/plugins.php):
        // pwg_activity('system', ActivitySystem::Plugin, $action, ...) --
        // object_id is always the fixed int sentinel 2, never a plugin
        // slug string (the `activity` table's object_id column is
        // `int unsigned NOT NULL`).
        $_REQUEST['method'] = 'pwg.plugins.performAction';
        $_REQUEST['action'] = 'deactivate';

        $this->service->record('system', 2, 'restore');

        self::assertSame(0, $this->countRows('system', 2, 'restore'));
    }

    public function test_record_proceeds_for_a_matching_perform_action(): void
    {
        $_REQUEST['method'] = 'pwg.plugins.performAction';
        $_REQUEST['action'] = 'restore';

        $this->service->record('system', 2, 'restore');

        try {
            self::assertSame(1, $this->countRows('system', 2, 'restore'));
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action = 'restore'");
        }
    }

    public function test_record_stores_the_request_method_in_details(): void
    {
        $_REQUEST['method'] = 'pwg.tags.add';

        $this->service->record('test-tag', 1, 'add');

        $details = $this->fetchDetails('test-tag', 1, 'add');
        self::assertIsArray($details);
        self::assertSame('pwg.tags.add', $details['method']);
    }

    public function test_record_falls_back_to_script_basename_when_no_method(): void
    {
        $this->service->record('test-script', 1, 'add');

        $details = $this->fetchDetails('test-script', 1, 'add');
        self::assertIsArray($details);
        self::assertSame('script', $details['script']);
    }

    public function test_record_appends_the_admin_page_to_the_script_detail(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/admin.php';
        $_GET['page'] = 'user_activity';

        $this->service->record('test-admin', 1, 'add');

        $details = $this->fetchDetails('test-admin', 1, 'add');
        self::assertIsArray($details);
        self::assertSame('admin/user_activity', $details['script']);
    }

    public function test_record_unsets_method_and_script_on_autoupdate(): void
    {
        $_REQUEST['method'] = 'pwg.plugins.performAction';
        $_REQUEST['action'] = 'autoupdate';

        $this->service->record('test-autoupdate', 1, 'autoupdate');

        $details = $this->fetchDetails('test-autoupdate', 1, 'autoupdate');
        self::assertIsArray($details);
        self::assertArrayNotHasKey('method', $details);
        self::assertArrayNotHasKey('script', $details);
    }

    public function test_record_captures_the_user_agent_on_login(): void
    {
        // user-agent capture is hardcoded to the literal object 'user',
        // so this can't use the 'test-' prefix tearDown() otherwise relies
        // on -- clean up explicitly instead (object_id 777 doesn't collide
        // with any fixture row).
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        try {
            $this->service->record('user', 777, 'login');

            $userAgent = $this->conn->createQueryBuilder()
                ->select('user_agent')
                ->from(Tables::activity())
                ->where('object_id = 777')
                ->executeQuery()
                ->fetchOne();

            self::assertSame('TestAgent/1.0', $userAgent);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::activity() . ' WHERE object_id = 777');
        }
    }

    public function test_record_does_not_capture_user_agent_for_a_non_login_action(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $this->service->record('test-non-login', 1, 'add');

        $userAgent = $this->conn->createQueryBuilder()
            ->select('user_agent')
            ->from(Tables::activity())
            ->where("object = 'test-non-login'")
            ->executeQuery()
            ->fetchOne();

        self::assertNull($userAgent);
    }

    public function test_record_detects_a_tag_merge(): void
    {
        // merge detection is hardcoded to the literal object 'tag', so
        // this can't use the 'test-' prefix tearDown() otherwise relies on
        // -- clean up explicitly instead (the fixture has no 'tag'+'delete'
        // row, only 'tag'+'add', so this DELETE can't touch fixture data).
        $_POST['destination_tag'] = '5';

        try {
            $this->service->record('tag', 1, 'delete');

            $details = $this->fetchDetails('tag', 1, 'delete');
            self::assertIsArray($details);
            self::assertSame('merge', $details['action']);
            self::assertSame('5', $details['destination_tag']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::activity() . " WHERE object = 'tag' AND action = 'delete'");
        }
    }

    public function test_record_fans_out_over_multiple_object_ids(): void
    {
        $this->service->record('test-multi', [10, 20, 30], 'add');

        self::assertSame(3, $this->countRows('test-multi', null, 'add'));
    }

    public function test_record_uses_the_object_id_as_performed_by_on_logout(): void
    {
        // should be ignored for logout
        CurrentUser::current()->set(User::fromUserArray(['id' => 999999]));
        CurrentUser::current()->markRealUserResolved();

        $this->service->record('test-logout', 1, 'logout');

        $performedBy = $this->conn->createQueryBuilder()
            ->select('performed_by')
            ->from(Tables::activity())
            ->where("object = 'test-logout'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $performedBy);
    }

    public function test_record_uses_the_current_user_as_performed_by_otherwise(): void
    {
        CurrentUser::current()->set(User::fromUserArray(['id' => 3]));
        CurrentUser::current()->markRealUserResolved();

        $this->service->record('test-performer', 999, 'add');

        $performedBy = $this->conn->createQueryBuilder()
            ->select('performed_by')
            ->from(Tables::activity())
            ->where("object = 'test-performer'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame(3, $performedBy);
    }

    public function test_record_detects_the_auto_login_auth_function_from_the_call_stack(): void
    {
        // No real caller in this rewrite is literally named auto_login()
        // yet (grep-confirmed) -- ActivityService::record() still walks
        // debug_backtrace() looking for that bare function/method name, so
        // this closes the gap directly against a same-named private
        // helper below rather than a real call site.
        try {
            $this->auto_login();

            $details = $this->fetchDetails('user', 555, 'login');
            self::assertIsArray($details);
            self::assertSame('auto_login', $details['auth_function']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::activity() . ' WHERE object_id = 555');
        }
    }

    public function test_record_detects_the_auth_key_login_auth_function_from_the_call_stack(): void
    {
        try {
            $this->auth_key_login();

            $details = $this->fetchDetails('user', 556, 'login');
            self::assertIsArray($details);
            self::assertSame('auth_key_login', $details['auth_function']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::activity() . ' WHERE object_id = 556');
        }
    }

    public function test_record_marks_a_photo_add_as_browser_added_when_the_referer_is_the_admin_photos_add_page(): void
    {
        // added_with detection is hardcoded to the literal object 'photo'
        // (the fixture already has 5 real photo/add rows the repository's
        // own count-by-object test depends on, object_id 1-5), so this
        // can't use the 'test-' prefix tearDown() relies on -- a
        // disposable, non-colliding object_id cleaned up explicitly.
        $_SERVER['HTTP_REFERER'] = 'https://example.test/admin.php?page=photos_add';

        try {
            $this->service->record('photo', 888887, 'add');

            $details = $this->fetchDetails('photo', 888887, 'add');
            self::assertIsArray($details);
            self::assertSame('browser', $details['added_with']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::activity() . ' WHERE object_id = 888887');
        }
    }

    public function test_get_user_object_log_with_usernames_delegates_to_the_repository(): void
    {
        $rows = $this->service->getUserObjectLogWithUsernames('username', 'id');

        // Fixture: object='user' rows are activity_id 3, 4, 15, 16 (2
        // logins + 2 adds), all performed_by fixture_admin -- same baseline
        // ActivityRepositoryTest::test_find_user_object_log_with_usernames()
        // asserts directly against the repository.
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            self::assertInstanceOf(\Piwigo\Activity\Projection\UserActivityLogEntry::class, $row);
            self::assertSame('user', $row->object);
            self::assertSame('fixture_admin', $row->username);
        }
    }

    public function test_get_system_action_counts_by_object_id_delegates_to_the_repository(): void
    {
        // Fixture: activity_id 1 (object='system', object_id=3, action=
        // 'activate') and activity_id 2 (object='system', object_id=1,
        // action='install') -- see ActivityRepositoryTest's own fixture
        // docblock for the full row-by-row breakdown. Neither collides with
        // any object_id/action pair the record()-based tests above insert
        // (2/'restore').
        $rows = $this->service->getSystemActionCountsByObjectId();

        $activate = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['object_id'] === 3 && $row['action'] === 'activate'
        ));
        self::assertCount(1, $activate);
        self::assertSame('system', $activate[0]['object']);
        self::assertSame(1, $activate[0]['counter']);

        $install = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['object_id'] === 1 && $row['action'] === 'install'
        ));
        self::assertCount(1, $install);
        self::assertSame(1, $install[0]['counter']);
    }

    public function test_get_user_agent_breakdown_delegates_to_the_repository(): void
    {
        // Fixture: activity_id 3/4 (object='user', action='login', both
        // performed_by fixture_admin) both carry user_agent=
        // 'PiwigoFixtureRegen/1.0' -- see ActivityRepositoryTest's own
        // fixture docblock. Distinct from every user-agent value the
        // record()-based tests above insert ('TestAgent/1.0').
        $rows = $this->service->getUserAgentBreakdown();

        $matching = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['user_agent'] === 'PiwigoFixtureRegen/1.0'
        ));
        self::assertCount(1, $matching);
        self::assertSame(2, $matching[0]['counter']);
    }

    public function test_get_core_update_history_delegates_to_the_repository(): void
    {
        // No fixture row matches ('activity_id 2 is object_id=1/'install',
        // not 'update'/'autoupdate' -- see findCoreUpdateHistory()'s own
        // action filter), so this inserts its own disposable row directly
        // via the repository, same technique/shape as
        // ActivityRepositoryTest::test_find_core_update_history_filters_by_object_and_actions().
        $repo = EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Activity\ActivityEntity::class);
        self::assertInstanceOf(ActivityRepository::class, $repo);
        $repo->insertMany([
            [
                'object' => 'system',
                'objectId' => ActivitySystem::Core,
                'action' => 'update',
                'performedBy' => null,
                'sessionIdx' => 'sess-1',
                'ipAddress' => null,
                'occuredOn' => '2026-07-10 00:00:00',
                'details' => ['from_version' => '16.0.0', 'to_version' => '17.0.0'],
                'userAgent' => null,
            ],
        ]);

        try {
            $rows = $this->service->getCoreUpdateHistory();

            $matching = array_values(array_filter($rows, static fn (array $row): bool => $row['action'] === 'update'));
            self::assertCount(1, $matching);
            self::assertSame('2026-07-10 00:00:00', $matching[0]['occured_on']);
            self::assertIsString($matching[0]['details']);
            // MySQL's JSON column type reorders object members (by key
            // length, then lexicographically) independent of the original
            // insertion order -- ksort() both sides, matching this
            // codebase's own established convention for this gotcha (see
            // tests/Contract/WsImagesFilteredSearchTest.php's docblock).
            $expectedDetails = ['from_version' => '16.0.0', 'to_version' => '17.0.0'];
            $actualDetails = json_decode($matching[0]['details'], true);
            ksort($expectedDetails);
            self::assertIsArray($actualDetails);
            ksort($actualDetails);
            self::assertSame($expectedDetails, $actualDetails);
        } finally {
            $this->conn->executeStatement(
                "DELETE FROM " . Tables::activity() . " WHERE object = 'system' AND action = 'update' AND object_id = " . ActivitySystem::Core
            );
        }
    }

    // Named literally auto_login()/auth_key_login() on purpose -- see the
    // two test methods above; ActivityService::record() matches on the
    // bare function/method name from debug_backtrace(), not this class's
    // own naming convention.
    private function auto_login(): void
    {
        $this->service->record('user', 555, 'login');
    }

    private function auth_key_login(): void
    {
        $this->service->record('user', 556, 'login');
    }

    public function test_record_writes_a_null_performed_by_when_no_user_is_loaded(): void
    {
        // Real, adversarially-verified bug fixed in this same batch:
        // activity.performed_by has an ON DELETE SET NULL foreign key to
        // users.id, so the old `$user['id'] ?? 0` fallback (for "on a
        // plugin autoupdate, $user is not yet loaded", per the removed
        // comment) threw a real ForeignKeyConstraintViolationException on
        // every such write, since 0 is never a valid user id
        // (AUTO_INCREMENT starts at 1) -- this test would have failed with
        // that exception before the fix. CurrentUser::current()->isInitialized() is
        // always true by this point (setUp() already called
        // CurrentUser::current()->set()) -- resetRealUserResolvedFlag() is what
        // simulates "no real user resolved this request" now (Legacy
        // Coupling Retirement Phase 8, 8h).
        CurrentUser::current()->resetRealUserResolvedFlag();

        $this->service->record('test-no-user', 1, 'add');

        $performedBy = $this->conn->createQueryBuilder()
            ->select('performed_by')
            ->from(Tables::activity())
            ->where("object = 'test-no-user'")
            ->executeQuery()
            ->fetchOne();

        self::assertNull($performedBy);
    }

    private function countRows(string $object, int|string|null $objectId, string $action): int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::activity())
            ->where('object = :object')
            ->andWhere('action = :action')
            ->setParameter('object', $object)
            ->setParameter('action', $action);

        if ($objectId !== null) {
            $qb->andWhere('object_id = :objectId')
                ->setParameter('objectId', $objectId);
        }

        $value = $qb->executeQuery()->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDetails(string $object, int $objectId, string $action): ?array
    {
        $value = $this->conn->createQueryBuilder()
            ->select('details')
            ->from(Tables::activity())
            ->where('object = :object')
            ->andWhere('object_id = :objectId')
            ->andWhere('action = :action')
            ->setParameter('object', $object)
            ->setParameter('objectId', $objectId)
            ->setParameter('action', $action)
            ->executeQuery()
            ->fetchOne();

        if (! is_string($value)) {
            return null;
        }

        $details = json_decode($value, true);
        if (! is_array($details)) {
            return null;
        }

        $stringKeyed = [];
        foreach ($details as $key => $item) {
            if (is_string($key)) {
                $stringKeyed[$key] = $item;
            }
        }

        return $stringKeyed;
    }
}
}
