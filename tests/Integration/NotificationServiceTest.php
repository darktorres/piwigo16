<?php

declare(strict_types=1);

// NotificationService::newsExists() now calls Piwigo\Auth\AccessControl::
// isAdmin() directly (P23 batch 8d), which reads Piwigo\Users\CurrentUser
// (Legacy Coupling Retirement Track A batch A3) -- tests below seed
// CurrentUser instead.
namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Cache\CachePools;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Notification\NotificationRepository;
    use Piwigo\Notification\NotificationService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

/**
 * Same fixture shape as NotificationRepositoryTest.
 */
final class NotificationServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationService $service;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        $freshFixture = ! self::$fixtureReady;
        if ($freshFixture) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();

        if ($freshFixture) {
            // Same graduated-date restoration as NotificationRepositoryTest
            // (see that class's own docblock) -- the committed fixture
            // seeds every comment/image/user at one uniform timestamp,
            // this class's tests need distinguishable dates to exercise
            // date-RANGE filtering meaningfully.
            $this->conn->executeStatement('UPDATE ' . Tables::comments() . " SET validation_date = '2026-07-07 05:02:38' WHERE validated = 1");
            $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET date_available = '2026-07-07 05:02:36' WHERE id IN (1, 2)");
            $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET date_available = '2026-07-07 05:02:37' WHERE id IN (3, 4)");
            $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET date_available = '2026-07-07 05:02:38' WHERE id = 5");
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2026-07-07 05:02:35' WHERE user_id IN (1, 2)");
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2026-07-07 05:02:38' WHERE user_id IN (3, 4)");
        }

        CurrentUser::set(User::fromUserArray([
            'id' => 1,
            'status' => 'normal',
            'cache_update_time' => '2026-07-07 05:02:38',
            'forbidden_categories' => '0',
            'level' => '0',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
        ]));

        $this->service = new NotificationService(
            new NotificationRepository($this->conn),
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Category\CategoryEntity::class)),
            new HtmlService(),
            new UrlService(new HtmlService())
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        CachePools::notifications()->clear();

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
        CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Normal));

        self::assertTrue($this->service->newsExists('2026-07-07 05:02:36', '2026-07-07 05:02:38'));
    }

    public function test_news_exists_is_false_for_an_empty_window(): void
    {
        CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Normal));

        self::assertFalse($this->service->newsExists('2026-07-08 00:00:00', '2026-07-09 00:00:00'));
    }

    public function test_news_exists_considers_unvalidated_comments_only_for_admins(): void
    {
        // Insert a fresh unvalidated comment scoped to a date window with
        // no other real fixture activity, so only the admin-only
        // unvalidated-comments signal can fire in that window.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::comments() . " (image_id, date, author, anonymous_id, content, validated) VALUES (1, '2026-08-01 00:00:00', ?, ?, ?, 0)",
            ['test author', '127.0.0.9', 'pending test comment']
        );

        CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Normal));
        self::assertFalse($this->service->newsExists('2026-07-31 00:00:00', '2026-08-02 00:00:00'));

        CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Admin));
        self::assertTrue($this->service->newsExists('2026-07-31 00:00:00', '2026-08-02 00:00:00'));

        $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE author = 'test author'");
    }

    /**
     * CachePools::notifications() (gap-closure Stage 4a) replaces the
     * older PersistentCache/cacheUpdateTime mechanism -- proven the same
     * way TagServiceTest/ForbiddenCategoriesCacheTest prove their own pool
     * wiring: mutate the underlying data after the first (caching) call,
     * then show a 2nd call with the same params still returns the stale
     * (pre-mutation) result.
     */
    public function test_get_recent_post_dates_groups_and_caches(): void
    {
        $result = $this->service->getRecentPostDates(10, 2, 2);

        self::assertNotSame([], $result);

        $this->conn->executeStatement(
            "UPDATE " . Tables::images() . " SET date_available = '2099-01-01 00:00:00' WHERE id = 5"
        );

        try {
            $cached = $this->service->getRecentPostDates(10, 2, 2);
            self::assertSame($result, $cached, 'a cache hit must not re-query the DB');
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::images() . " SET date_available = '2026-07-07 05:02:38' WHERE id = 5"
            );
        }
    }

    public function test_get_recent_post_dates_array_applies_defaults(): void
    {
        $viaArray = $this->service->getRecentPostDatesArray([]);
        $viaDirect = $this->service->getRecentPostDates(3, 3, 3);

        self::assertSame($viaDirect, $viaArray);
    }
}
}
