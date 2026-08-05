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
    use Piwigo\Lang\Translator;
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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();

        if ($freshFixture) {
            // Same graduated-date restoration as NotificationRepositoryTest
            // (see that class's own docblock) -- the committed fixture
            // seeds every comment/image/user at one uniform timestamp,
            // this class's tests need distinguishable dates to exercise
            // date-RANGE filtering meaningfully.
            // validated is a genuine boolean column -- a bare `1` literal
            // in the SQL text (unlike a bound parameter, which the driver
            // coerces implicitly) is rejected outright by Postgres.
            $validatedLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement('UPDATE ' . Tables::comments() . " SET validation_date = '2026-07-07 05:02:38' WHERE validated = {$validatedLiteral}");
            $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET date_available = '2026-07-07 05:02:36' WHERE id IN (1, 2)");
            $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET date_available = '2026-07-07 05:02:37' WHERE id IN (3, 4)");
            $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET date_available = '2026-07-07 05:02:38' WHERE id = 5");
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2026-07-07 05:02:35' WHERE user_id IN (1, 2)");
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2026-07-07 05:02:38' WHERE user_id IN (3, 4)");
        }

        CurrentUser::current()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'normal',
            'cache_update_time' => '2026-07-07 05:02:38',
            'forbidden_categories' => '0',
            'level' => '0',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
        ]));

        $filterState = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\FilterState::class);
        if (! $filterState instanceof \Piwigo\Core\FilterState) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\FilterState::class);
        }

        $this->service = new NotificationService(
            \Piwigo\Core\Lang::current(),
            \Piwigo\Auth\AccessControl::current(),
            new NotificationRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)),
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn), $currentConfig), \Piwigo\Users\CurrentUser::current(), $filterState),
            \Piwigo\Tests\Support\HtmlServiceTestFactory::build(),
            \Piwigo\Tests\Support\UrlServiceTestFactory::build(),
            new Translator(\Piwigo\Config\CurrentConfig::current()),
            \Piwigo\Users\CurrentUser::current(),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        CachePools::notifications()->clear();

        parent::tearDown();
    }

    public function test_get_comments_restrict_condition_with_the_sentinel_forbidden_categories_value(): void
    {
        // SQL-modernization audit: was getSqlWhereRestrictFilter(),
        // returning an already-'WHERE '-prefixed raw string -- now
        // getCommentsRestrictCondition() (Item 14 Sub-phase C1's typed
        // replacement for the old getSqlWhereRestrictCondition()),
        // returning a SqlCondition whose own ->sql has no prefix (callers
        // compose it via QueryBuilder::andWhere() instead).
        //
        // setUp() seeds forbiddenCategories: '0' -- PermissionService's
        // own sentinel for "no real forbidden categories" (see
        // PermissionService::getForbiddenCategories()'s own docblock:
        // the list always contains at least 0, a nonexistent category,
        // so `NOT IN (0)` is always true) -- not an EMPTY string, so this
        // still produces a real (if practically always-true) bound NOT IN
        // fragment.
        $condition = $this->service->getCommentsRestrictCondition();

        self::assertFalse($condition->isEmpty());
        self::assertStringContainsString('NOT IN', $condition->sql);
        $key = array_key_first($condition->parameters);
        self::assertIsString($key);
        self::assertSame([0], $condition->parameters[$key]);
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

    /**
     * Exercises the 'new_elements'/'updated_categories' match arm of
     * customNotificationQuery()'s own internal `$restrictSql` selection --
     * distinct from the 'new_comments' arm already covered above, and from
     * the 'default' (empty restrictSql) arm covered by the new_users test
     * just below.
     */
    public function test_custom_notification_query_counts_updated_categories(): void
    {
        // Fixture image_category rows (image_id => category_id): 1,2,3 => 1
        // and 4,5 => 2 (confirmed against tests/Fixtures/piwigo-17.0.sql
        // directly). Images 3/4/5 fall in this date_available window (same
        // window as test_nb_new_elements_counts_in_range), touching
        // category 1 (via image 3) and category 2 (via images 4 and 5) --
        // 2 distinct categories.
        $result = $this->service->customNotificationQuery('count', 'updated_categories', '2026-07-07 05:02:36', '2026-07-07 05:02:38');

        self::assertSame(2, $result);
    }

    /**
     * 'new_users' (like 'unvalidated_comments') falls through to
     * customNotificationQuery()'s `default => ''` restrictSql arm --
     * distinct from both arms above.
     */
    public function test_custom_notification_query_counts_new_users_via_default_restrict_arm(): void
    {
        $result = $this->service->customNotificationQuery('count', 'new_users', '2026-07-07 05:02:35', '2026-07-07 05:02:39');

        self::assertSame(2, $result);
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

    public function test_new_comments_returns_ids_in_range(): void
    {
        // Direct call to newComments() itself (as opposed to
        // customNotificationQuery('info', 'new_comments', ...), already
        // covered above) -- same window/expected ids as that test, proving
        // the convenience wrapper delegates correctly on its own.
        $ids = $this->service->newComments('2026-07-07 05:02:37', '2026-07-07 05:02:39');
        sort($ids);

        self::assertSame([1, 2, 3, 4], $ids);
    }

    public function test_nb_new_users_counts_in_range(): void
    {
        self::assertSame(2, $this->service->nbNewUsers('2026-07-07 05:02:35', '2026-07-07 05:02:39'));
    }

    public function test_updated_categories_returns_ids_in_range(): void
    {
        // Same window as test_nb_new_elements_counts_in_range/
        // test_custom_notification_query_counts_updated_categories -- see
        // that test's own comment for the image->category fixture mapping
        // that makes [1, 2] the correct distinct category id list.
        $ids = $this->service->updatedCategories('2026-07-07 05:02:36', '2026-07-07 05:02:38');
        sort($ids);

        self::assertSame([1, 2], $ids);
    }

    public function test_new_users_returns_ids_in_range(): void
    {
        // user_id 3 (regular_user) and 4 (power_user) are the fixture rows
        // whose registration_date was moved to 05:02:38 in setUp(); 1/2
        // (fixture_admin/guest) sit at 05:02:35, excluded by the
        // exclusive-start '> $start' comparison the repository uses.
        $ids = $this->service->newUsers('2026-07-07 05:02:35', '2026-07-07 05:02:39');
        sort($ids);

        self::assertSame([3, 4], $ids);
    }

    public function test_news_exists_is_true_when_new_elements_exist(): void
    {
        CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Normal));

        self::assertTrue($this->service->newsExists('2026-07-07 05:02:36', '2026-07-07 05:02:38'));
    }

    public function test_news_exists_is_false_for_an_empty_window(): void
    {
        CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Normal));

        self::assertFalse($this->service->newsExists('2026-07-08 00:00:00', '2026-07-09 00:00:00'));
    }

    public function test_news_exists_considers_unvalidated_comments_only_for_admins(): void
    {
        // Insert a fresh unvalidated comment scoped to a date window with
        // no other real fixture activity, so only the admin-only
        // unvalidated-comments signal can fire in that window.
        // validated is a genuine boolean column -- a bare `0` literal in
        // the SQL text (unlike a bound parameter, which the driver
        // coerces implicitly) is rejected outright by Postgres.
        $validatedLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::comments() . " (image_id, date, author, anonymous_id, content, validated) VALUES (1, '2026-08-01 00:00:00', ?, ?, ?, {$validatedLiteral})",
            ['test author', '127.0.0.9', 'pending test comment']
        );

        CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Normal));
        self::assertFalse($this->service->newsExists('2026-07-31 00:00:00', '2026-08-02 00:00:00'));

        CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Admin));
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

    public function test_news_appends_the_auth_key_to_every_generated_url(): void
    {
        CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Normal));

        // This window has new elements, updated categories, and new
        // comments all firing at once (see the elements/categories/
        // comments tests above using the same or an overlapping window),
        // so every addUrlParams() call site inside news() that carries
        // $addUrlParams runs at least once.
        $news = $this->service->news(
            '2026-07-07 05:02:36',
            '2026-07-07 05:02:38',
            excludeImgCats: false,
            addUrl: true,
            authKey: 'notif-auth-marker-77',
        );

        self::assertNotSame([], $news);
        self::assertStringContainsString('auth=notif-auth-marker-77', implode('', $news));
    }

    public function test_news_omits_the_auth_param_entirely_when_no_auth_key_is_given(): void
    {
        CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Normal));

        $news = $this->service->news(
            '2026-07-07 05:02:36',
            '2026-07-07 05:02:38',
            excludeImgCats: false,
            addUrl: true,
        );

        self::assertNotSame([], $news);
        self::assertStringNotContainsString('auth=', implode('', $news));
    }

    public function test_get_html_description_recent_post_date_appends_the_auth_key_to_its_url(): void
    {
        $html = $this->service->getHtmlDescriptionRecentPostDate(
            ['date_available' => '2026-07-15', 'nb_elements' => 5, 'nb_cats' => 2],
            'notif-auth-marker-88',
        );

        self::assertStringContainsString('auth=notif-auth-marker-88', $html);
    }

    public function test_get_html_description_recent_post_date_omits_the_auth_param_when_no_auth_key_is_given(): void
    {
        $html = $this->service->getHtmlDescriptionRecentPostDate(
            ['date_available' => '2026-07-15', 'nb_elements' => 5, 'nb_cats' => 2],
        );

        self::assertStringNotContainsString('auth=', $html);
    }
}
}
