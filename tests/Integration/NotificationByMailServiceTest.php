<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\Kernel;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Db\TypedRepository;
    use Piwigo\Notification\NotificationByMailRepository;
    use Piwigo\Notification\NotificationByMailService;
    use Piwigo\Notification\UserMailNotificationEntity;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionRepository;
    use Piwigo\Session\SessionService;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\DbTransactionTestOverride;

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

            // PILOT (transaction-wrapping rollout): begin before any container
            // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
            // comment for the full reasoning.
            DbTransactionTestOverride::begin();

            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $this->conn = DbConnection::build();
            $this->service = new NotificationByMailService(TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(UserMailNotificationEntity::class), NotificationByMailRepository::class), new SessionService(TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), SessionRepository::class), CurrentConfigTestFactory::get()));
        }

        #[Override]
        protected function tearDown(): void
        {
            DbTransactionTestOverride::rollback();
            parent::tearDown();
        }

        public function testFindAvailableCheckKeyMatchesTheExpectedShape(): void
        {
            $key = $this->service->findAvailableCheckKey();

            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $key);
        }

        public function testFindAvailableCheckKeyNeverCollidesWithAnExistingRow(): void
        {
            $key = $this->service->findAvailableCheckKey();

            self::assertSame(0, TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(UserMailNotificationEntity::class), NotificationByMailRepository::class)->countByCheckKey($key));
        }

        public function testGetUserNotificationsReturnsEmptyForAnUnknownAction(): void
        {
            self::assertSame([], $this->service->getUserNotifications('bogus_action', [], ''));
        }

        public function testGetUserNotificationsWithBoolFalseAppliesNoFilter(): void
        {
            // Matches the original's loose `!= ''` semantics: a bare `false`
            // means "no enabled filter", same as an empty string -- both
            // fixture subscribers come back.
            $rows = $this->service->getUserNotifications('subscribe', [], false);

            self::assertCount(2, $rows);
        }

        public function testGetUserNotificationsWithBoolTrueFiltersToEnabled(): void
        {
            $rows = $this->service->getUserNotifications('subscribe', [], true);

            self::assertCount(1, $rows);
            self::assertSame('fixture_admin', $rows[0]->username);
        }

        public function testGetUserNotificationsSendActionExcludesUsersWithNoEmail(): void
        {
            $rows = $this->service->getUserNotifications('send', [], '');

            self::assertCount(1, $rows);
            self::assertSame('fixture_admin', $rows[0]->username);
        }

        public function testGetUserNotificationsFiltersNonStringCheckKeys(): void
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
