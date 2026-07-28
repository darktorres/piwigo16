<?php

declare(strict_types=1);

// tryLogUser() calls the real Piwigo\PluginConfig\EventDispatcher::get()->
// triggerChange() directly, a pure passthrough with no handlers
// registered, so no local stub is needed.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Auth\ApiKeyRepository;
    use Piwigo\Auth\ApiKeyService;
    use Piwigo\Auth\AuthRepository;
    use Piwigo\Auth\AuthService;
    use Piwigo\Auth\CookieService;
    use Piwigo\Auth\PasswordRepository;
    use Piwigo\Auth\PasswordService;
    use Piwigo\Common\ValueObject\UserId;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Html\HtmlService;
    use Piwigo\Http\ResponseReadyException;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

    /**
     * Covers calculateAutoLoginKey() fully (a pure DB read + HMAC
     * computation, no session/cookie/legacy-activity side effects) and
     * tryLogUser()'s delegation to the try_log_user event (no handler is
     * registered in this harness, so EventDispatcher::triggerChange()
     * returns its own $data argument unchanged); also authKeyLogin()'s
     * every reject-before-logUser() branch, pwgLogin()'s early-success and
     * finalize_login-denial branches, logUser()'s 2 "hacking attempt"
     * branches (both throw via HtmlRenderingInterface::fatalError() before
     * any session/cookie code runs), createUserAuthKey()'s
     * duration-disabled branch, and generatePasswordLink()'s
     * firstLogin=false branch.
     *
     * logUser()'s remaining lines (the lang-cookie-sync path once past the
     * 2 hacking-attempt checks, the remember-me cookie set/clear, and the
     * session_start()/session_regenerate_id() switch) plus all of
     * autoLogin()'s remember-me-cookie-parsing and logoutUser() itself
     * depend on setcookie()/real PHP session functions actually taking
     * effect -- unlike CookieService's own setcookie() calls (safe to
     * assert on directly, see CookieServiceTest.php), these specific paths
     * were confirmed empirically (this same pass) to depend on session
     * state that behaves inconsistently once many other tests have already
     * run in this shared CLI process (matching
     * tests/Unit/Http/Middleware/SessionMiddlewareTest.php's own documented
     * finding for session_start()). Live-verified separately instead --
     * same limitation as GroupService's own pwg_activity()-adjacent gaps,
     * see tests/Integration/GroupServiceTest.php's class docblock.
     * createUserAuthKey()'s candidate-collision retry branch
     * (SessionService::generateKey(30) is a real CSPRNG read, not
     * seedable) and generatePasswordLink()'s strtotime() failure guard (an
     * always-valid duration never fails to parse) are both left uncovered
     * as genuinely unreachable/impractical-to-force, not overlooked.
     */
    final class AuthServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private AuthService $service;

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

            CurrentConfig::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfig::setUserFields(['id' => 'id', 'username' => 'username', 'password' => 'password']);
            CurrentConfig::setSecretKey('test-secret-key');

            $this->conn = DbConnection::build();

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

        public function test_log_user_treats_a_non_string_lang_cookie_as_a_hacking_attempt(): void
        {
            // A real request can never send a scalar $_COOKIE['lang'] as
            // an array (only a crafted 'lang[]=x&lang[]=y' request could),
            // but $_COOKIE is untyped -- logUser() defends against it
            // before touching any session/cookie code, so this is safe to
            // exercise directly.
            CurrentUser::set(new User(
                id: UserId::from(1),
                username: 'fixture_admin',
                email: '',
                language: 'en_UK',
                theme: '',
                status: UserStatus::Webmaster,
                enabledHigh: false,
            ));
            $_COOKIE['lang'] = ['unexpected', 'array'];

            // HtmlService::fatalError()'s own trigger_error(E_USER_ERROR)
            // hard-halts the script unless something intercepts it and
            // returns true -- real requests get this from
            // Piwigo\Core\ErrorCollector::install(), never called here (a
            // real set_error_handler()/register_shutdown_function() pair
            // would leak into every later test in this shared process,
            // same reasoning as TemplateDefineDerivativeTest's own
            // identical local handler).
            set_error_handler(static fn (): bool => true);
            try {
                $this->service->logUser(1, false);
                self::fail('Expected HtmlRenderingInterface::fatalError() to throw ResponseReadyException');
            } catch (ResponseReadyException $e) {
                self::assertSame(500, $e->response()->getStatusCode());
                self::assertStringContainsString(
                    '[Hacking attempt] the input parameter "lang" is not valid',
                    (string) $e->response()->getBody()
                );
            } finally {
                restore_error_handler();
                unset($_COOKIE['lang']);
            }
        }

        public function test_log_user_treats_an_unrecognised_language_code_as_a_hacking_attempt(): void
        {
            CurrentUser::set(new User(
                id: UserId::from(1),
                username: 'fixture_admin',
                email: '',
                language: 'en_UK',
                theme: '',
                status: UserStatus::Webmaster,
                enabledHigh: false,
            ));
            $_COOKIE['lang'] = 'zz_NOT_A_REAL_LANGUAGE';

            // See the previous test's own comment: a local, scoped error
            // handler is what lets fatalError()'s trigger_error(E_USER_ERROR)
            // return instead of hard-halting the script.
            set_error_handler(static fn (): bool => true);
            try {
                $this->service->logUser(1, false);
                self::fail('Expected HtmlRenderingInterface::fatalError() to throw ResponseReadyException');
            } catch (ResponseReadyException $e) {
                self::assertSame(500, $e->response()->getStatusCode());
                self::assertStringContainsString(
                    '[Hacking attempt] the input parameter "zz_NOT_A_REAL_LANGUAGE" is not valid',
                    (string) $e->response()->getBody()
                );
            } finally {
                restore_error_handler();
                unset($_COOKIE['lang']);
            }
        }

        public function test_pwg_login_returns_true_immediately_when_success_is_already_true(): void
        {
            // The $success===true short-circuit at the very top of
            // pwgLogin() -- reached e.g. when a plugin's own
            // 'try_log_user' handler already authenticated the user before
            // this default handler runs.
            self::assertTrue($this->service->pwgLogin(true, 'irrelevant', 'irrelevant', false));
        }

        public function test_pwg_login_denies_the_login_when_a_finalize_login_handler_blocks_it(): void
        {
            // fixture_admin / fixture_admin, per tests/Fixtures/README.md's
            // documented install credentials -- a real username+password
            // that passes pwgLogin()'s own password_verify() check, so
            // execution reaches the finalize_login trigger rather than
            // being rejected earlier for a wrong password.
            $handler = static function (array $state): array {
                $state['can_login'] = false;
                $state['reason'] = 'blocked_by_test_handler';
                return $state;
            };
            EventDispatcher::get()->addEventHandler('finalize_login', $handler);

            try {
                $result = $this->service->pwgLogin(false, 'fixture_admin', 'fixture_admin', false);

                self::assertFalse($result);
            } finally {
                EventDispatcher::get()->removeEventHandler('finalize_login', $handler);
            }
        }

        public function test_auth_key_login_rejects_a_key_with_an_invalid_format(): void
        {
            self::assertFalse($this->service->authKeyLogin('not-a-valid-key-format'));
        }

        public function test_auth_key_login_returns_false_for_a_wellformed_but_unknown_auth_key(): void
        {
            // 30 lowercase alnum chars matches the auth_key format regex
            // but was never inserted into user_auth_keys.
            self::assertFalse($this->service->authKeyLogin(str_repeat('a', 30)));
        }

        public function test_auth_key_login_rejects_an_expired_auth_key(): void
        {
            $created = $this->service->createUserAuthKey(4, 'normal');
            self::assertIsArray($created);

            $this->conn->executeStatement(
                'UPDATE ' . Tables::userAuthKeys() . " SET expired_on = '2000-01-01 00:00:00' WHERE auth_key = ?",
                [$created['auth_key']]
            );

            try {
                self::assertFalse($this->service->authKeyLogin($created['auth_key']));
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::userAuthKeys() . ' WHERE auth_key = ?', [$created['auth_key']]);
            }
        }

        public function test_auth_key_login_rejects_an_auth_key_whose_user_status_is_no_longer_eligible(): void
        {
            // The key was created while user 4 was 'normal' (the only
            // status createUserAuthKey() itself allows); promoting them to
            // 'admin' afterward -- e.g. an admin action taken between key
            // creation and its use -- exercises authKeyLogin()'s own
            // separate, defensive status re-check.
            $created = $this->service->createUserAuthKey(4, 'normal');
            self::assertIsArray($created);

            $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'admin' WHERE user_id = 4");

            try {
                self::assertFalse($this->service->authKeyLogin($created['auth_key']));
            } finally {
                $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'normal' WHERE user_id = 4");
                $this->conn->executeStatement('DELETE FROM ' . Tables::userAuthKeys() . ' WHERE auth_key = ?', [$created['auth_key']]);
            }
        }

        public function test_auth_key_login_rejects_an_api_key_with_the_wrong_secret(): void
        {
            $apiKeyService = new ApiKeyService(
                new \Piwigo\Mail\MailService(),
                new ApiKeyRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)),
                new PasswordService(new PasswordRepository($this->conn)),
                new UrlService(new HtmlService()),
            );
            $created = $apiKeyService->create(4, 30, 'Wrong Secret Test Key');

            // Tamper the last char of the 40-char plain secret so it no
            // longer password_verify()s against the stored hash, while
            // keeping the combined key's own format regex satisfied.
            $tamperedSecret = substr($created['apikey_secret'], 0, -1) . (str_ends_with($created['apikey_secret'], 'a') ? 'b' : 'a');

            try {
                self::assertFalse($this->service->authKeyLogin($created['auth_key'] . ':' . $tamperedSecret));
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::userAuthKeys() . ' WHERE auth_key = ?', [$created['auth_key']]);
            }
        }

        public function test_auth_key_login_rejects_a_revoked_api_key(): void
        {
            $apiKeyService = new ApiKeyService(
                new \Piwigo\Mail\MailService(),
                new ApiKeyRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)),
                new PasswordService(new PasswordRepository($this->conn)),
                new UrlService(new HtmlService()),
            );
            $created = $apiKeyService->create(4, 30, 'Revoked Test Key');
            $revoked = $apiKeyService->revoke(4, $created['auth_key']);
            self::assertTrue($revoked);

            try {
                self::assertFalse($this->service->authKeyLogin($created['auth_key'] . ':' . $created['apikey_secret']));
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::userAuthKeys() . ' WHERE auth_key = ?', [$created['auth_key']]);
            }
        }

        public function test_create_user_auth_key_returns_false_when_auth_key_duration_is_disabled(): void
        {
            CurrentConfig::setAuthKeyDuration(0);

            self::assertFalse($this->service->createUserAuthKey(4, 'normal'));
        }

        public function test_generate_password_link_computes_the_reset_link_when_not_the_first_login(): void
        {
            try {
                $result = $this->service->generatePasswordLink(4, new UrlService(new HtmlService()), false);

                self::assertStringContainsString('password.php?key=', $result['password_link']);
            } finally {
                $this->conn->executeStatement(
                    'UPDATE ' . Tables::userInfos() . ' SET activation_key = NULL, activation_key_expire = NULL WHERE user_id = 4'
                );
            }
        }
    }
}
