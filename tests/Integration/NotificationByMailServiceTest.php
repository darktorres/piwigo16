<?php

declare(strict_types=1);

// \Piwigo\Db\MysqliDb::booleanToString() is a real, pure, dependency-free function -- copied
// verbatim (same body as SearchServiceTest.php/ImageServiceTest.php;
// function_exists() guards mean whichever Integration test file's stub
// loads first wins for the whole run, so every file declaring it must
// keep the body identical). generate_key()'s own stub was removed (P23
// batch 8c) -- NotificationByMailService now calls the real
// Piwigo\Session\SessionService::get()->generateKey() directly, which
// this Integration test's real DB connection satisfies without a stub.
namespace {
    if (! function_exists('boolean_to_string')) {
        function boolean_to_string(mixed $var): mixed
        {
            if (is_bool($var)) {
                return $var ? 'true' : 'false';
            }

            return $var;
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Notification\NotificationByMailRepository;
    use Piwigo\Notification\NotificationByMailService;

/**
 * Same fixture shape as NotificationByMailRepositoryTest.
 */
final class NotificationByMailServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationByMailService $service;

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
        $this->service = new NotificationByMailService(new NotificationByMailRepository($this->conn));

        $GLOBALS['conf'] = [
            'user_fields' => ['username' => 'username', 'email' => 'mail_address', 'id' => 'id'],
        ];
    }

    public function test_find_available_check_key_matches_the_expected_shape(): void
    {
        $key = $this->service->findAvailableCheckKey();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $key);
    }

    public function test_find_available_check_key_never_collides_with_an_existing_row(): void
    {
        $key = $this->service->findAvailableCheckKey();

        self::assertSame(0, new NotificationByMailRepository($this->conn)->countByCheckKey($key));
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
        self::assertSame('fixture_admin', $rows[0]['username']);
    }

    public function test_get_user_notifications_send_action_excludes_users_with_no_email(): void
    {
        $rows = $this->service->getUserNotifications('send', [], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]['username']);
    }

    public function test_get_user_notifications_filters_non_string_check_keys(): void
    {
        // Mirrors a raw $_POST-shaped array (admin/notification_by_mail.php's
        // own calling convention): non-string elements must be silently
        // dropped, not passed through to the bound-parameter query.
        $rows = $this->service->getUserNotifications('subscribe', ['abcdef1234567890', 123, null, ['nested']], '');

        self::assertCount(1, $rows);
        self::assertSame('fixture_admin', $rows[0]['username']);
    }
}
}
