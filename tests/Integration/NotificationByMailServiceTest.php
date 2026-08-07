<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Override;
    use Piwigo\Core\Kernel;
    use LogicException;
    use Piwigo\Db\EntityManagerFactory;
    use Doctrine\DBAL\Connection;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Notification\NotificationByMailService;
    use Piwigo\Notification\UserMailNotificationEntity;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;

/**
 * Same fixture shape as NotificationByMailRepositoryTest.
 */
final class NotificationByMailServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationByMailService $service;

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
        $this->service = new NotificationByMailService(EntityManagerFactory::build($this->conn)->getRepository(UserMailNotificationEntity::class), new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()));
    }

    public function test_find_available_check_key_matches_the_expected_shape(): void
    {
        $key = $this->service->findAvailableCheckKey();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $key);
    }

    public function test_find_available_check_key_never_collides_with_an_existing_row(): void
    {
        $key = $this->service->findAvailableCheckKey();

        self::assertSame(0, EntityManagerFactory::build($this->conn)->getRepository(UserMailNotificationEntity::class)->countByCheckKey($key));
    }

    public function test_get_user_notifications_returns_empty_for_an_unknown_action(): void
    {
        self::assertSame([], $this->service->getUserNotifications('bogus_action', [], ''));
    }

    public function test_get_user_notifications_with_bool_false_applies_no_filter(): void
    {
        // Matches the original's loose `!= ''` semantics: a bare `false`
        // means "no enabled filter", same as an empty string -- both
        // fixture subscribers come back.
        $rows = $this->service->getUserNotifications('subscribe', [], false);

        self::assertCount(2, $rows);
    }

    public function test_get_user_notifications_with_bool_true_filters_to_enabled(): void
    {
        $rows = $this->service->getUserNotifications('subscribe', [], true);

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }

    public function test_get_user_notifications_send_action_excludes_users_with_no_email(): void
    {
        $rows = $this->service->getUserNotifications('send', [], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }

    public function test_get_user_notifications_filters_non_string_check_keys(): void
    {
        // Mirrors a raw $_POST-shaped array (admin/notification_by_mail.php's
        // own calling convention): non-string elements must be silently
        // dropped, not passed through to the bound-parameter query.
        $rows = $this->service->getUserNotifications('subscribe', ['abcdef1234567890', 123, null, ['nested']], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }
}
}
