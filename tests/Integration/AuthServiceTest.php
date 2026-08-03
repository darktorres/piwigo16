<?php

declare(strict_types=1);

// tryLogUser() calls the real Piwigo\PluginConfig\EventDispatcher::get()->
// dispatchChange() directly, a pure passthrough with no handlers
// registered, so no local stub is needed.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Auth\ApiKeyRepository;
    use Piwigo\Auth\ApiKeyService;
    use Piwigo\Auth\AuthRepository;
    use Piwigo\Auth\AuthService;
    use Piwigo\Auth\CookieService;
    use Piwigo\Auth\Event\FinalizeLogin;
    use Piwigo\Auth\PasswordRepository;
    use Piwigo\Auth\PasswordService;
    use Piwigo\Common\ValueObject\UserId;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Event\User\TryLogUser;
    use Piwigo\Html\HtmlService;
    use Piwigo\Http\ResponseReadyException;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

    /**
     * Covers calculateAutoLoginKey() fully (a pure DB read + HMAC
     * computation, no session/cookie/legacy-activity side effects) and
     * tryLogUser()'s delegation to the TryLogUser event (no handler is
     * registered in this harness, so EventDispatcher::dispatchChange()
     * returns the same event object unchanged); also authKeyLogin()'s
     * every reject-before-logUser() branch, pwgLogin()'s early-success and
     * finalize_login-denial branches, logUser()'s 2 "hacking attempt"
     * branches (both throw via HtmlRenderingInterface::fatalError() before
     * any session/cookie code runs), createUserAuthKey()'s
     * duration-disabled branch, and generatePasswordLink()'s
     * firstLogin=false branch.
     *
     * logUser()'s lang-cookie-sync path (once past the 2 hacking-attempt
     * checks) and autoLogin()'s own remember-me-cookie-parsing are now
     * covered too (both wrapped in the same no-op set_error_handler()
     * this suite's hacking-attempt tests already established, needed
     * because the setcookie()/session_start() calls further down logUser()
     * unavoidably run alongside them and emit a real
     * E_WARNING("headers already sent") once Pest's own console output has
     * already occurred in this shared CLI process -- the same limitation
     * tests/Unit/Http/Middleware/SessionMiddlewareTest.php and
     * InstallWizardTest.php's own renderSuppressingHeaderWarnings()
     * document). logUser()'s remember-me-TRUE branch, the
     * session_start()/session_regenerate_id() switch itself, and
     * logoutUser() are exercised for real over genuine HTTP requests by
     * tests/Browser/RememberMeTest.php and
     * tests/Browser/IdentificationControllerTest.php instead -- those run
     * against a live Apache process, a separate PHP runtime the CLI
     * coverage collector attached to this Pest process can't see, so they
     * legitimately don't move this file's own line-coverage numbers even
     * though the behavior itself is real and tested.
     *
     * createUserAuthKey()'s candidate-collision retry branch is left
     * uncovered as genuinely impractical to force: both AuthRepository and
     * SessionService are `final` with no interface, so PHPUnit 12 refuses
     * to double either one (ClassIsFinalException), and
     * SessionService::generateKey(30)'s real random_bytes() CSPRNG can't be
     * seeded to force a collision against a pre-inserted row either.
     * generatePasswordLink()'s strtotime() failure guard is left uncovered
     * as genuinely unreachable, not just impractical: empirically (`php
     * -r`), every int $duration extreme enough to make
     * strtotime('now -' . $duration . ' second') return false is *also*
     * extreme enough that the earlier `(clone Env::now())->modify('+' .
     * $duration . ' seconds')` call (both config-typed `int` durations
     * this method reads always reach that line first) throws
     * DateMalformedStringException before execution ever gets this far --
     * confirmed across the full magnitude range where either function's
     * behavior changes, no int value threads the gap between the two.
     */
    final class AuthServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private AuthService $service;

        private Connection $conn;

        private \Piwigo\Auth\UserFailedLoginRepository $failedLoginRepo;

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

            $this->failedLoginRepo = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Auth\UserFailedLoginEntity::class);

            $this->service = new AuthService(
                new AuthRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())),
                new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)),
                new HtmlService(),
                new PasswordService(new PasswordRepository(DbConnection::build()), new \Piwigo\Config\DeploymentPolicy()),
                new CookieService(),
                $this->failedLoginRepo,
                new SessionService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class)),
                EventDispatcher::get(),
                \Piwigo\Core\PageState::current(),
            );
        }

        /**
         * AuthService::pwgLogin() is the real, registered try_log_user
         * handler -- it now takes/returns a TryLogUser event, not 4 loose
         * params, matching addTypedHandler()'s own contract.
         */
        private function pwgLoginResult(bool $success, string $username, ?string $password, bool $rememberMe): bool
        {
            return $this->service->pwgLogin(new TryLogUser($success, $username, $password, $rememberMe))->success;
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
            // EventDispatcher::dispatchChange() returns the same event
            // (constructed with success=false) unchanged.
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

            // HtmlService::fatalError() always throws ResponseReadyException
            // regardless of ErrorCollector::isActive() (see its own
            // docblock) -- this handler is now just belt-and-suspenders
            // against any incidental warning along the way, not load-
            // bearing for the throw itself. Left local rather than a real
            // Piwigo\Core\ErrorCollector::install() (a real
            // set_error_handler()/register_shutdown_function() pair would
            // leak into every later test in this shared process, same
            // reasoning as TemplateDefineDerivativeTest's own identical
            // local handler).
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

            // See the previous test's own comment.
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

        public function test_log_user_syncs_the_language_preference_and_clears_the_lang_cookie_when_it_differs(): void
        {
            // Only 'en_UK' ships in this suite's fixture `languages` table
            // (see the two hacking-attempt tests above, which rely on
            // *every* other code being rejected by
            // array_key_exists($lang_cookie, LangService::getLanguages()))
            // -- insert a second real, on-disk language row for the
            // duration of this test so this exact `if` has a genuinely
            // different, valid language to accept.
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::languages() . " (id, version, name) VALUES ('fr_FR', '16.3.0', 'Francais')"
            );

            CurrentUser::set(new User(
                id: UserId::from(1),
                username: 'fixture_admin',
                email: '',
                language: 'en_UK',
                theme: '',
                status: UserStatus::Webmaster,
                enabledHigh: false,
            ));
            $_COOKIE['lang'] = 'fr_FR';

            try {
                // Past both hacking-attempt guards, logUser() unconditionally
                // continues into the remember-me cookie set/clear and
                // session_start()/session_regenerate_id() switch below --
                // both setcookie() and session_start() emit a real
                // E_WARNING("headers already sent") once Pest's own console
                // output has already happened earlier in this shared CLI
                // process (the same CLI-SAPI limitation documented by
                // tests/Unit/Http/Middleware/SessionMiddlewareTest.php and
                // InstallWizardTest.php's own
                // renderSuppressingHeaderWarnings()); a plain `@` does not
                // stop PHPUnit's own ErrorHandler from surfacing them, so
                // this needs the same no-op error handler those suites use.
                set_error_handler(static fn (): bool => true);
                try {
                    $this->service->logUser(1, false);
                } finally {
                    restore_error_handler();
                }

                $language = $this->conn->fetchOne('SELECT language FROM ' . Tables::userInfos() . ' WHERE user_id = 1');
                self::assertSame('fr_FR', $language, 'logUser() should persist the lang cookie value to user_infos.language.');

                // setcookie('lang', '', ['expires' => time() - 3600]) itself
                // only ever mutates the outgoing response header -- this
                // process's own $_COOKIE superglobal is never touched by
                // setcookie() (that only affects the *next* real HTTP
                // request) -- so there's nothing further in-process to
                // assert on it directly; the DB write above plus the
                // absence of any uncaught warning/exception together prove
                // this exact branch (including the setcookie() call) ran.
            } finally {
                unset($_COOKIE['lang']);
                $this->conn->executeStatement("DELETE FROM " . Tables::languages() . " WHERE id = 'fr_FR'");
                $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET language = 'en_UK' WHERE user_id = 1");
                unset($_SESSION['pwg_uid']);
            }
        }

        public function test_auto_login_succeeds_for_a_valid_remember_me_cookie_and_marks_the_session_ui_context(): void
        {
            $remember_me_name = CurrentConfig::rememberMeName();
            $time = time();
            $calculated = $this->service->calculateAutoLoginKey(1, $time);
            self::assertIsString($calculated['key']);

            $_COOKIE[$remember_me_name] = 1 . '-' . $time . '-' . $calculated['key'];

            try {
                // autoLogin()'s success path unconditionally reaches
                // logUser(); see the lang-cookie-sync test above for why
                // that needs the same no-op error handler.
                //
                // Real bug, found live: autoLogin() sets $_SESSION[
                // 'connected_with'] = 'pwg_ui' BEFORE calling logUser(),
                // and logUser() itself only calls session_regenerate_id()
                // (which preserves the current $_SESSION content) when a
                // session is ALREADY active -- otherwise it calls
                // session_start(), which *reloads* $_SESSION from the
                // persisted (DB-backed) store, clobbering the in-memory
                // 'connected_with' write made moments earlier. A real HTTP
                // request's bootstrap chain always has an active session
                // by the time autoLogin() runs, so this never bites in
                // production -- but this CLI test process starts with no
                // active session, so it must start one first to match that
                // real precondition (confirmed live: without this,
                // connected_with reads back null every time).
                if (session_status() !== \PHP_SESSION_ACTIVE) {
                    set_error_handler(static fn (): bool => true);
                    try {
                        session_start();
                    } finally {
                        restore_error_handler();
                    }
                }

                set_error_handler(static fn (): bool => true);
                try {
                    $result = $this->service->autoLogin();
                } finally {
                    restore_error_handler();
                }

                self::assertTrue($result);
                // PageFilterHelper::scriptBasename() resolves to this test
                // binary's own invoking script name under CLI (never
                // literally "ws"), so the pwg_ui branch always applies here.
                self::assertSame('pwg_ui', $_SESSION['connected_with'] ?? null);
            } finally {
                unset($_COOKIE[$remember_me_name]);
                unset($_SESSION['pwg_uid'], $_SESSION['connected_with']);
            }
        }

        public function test_auto_login_clears_the_cookie_and_returns_false_for_a_malformed_remember_me_cookie(): void
        {
            $remember_me_name = CurrentConfig::rememberMeName();
            // 5 dash-separated parts -- is_string() passes and explode()
            // runs, but count($cookie) === 3 fails immediately,
            // short-circuiting the rest of the compound condition. Exercises
            // the fallback cleanup setcookie() at the bottom of autoLogin()
            // instead of the success path the test above already covers.
            $_COOKIE[$remember_me_name] = 'not-a-valid-cookie-format';

            try {
                set_error_handler(static fn (): bool => true);
                try {
                    $result = $this->service->autoLogin();
                } finally {
                    restore_error_handler();
                }

                self::assertFalse($result);
            } finally {
                unset($_COOKIE[$remember_me_name]);
            }
        }

        public function test_pwg_login_returns_true_immediately_when_success_is_already_true(): void
        {
            // The $success===true short-circuit at the very top of
            // pwgLogin() -- reached e.g. when a plugin's own
            // 'try_log_user' handler already authenticated the user before
            // this default handler runs.
            self::assertTrue($this->pwgLoginResult(true, 'irrelevant', 'irrelevant', false));
        }

        public function test_pwg_login_denies_the_login_when_a_finalize_login_handler_blocks_it(): void
        {
            // fixture_admin / fixture_admin, per tests/Fixtures/README.md's
            // documented install credentials -- a real username+password
            // that passes pwgLogin()'s own password_verify() check, so
            // execution reaches the finalize_login trigger rather than
            // being rejected earlier for a wrong password.
            $handler = static function (FinalizeLogin $event): FinalizeLogin {
                return new FinalizeLogin(
                    ['can_login' => false, 'reason' => 'blocked_by_test_handler', 'authenticated' => $event->state['authenticated']],
                    $event->userFound,
                    $event->rememberMe,
                );
            };
            EventDispatcher::get()->addTypedHandler(FinalizeLogin::class, $handler);

            try {
                $result = $this->pwgLoginResult(false, 'fixture_admin', 'fixture_admin', false);

                self::assertFalse($result);
            } finally {
                EventDispatcher::get()->removeEventHandler(FinalizeLogin::class, $handler);
                $this->conn->executeStatement('DELETE FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
            }
        }

        public function test_pwg_login_records_a_failed_login_row_for_a_wrong_password(): void
        {
            $countFailedLoginsForFixtureAdmin = function (): int {
                $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
                return is_numeric($count) ? (int) $count : 0;
            };

            $before = $countFailedLoginsForFixtureAdmin();

            try {
                $result = $this->pwgLoginResult(false, 'fixture_admin', 'definitely-wrong-password', false);

                self::assertFalse($result);
                self::assertSame($before + 1, $countFailedLoginsForFixtureAdmin());
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
            }
        }

        public function test_pwg_login_locks_out_the_username_after_max_attempts_even_with_the_correct_password(): void
        {
            // Empty $_SERVER['REMOTE_ADDR'] in this CLI test process means
            // pwgLogin()'s ip-scoped check never fires (its own '$ip !== ""'
            // guard), so this exercises the username-scoped lockout alone.
            CurrentConfig::setLoginLockoutMaxAttempts(3);

            try {
                for ($i = 0; $i < 3; $i++) {
                    self::assertFalse($this->pwgLoginResult(false, 'fixture_admin', 'definitely-wrong-password', false));
                }

                // generateFakeUser() is the only thing that ever sets this
                // -- unset it first so the assertion below can tell whether
                // pwgLogin() reached it on this specific call.
                unset($_SESSION['fake_user_cache']);

                $result = $this->pwgLoginResult(false, 'fixture_admin', 'fixture_admin', false);

                self::assertFalse($result, 'Expected pwgLogin() to reject a locked-out username even with the correct password.');
                self::assertArrayNotHasKey(
                    'fake_user_cache',
                    $_SESSION,
                    'pwgLogin() should fast-reject a locked-out username before reaching generateFakeUser()/password_verify().'
                );
            } finally {
                unset($_SESSION['fake_user_cache']);
                $this->conn->executeStatement('DELETE FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
            }
        }

        public function test_pwg_login_fast_rejects_a_locked_out_username_via_the_user_scoped_lockout_block_directly(): void
        {
            // A minimal, deterministic reproduction of the user-scoped
            // lockout block itself, isolated from
            // test_pwg_login_locks_out_the_username_after_max_attempts_even_with_the_correct_password()'s
            // own 3-attempt loop above: with maxAttempts=1, the single
            // real failure below is recorded by the *separate*
            // "wrong password" block further down pwgLogin() (already
            // covered by test_pwg_login_records_a_failed_login_row_for_a_
            // wrong_password() above), and only the *second* call --
            // this time with the correct password -- is old enough to be
            // fast-rejected by the user-scoped lockout block itself.
            //
            // Real bug, found live: pwgLogin()'s IP-scoped lockout check
            // runs FIRST, before the username is even resolved, and only
            // when $_SERVER['REMOTE_ADDR'] is non-empty (see
            // test_pwg_login_locks_out_the_username_after_max_attempts_
            // even_with_the_correct_password()'s own docblock: this CLI
            // process normally has no REMOTE_ADDR at all, which is exactly
            // what lets every *other* test in this file exercise the
            // user-scoped block in isolation). If some other Integration
            // test file leaves REMOTE_ADDR set (or a real request context
            // does), and a matching recent failedLoginRepo row exists for
            // that IP, the IP-scoped check fires first and records under
            // ip/user_id=NULL -- invisible to a `WHERE user_id = 1` count,
            // and this test's own very first pwgLogin() call never reaches
            // the "record a wrong-password failure" branch at all, so
            // $afterFirstFailure reads back 0 instead of 1. Force
            // REMOTE_ADDR to the same guaranteed-empty state every sibling
            // test here relies on implicitly, and clear any leftover
            // user-scoped row too rather than trusting either precondition.
            // Assigned, never unset()'d -- IpAddress::fromRemoteAddr() ->
            // tryFrom('') rejects an empty string as an invalid IP just
            // like a missing key, so this reaches the exact same $ip = ''
            // outcome pwgLogin() needs without an unset() PHPStan can't
            // reason about the shape of $_SERVER through.
            $originalRemoteAddr = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
            $_SERVER['REMOTE_ADDR'] = '';
            $this->conn->executeStatement('DELETE FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
            CurrentConfig::setLoginLockoutMaxAttempts(1);

            $countFailedLoginsForFixtureAdmin = function (): int {
                $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
                return is_numeric($count) ? (int) $count : 0;
            };

            try {
                self::assertFalse($this->pwgLoginResult(false, 'fixture_admin', 'definitely-wrong-password', false));
                $afterFirstFailure = $countFailedLoginsForFixtureAdmin();

                unset($_SESSION['fake_user_cache']);

                $result = $this->pwgLoginResult(false, 'fixture_admin', 'fixture_admin', false);

                self::assertFalse($result, 'Expected the user-scoped lockout block to reject even a correct password.');
                self::assertArrayNotHasKey(
                    'fake_user_cache',
                    $_SESSION,
                    'pwgLogin() should fast-reject via the lockout block before reaching generateFakeUser()/password_verify().'
                );
                self::assertSame(
                    $afterFirstFailure + 1,
                    $countFailedLoginsForFixtureAdmin(),
                    'The lockout block itself calls recordFailure() a second time.'
                );
            } finally {
                unset($_SESSION['fake_user_cache']);
                $this->conn->executeStatement('DELETE FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 1');
                $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
            }
        }

        public function test_pwg_login_locks_out_by_ip_even_for_an_unknown_username(): void
        {
            CurrentConfig::setLoginLockoutMaxAttempts(3);
            $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.55';

            try {
                for ($i = 0; $i < 3; $i++) {
                    self::assertFalse($this->pwgLoginResult(false, 'no-such-user-' . $i . '-' . uniqid(), 'irrelevant', false));
                }

                unset($_SESSION['fake_user_cache']);

                // A brand-new, never-before-seen username -- proves the
                // lockout is keyed on the IP, not on having seen this exact
                // username fail before.
                $result = $this->pwgLoginResult(false, 'no-such-user-final-' . uniqid(), 'irrelevant', false);

                self::assertFalse($result);
                self::assertArrayNotHasKey(
                    'fake_user_cache',
                    $_SESSION,
                    'pwgLogin() should fast-reject a locked-out IP before reaching generateFakeUser()/password_verify().'
                );
            } finally {
                unset($_SESSION['fake_user_cache']);
                if ($originalRemoteAddr === null) {
                    unset($_SERVER['REMOTE_ADDR']);
                } else {
                    $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
                }
                $this->conn->executeStatement("DELETE FROM " . Tables::userFailedLogins() . " WHERE ip = '203.0.113.55'");
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
                new PasswordService(new PasswordRepository($this->conn), new \Piwigo\Config\DeploymentPolicy()),
                new UrlService(new HtmlService()),
                new SessionService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class)),
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
                new PasswordService(new PasswordRepository($this->conn), new \Piwigo\Config\DeploymentPolicy()),
                new UrlService(new HtmlService()),
                new SessionService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class)),
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

        public function test_generate_password_link_still_works_for_a_user_locked_out_of_pwg_login(): void
        {
            // generatePasswordLink() is the password-reset escape hatch --
            // it never routes through pwgLogin()/tryLogUser()/logUser(), so
            // a username-scoped lockout on user 4 must not affect it.
            CurrentConfig::setLoginLockoutMaxAttempts(3);

            for ($i = 0; $i < 3; $i++) {
                self::assertFalse($this->pwgLoginResult(false, 'power_user', 'definitely-wrong-password', false));
            }

            try {
                // Confirm the lockout genuinely took effect first, so this
                // test would actually fail if generatePasswordLink() ever
                // started depending on pwgLogin()'s own state. (The
                // narrower claim that this fast-rejects without calling
                // password_verify() is already covered by
                // test_pwg_login_locks_out_the_username_after_max_attempts_even_with_the_correct_password().)
                self::assertFalse($this->pwgLoginResult(false, 'power_user', 'anything', false));

                $result = $this->service->generatePasswordLink(4, new UrlService(new HtmlService()), false);

                self::assertStringContainsString('password.php?key=', $result['password_link']);
            } finally {
                $this->conn->executeStatement(
                    'UPDATE ' . Tables::userInfos() . ' SET activation_key = NULL, activation_key_expire = NULL WHERE user_id = 4'
                );
                $this->conn->executeStatement('DELETE FROM ' . Tables::userFailedLogins() . ' WHERE user_id = 4');
            }
        }
    }
}
