<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Notification\NotificationByMailRepository;

/**
 * Fixture shape: user_mail_notification has 2 rows -- user 1
 * (fixture_admin, email set, check_key 'abcdef1234567890', enabled='true'),
 * user 3 (regular_user, email NULL, check_key 'ghijkl9876543210',
 * enabled='false'). Users 2 (guest)/4 (power_user) have no notification row.
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

        Config::reset();
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
        $rows = $this->repo->findUserNotifications('subscribe', [], '', 'username', 'mail_address', 'id');

        $usernames = array_column($rows, 'username');
        self::assertSame(['fixture_admin', 'regular_user'], $usernames);
    }

    public function test_find_user_notifications_send_excludes_users_with_no_email_or_disabled(): void
    {
        // regular_user has no email and is also disabled -- excluded either way.
        $rows = $this->repo->findUserNotifications('send', [], '', 'username', 'mail_address', 'id');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]['username']);
    }

    public function test_find_user_notifications_filters_by_check_key_list(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', ['abcdef1234567890'], '', 'username', 'mail_address', 'id');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]['username']);
    }

    public function test_find_user_notifications_filters_by_enabled_value(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], 'false', 'username', 'mail_address', 'id');

        self::assertCount(1, $rows);
        self::assertSame('regular_user', $rows[0]['username']);
    }

    public function test_find_user_notifications_normalizes_numeric_columns_to_string(): void
    {
        $rows = $this->repo->findUserNotifications('subscribe', [], '', 'username', 'mail_address', 'id');

        // user_id is a native int under this project's DBAL/mysqli driver
        // config, but must come back as a string here, matching the legacy
        // pwg_db_fetch_assoc() contract downstream callers
        // (set_user_on_env_nbm() etc., untouched by this port) still rely
        // on -- proven at runtime, not just declared via the return type.
        self::assertIsString($rows[0]['user_id']);
        self::assertSame('1', $rows[0]['user_id']);
    }
}
