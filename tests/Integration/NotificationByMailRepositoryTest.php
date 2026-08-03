<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Notification\NotificationByMailRepository;

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

        $this->conn = DbConnection::build();
        $this->repo = new NotificationByMailRepository($this->conn);
    }

    public function test_count_by_check_key_finds_an_existing_key(): void
    {
        self::assertSame(1, $this->repo->countByCheckKey('abcdef1234567890'));
    }

    public function test_count_by_check_key_returns_zero_for_an_unknown_key(): void
    {
        self::assertSame(0, $this->repo->countByCheckKey('no-such-key'));
    }

    public function test_find_user_notifications_subscribe_returns_every_subscriber(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '');

        $usernames = array_column($rows, 'username');
        self::assertSame(['fixture_admin', 'regular_user'], $usernames);
    }

    public function test_find_user_notifications_send_excludes_users_with_no_email_or_disabled(): void
    {
        // regular_user has no email and is also disabled -- excluded either way.
        $rows = $this->repo->findUserNotifications('send', [], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }

    public function test_find_user_notifications_filters_by_check_key_list(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', ['abcdef1234567890'], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]->username);
    }

    public function test_find_user_notifications_filters_by_enabled_value(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '0');

        self::assertCount(1, $rows);
        self::assertSame('regular_user', $rows[0]->username);
    }

    public function test_find_user_notifications_narrows_user_id_to_a_real_int(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '');

        // UserMailNotification::fromRow() narrows user_id to a real int
        // (P17-23 Stage 1b), replacing the legacy \Piwigo\Db\MysqliDb::fetchAssoc()-style
        // "everything comes back as string|null" convention every other
        // domain's own Projection has already moved away from.
        self::assertSame(1, $rows[0]->userId);
    }

    public function test_delete_by_check_keys_is_a_no_op_for_an_empty_list(): void
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
     * SQL-modernization audit regression: check keys used to reach this
     * method already quote-wrapped by NotificationByMailSender::
     * quoteCheckKeyList() (`'\'' . $s . '\''`, zero escaping) and get
     * spliced into the query text -- now bound, and quoteCheckKeyList()
     * itself is gone (this was its only real caller). A check_key
     * containing a literal single quote would have broken the old manual
     * wrapping; confirms it deletes correctly instead.
     */
    public function test_delete_by_check_keys_removes_a_key_containing_a_single_quote(): void
    {
        $checkKey = "o'brien-" . bin2hex(random_bytes(4));
        // user 2 (guest) has no notification row in the fixture -- see
        // this class's own docblock.
        $this->repo->insertNotifications([
            ['user_id' => 2, 'check_key' => $checkKey, 'enabled' => 0],
        ]);
        self::assertSame(1, $this->repo->countByCheckKey($checkKey));

        $this->repo->deleteByCheckKeys([$checkKey]);

        self::assertSame(0, $this->repo->countByCheckKey($checkKey));
    }

    public function test_insert_notifications_is_a_no_op_for_an_empty_list(): void
    {
        $before = $this->countNotificationRows();

        $this->repo->insertNotifications([]);

        self::assertSame($before, $this->countNotificationRows());
    }

    private function countNotificationRows(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userMailNotification())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
