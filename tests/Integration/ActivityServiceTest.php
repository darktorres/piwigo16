<?php

declare(strict_types=1);

// ActivityService::record() calls the real, stable, already-migrated
// script_basename() (needs full $_SERVER-driven bootstrap this isolated
// integration test doesn't load). Same "minimal stub to load standalone"
// pattern as tests/Integration/UserServiceTest.php -- copied verbatim
// from there (function_exists() guards mean whichever Integration test
// file's stub loads first wins for the whole test run, so every file
// that needs this function must declare an identical body).
namespace {
    if (! function_exists('script_basename')) {
        function script_basename(): string
        {
            /** @var array<string, mixed> $conf */
            global $conf;

            foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $key) {
                $raw = $_SERVER[$key] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $filename = strtolower($raw);
                    if ((bool) ($conf['php_extension_in_urls'] ?? false) && get_extension($filename) !== 'php') {
                        continue;
                    }

                    $basename = basename($filename, '.php');
                    if ($basename !== '') {
                        return $basename;
                    }
                }
            }

            return '';
        }
    }
}

namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $GLOBALS['conf'] = ['php_extension_in_urls' => false];
        $GLOBALS['user'] = ['id' => 1];
        unset($_REQUEST['method'], $_REQUEST['action'], $_GET['page'], $_POST['destination_tag'], $_SESSION['connected_with']);
        $_SERVER['SCRIPT_NAME'] = '/some/script.php';

        $this->conn = DbConnection::build();
        $this->service = new ActivityService(new ActivityRepository($this->conn));
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
        $GLOBALS['user'] = ['id' => 999999]; // should be ignored for logout

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
        $GLOBALS['user'] = ['id' => 3];

        $this->service->record('test-performer', 999, 'add');

        $performedBy = $this->conn->createQueryBuilder()
            ->select('performed_by')
            ->from(Tables::activity())
            ->where("object = 'test-performer'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame(3, $performedBy);
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
        // that exception before the fix.
        unset($GLOBALS['user']);

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

        $details = unserialize($value);
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
