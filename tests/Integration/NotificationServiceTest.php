<?php

declare(strict_types=1);

// NotificationService::newsExists() calls the real is_admin() -- needs
// more bootstrap ($user global/access-level resolution) than this
// isolated integration test wants to depend on. Same "minimal stub to
// load standalone" pattern as CategoryServiceTest.php -- body copied
// verbatim (function_exists() guards mean whichever Integration test
// file's stub loads first wins for the whole run, so every file
// declaring this must keep the body identical).
namespace {
    if (! defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', './');
    }

    if (! function_exists('is_admin')) {
        function is_admin(string $user_status = ''): bool
        {
            if ($user_status !== '') {
                return $user_status === 'admin';
            }

            return (bool) ($GLOBALS['test_is_admin'] ?? false);
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Cache\PersistentFileCache;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Notification\NotificationRepository;
    use Piwigo\Notification\NotificationService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;

/**
 * Same fixture shape as NotificationRepositoryTest.
 */
final class NotificationServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationService $service;

    private Connection $conn;

    private string $cacheDir;

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

        // Same self-contained scratch cache dir pattern as
        // SearchServiceTest -- never the real _data/cache/.
        $this->cacheDir = dirname(__DIR__, 2) . '/_data/notification-service-test-cache';
        @mkdir($this->cacheDir . '/cache', 0o777, true);

        $GLOBALS['user'] = [
            'id' => 1,
            'cache_update_time' => '2026-07-07 05:02:38',
            'forbidden_categories' => '0',
            'level' => '0',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
        ];
        $GLOBALS['filter'] = [];
        $GLOBALS['conf'] = ['data_location' => '_data/notification-service-test-cache/'];

        $this->service = new NotificationService(
            new NotificationRepository($this->conn),
            new PermissionService(new PermissionRepository($this->conn), new GroupRepository($this->conn)),
            new PersistentFileCache()
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->cacheDir . '/cache/*.cache');
        foreach ($files !== false ? $files : [] as $file) {
            @unlink($file);
        }

        @rmdir($this->cacheDir . '/cache');
        @rmdir($this->cacheDir);

        parent::tearDown();
    }

    public function test_get_sql_where_restrict_filter_with_no_forbidden_categories(): void
    {
        $sql = $this->service->getSqlWhereRestrictFilter('WHERE', 'ic.image_id', true);

        self::assertStringStartsWith('WHERE', $sql);
    }

    public function test_custom_notification_query_returns_null_for_an_unknown_type(): void
    {
        self::assertNull($this->service->customNotificationQuery('count', 'bogus_type'));
    }

    public function test_custom_notification_query_counts_new_comments(): void
    {
        $result = $this->service->customNotificationQuery('count', 'new_comments', '2026-07-07 05:02:37', '2026-07-07 05:02:39');

        self::assertSame(4, $result);
    }

    public function test_custom_notification_query_returns_ids_for_info_action(): void
    {
        $result = $this->service->customNotificationQuery('info', 'new_comments', '2026-07-07 05:02:37', '2026-07-07 05:02:39');

        self::assertIsArray($result);
        sort($result);
        self::assertSame([1, 2, 3, 4], $result);
    }

    public function test_nb_new_elements_counts_in_range(): void
    {
        self::assertSame(3, $this->service->nbNewElements('2026-07-07 05:02:36', '2026-07-07 05:02:38'));
    }

    public function test_new_elements_returns_ids_in_range(): void
    {
        $ids = $this->service->newElements('2026-07-07 05:02:36', '2026-07-07 05:02:38');
        sort($ids);

        self::assertSame([3, 4, 5], $ids);
    }

    public function test_nb_new_users_counts_in_range(): void
    {
        self::assertSame(2, $this->service->nbNewUsers('2026-07-07 05:02:35', '2026-07-07 05:02:39'));
    }

    public function test_news_exists_is_true_when_new_elements_exist(): void
    {
        $GLOBALS['test_is_admin'] = false;

        self::assertTrue($this->service->newsExists('2026-07-07 05:02:36', '2026-07-07 05:02:38'));
    }

    public function test_news_exists_is_false_for_an_empty_window(): void
    {
        $GLOBALS['test_is_admin'] = false;

        self::assertFalse($this->service->newsExists('2026-07-08 00:00:00', '2026-07-09 00:00:00'));
    }

    public function test_news_exists_considers_unvalidated_comments_only_for_admins(): void
    {
        // Insert a fresh unvalidated comment scoped to a date window with
        // no other real fixture activity, so only the admin-only
        // unvalidated-comments signal can fire in that window.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::comments() . " (image_id, date, author, anonymous_id, content, validated) VALUES (1, '2026-08-01 00:00:00', ?, ?, ?, 'false')",
            ['test author', '127.0.0.9', 'pending test comment']
        );

        $GLOBALS['test_is_admin'] = false;
        self::assertFalse($this->service->newsExists('2026-07-31 00:00:00', '2026-08-02 00:00:00'));

        $GLOBALS['test_is_admin'] = true;
        self::assertTrue($this->service->newsExists('2026-07-31 00:00:00', '2026-08-02 00:00:00'));

        $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE author = 'test author'");
    }

    public function test_get_recent_post_dates_groups_and_caches(): void
    {
        $result = $this->service->getRecentPostDates(10, 2, 2);

        self::assertNotSame([], $result);
        $found = glob($this->cacheDir . '/cache/*.cache');
        self::assertNotSame([], $found !== false ? $found : []);

        $cached = $this->service->getRecentPostDates(10, 2, 2);
        self::assertSame($result, $cached);
    }

    public function test_get_recent_post_dates_array_applies_defaults(): void
    {
        $viaArray = $this->service->getRecentPostDatesArray([]);
        $viaDirect = $this->service->getRecentPostDates(3, 3, 3);

        self::assertSame($viaDirect, $viaArray);
    }
}
}
