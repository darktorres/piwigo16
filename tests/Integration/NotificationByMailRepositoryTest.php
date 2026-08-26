<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\Projection\NotificationInsertRow;
use Piwigo\Notification\UserMailNotificationEntity;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Fixture shape: user_mail_notification has 2 rows -- user 1
 * (fixture_admin, email set, check_key 'abcdef1234567890', enabled=1),
 * user 3 (regular_user, email NULL, check_key 'ghijkl9876543210',
 * enabled=0). Users 2 (guest)/4 (power_user) have no notification row.
 */
final class NotificationByMailRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationByMailRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
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
        $this->repo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(UserMailNotificationEntity::class), NotificationByMailRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testCountByCheckKeyFindsAnExistingKey(): void
    {
        self::assertSame(1, $this->repo->countByCheckKey('abcdef1234567890'));
    }

    public function testCountByCheckKeyReturnsZeroForAnUnknownKey(): void
    {
        self::assertSame(0, $this->repo->countByCheckKey('no-such-key'));
    }

    public function testFindUserNotificationsSubscribeReturnsEverySubscriber(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '');

        $usernames = array_column($rows, 'username');
        self::assertSame(['fixture_admin', 'regular_user'], $usernames);
    }

    public function testFindUserNotificationsSendExcludesUsersWithNoEmailOrDisabled(): void
    {
        // regular_user has no email and is also disabled -- excluded either way.
        $rows = $this->repo->findUserNotifications('send', [], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }

    public function testFindUserNotificationsFiltersByCheckKeyList(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', ['abcdef1234567890'], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }

    public function testFindUserNotificationsFiltersByEnabledValue(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '0');

        self::assertCount(1, $rows);
        self::assertSame('regular_user', $rows[0]->username);
    }

    public function testFindUserNotificationsNarrowsUserIdToARealUserId(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '');

        // UserMailNotification::fromRow() narrows user_id to a real
        // UserId, unlike the legacy \Piwigo\Db\MysqliDb::fetchAssoc()-style
        // "everything comes back as string|null" convention every other
        // domain's own Projection has already moved away from.
        self::assertEquals(UserId::from(1), $rows[0]->userId);
    }

    public function testDeleteByCheckKeysIsANoOpForAnEmptyList(): void
    {
        $before = $this->countNotificationRows();

        $this->repo->deleteByCheckKeys([]);

        // Guards against building `DELETE FROM ... WHERE check_key IN ()`
        // -- invalid SQL -- for an empty list; the fixture's 2 real rows
        // must survive untouched, proving the early return (not a real,
        // scoped-to-nothing DELETE) is what actually ran.
        self::assertSame($before, $this->countNotificationRows());
    }

    /**
     * check_key values are bound, not quoted -- a check_key containing a
     * literal single quote would have broken the old manual
     * `'\'' . $s . '\''`-style wrapping this method used to receive from
     * NotificationByMailSender::quoteCheckKeyList() (now removed);
     * confirms it deletes correctly instead.
     */
    public function testDeleteByCheckKeysRemovesAKeyContainingASingleQuote(): void
    {
        $checkKey = "o'brien-" . bin2hex(random_bytes(4));
        // user 2 (guest) has no notification row in the fixture -- see
        // this class's own docblock.
        $this->repo->insertNotifications([
            new NotificationInsertRow(userId: 2, checkKey: $checkKey, enabled: 0),
        ]);
        self::assertSame(1, $this->repo->countByCheckKey($checkKey));

        $this->repo->deleteByCheckKeys([$checkKey]);

        self::assertSame(0, $this->repo->countByCheckKey($checkKey));
    }

    public function testInsertNotificationsIsANoOpForAnEmptyList(): void
    {
        $before = $this->countNotificationRows();

        $this->repo->insertNotifications([]);

        self::assertSame($before, $this->countNotificationRows());
    }

    private function countNotificationRows(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_mail_notification')
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
