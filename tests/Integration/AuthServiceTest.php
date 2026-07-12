<?php

declare(strict_types=1);

// tryLogUser() calls the real trigger_change() (unqualified, resolves to
// the global namespace) -- a stable, already-migrated function, but one
// that lives in include/functions_plugins.inc.php, which needs
// PHPWG_ROOT_PATH defined and pulls in the whole plugin-system stack this
// isolated integration test doesn't want to depend on. Same "minimal stub
// to load standalone" pattern as tests/Unit/PasswordHashTest.php, just
// needing PHP's bracketed namespace syntax here since this file's own test
// class must stay in Piwigo\Tests\Integration for PSR-4 discovery.
namespace {
    if (! function_exists('trigger_change')) {
        function trigger_change(string $event, mixed $data = null, mixed ...$args): mixed
        {
            return $data;
        }
    }
}

namespace Piwigo\Tests\Integration {

    use Piwigo\Auth\AuthRepository;
    use Piwigo\Auth\AuthService;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;

    /**
     * Covers calculateAutoLoginKey() fully (a pure DB read + HMAC
     * computation, no session/cookie/legacy-activity side effects) and
     * tryLogUser()'s delegation to the try_log_user event (stubbed above,
     * matching how the real trigger_change() behaves when no handler is
     * registered: it returns its own $data argument unchanged).
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

            Config::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $GLOBALS['conf'] = [
                'user_fields' => ['id' => 'id', 'username' => 'username', 'password' => 'password'],
                'secret_key' => 'test-secret-key',
            ];

            $this->service = new AuthService(new AuthRepository(DbConnection::build()));
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

            $GLOBALS['conf'] = [
                'user_fields' => ['id' => 'id', 'username' => 'username', 'password' => 'password'],
                'secret_key' => 'a-different-secret',
            ];

            $second = $this->service->calculateAutoLoginKey(1, 1000);

            self::assertNotSame($first['key'], $second['key']);
        }

        public function test_try_log_user_fails_closed_when_no_handler_is_registered(): void
        {
            // The stubbed trigger_change() above returns its own $data
            // argument (false) unchanged, matching the real function's
            // behavior when no handler is registered for the event.
            self::assertFalse($this->service->tryLogUser('anyone', 'anything', false));
        }
    }
}
