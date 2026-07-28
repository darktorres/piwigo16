<?php

declare(strict_types=1);

// tryLogUser() calls the real Piwigo\PluginConfig\EventDispatcher::get()->
// triggerChange() directly, a pure passthrough with no handlers
// registered, so no local stub is needed.

namespace Piwigo\Tests\Integration {

    use Piwigo\Auth\AuthRepository;
    use Piwigo\Auth\AuthService;
    use Piwigo\Auth\CookieService;
    use Piwigo\Auth\PasswordRepository;
    use Piwigo\Auth\PasswordService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Html\HtmlService;

    /**
     * Covers calculateAutoLoginKey() fully (a pure DB read + HMAC
     * computation, no session/cookie/legacy-activity side effects) and
     * tryLogUser()'s delegation to the try_log_user event (no handler is
     * registered in this harness, so EventDispatcher::triggerChange()
     * returns its own $data argument unchanged).
     * logUser()/autoLogin()/logoutUser() touch pwg_activity() (needs the
     * legacy $mysqli dblayer connection, not DBAL) and real PHP session
     * functions, which this lightweight Integration harness can't
     * exercise -- same limitation as GroupService, live-verified
     * separately instead. See tests/Integration/GroupServiceTest.php's
     * class docblock for the same reasoning in more detail.
     */
    final class AuthServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private AuthService $service;

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

            CurrentConfig::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfig::setUserFields(['id' => 'id', 'username' => 'username', 'password' => 'password']);
            CurrentConfig::setSecretKey('test-secret-key');

            $this->service = new AuthService(
                new AuthRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())),
                new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)),
                new HtmlService(),
                new PasswordService(new PasswordRepository(DbConnection::build())),
                new CookieService(),
            );
        }

        public function test_calculate_auto_login_key_returns_a_key_and_username_for_a_real_user(): void
        {
            $result = $this->service->calculateAutoLoginKey(1, 1000);

            self::assertIsString($result['key']);
            self::assertSame('fixture_admin', $result['username']);
        }

        public function test_calculate_auto_login_key_returns_false_for_a_missing_user(): void
        {
            $result = $this->service->calculateAutoLoginKey(999999, 1000);

            self::assertFalse($result['key']);
            self::assertSame('', $result['username']);
        }

        public function test_calculate_auto_login_key_is_stable_for_the_same_inputs(): void
        {
            $first = $this->service->calculateAutoLoginKey(1, 1000);
            $second = $this->service->calculateAutoLoginKey(1, 1000);

            self::assertSame($first['key'], $second['key']);
        }

        public function test_calculate_auto_login_key_changes_when_the_time_changes(): void
        {
            $first = $this->service->calculateAutoLoginKey(1, 1000);
            $second = $this->service->calculateAutoLoginKey(1, 2000);

            self::assertNotSame($first['key'], $second['key']);
        }

        public function test_calculate_auto_login_key_changes_when_the_secret_key_changes(): void
        {
            $first = $this->service->calculateAutoLoginKey(1, 1000);

            CurrentConfig::setSecretKey('a-different-secret');

            $second = $this->service->calculateAutoLoginKey(1, 1000);

            self::assertNotSame($first['key'], $second['key']);
        }

        public function test_try_log_user_fails_closed_when_no_handler_is_registered(): void
        {
            // No handler is registered for this event, so
            // EventDispatcher::triggerChange() returns its own $data
            // argument (false) unchanged.
            self::assertFalse($this->service->tryLogUser('anyone', 'anything', false));
        }

        public function test_find_user_by_username_or_email_matches_by_username(): void
        {
            $user = $this->service->findUserByUsernameOrEmail('fixture_admin');

            self::assertNotNull($user);
            self::assertSame('fixture_admin', $user->username);
        }

        public function test_find_user_by_username_or_email_returns_null_for_an_unknown_identifier(): void
        {
            self::assertNull($this->service->findUserByUsernameOrEmail('no-such-user-' . uniqid()));
        }

        public function test_has_already_logged_in_is_true_for_a_user_with_no_login_activity_history(): void
        {
            // Fixture user 4 (power_user) -- see this suite's own
            // fixture-shape memory notes; no login-activity rows exist for
            // it in the fixture, so countLoginActivity() === 0.
            self::assertTrue($this->service->hasAlreadyLoggedIn(4));
        }
    }
}
