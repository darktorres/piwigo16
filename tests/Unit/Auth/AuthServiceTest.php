<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\Event\TryLogUser;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\Projection\AuthUser;
use Piwigo\Auth\Projection\CreatedUserAuthKey;
use Piwigo\Auth\Projection\FinalizeLoginDecision;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Auth\AuthService -- has its own dedicated
 * tests/Integration/AuthServiceTest.php (29 tests); this ports them
 * down to the Unit suite via the real-DB-no-HTTP pattern. 292-line gap,
 * 0 existing Unit tests before this. Last item in Tier 2.
 *
 * Kernel::boot() IS needed here, for the whole file -- AuthService's
 * own constructor takes Paths, and CurrentPathsTestFactory::get()
 * throws unless Kernel has booted (unlike most other TestFactories,
 * which degrade to a fallback instance).
 *
 * The Integration original wraps every logUser()/autoLogin() call in a
 * local `set_error_handler()`/`restore_error_handler()` pair to
 * suppress a real "headers already sent" E_WARNING that PHPUnit's own
 * CLI runner triggers once console output has already occurred in the
 * same process. Confirmed empirically (matching
 * tests/Unit/Http/Middleware/SessionMiddlewareTest.php's own documented
 * finding) that Pest's CLI runner does NOT have this problem --
 * session_start()/setcookie() run clean here with no warning -- so that
 * wrapping is dropped in this port.
 *
 * user_failed_logins is append-only with no natural unique key
 * (UserFailedLoginRepositoryTest.php's own docblock). Every
 * pwgLogin()-triggered test that writes to it is transaction-wrapped
 * (AuthService doesn't open any secondary connection internally, unlike
 * TagService::setTagsOf()'s own updateImagesLastmodified() call, so
 * this is safe here) -- a plain, non-transactional write scoped only by
 * `ip = ''` still leaves the row genuinely, immediately visible to any
 * other connection for its entire lifetime, and
 * UserFailedLoginRepositoryTest.php's own countRecentByUserId() test
 * has no ip scoping at all (it's the user-scoped lockout check), so it
 * was observed picking these rows up under --parallel; confirmed live
 * via a 5-run full composer test loop, each with a fresh fixture
 * reimport.
 */
function authServiceTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-authservice-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);

    return $root;
}

function authServiceTestService(?Connection $conn = null, ?FinalizeLoginDecision $finalizeLoginOverride = null): AuthService
{
    $conn ??= DbConnection::build();
    $currentConfig = CurrentConfigTestFactory::get();

    return new AuthService(
        new AuthRepository(EntityManagerFactory::build($conn)),
        new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
        HtmlServiceTestFactory::build(),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
        new CookieService(),
        EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $currentConfig),
        EventDispatcherTestFactory::get(),
        PageStateTestFactory::get(),
        CurrentUserTestFactory::get(),
        $currentConfig,
        CurrentPathsTestFactory::get(),
        EntityManagerFactory::build($conn),
        new ConnectedWithSession(),
        $finalizeLoginOverride,
    );
}

function authServiceTestApiKeyService(): ApiKeyService
{
    $conn = DbConnection::build();
    $mailer = Kernel::container()->get(MailService::class);
    if (! $mailer instanceof MailService) {
        throw new LogicException('Container returned an unexpected type for ' . MailService::class);
    }

    return new ApiKeyService(
        LangTestFactory::get(),
        $mailer,
        new ApiKeyRepository(EntityManagerFactory::build($conn)),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
        UrlServiceTestFactory::build(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
        CurrentConfigTestFactory::get(),
    );
}

/**
 * AuthService::pwgLogin() is the real, registered try_log_user handler
 * -- it takes/returns a TryLogUser event, not 4 loose params.
 */
function authServiceTestPwgLoginResult(bool $success, string $username, ?string $password, bool $rememberMe, ?Connection $conn = null, ?FinalizeLoginDecision $finalizeLoginOverride = null): bool
{
    return authServiceTestService($conn, $finalizeLoginOverride)
        ->pwgLogin(new TryLogUser($success, $username, $password, $rememberMe))
        ->success;
}

function authServiceTestSetCurrentUserToFixtureAdmin(): void
{
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: Username::from('fixture_admin'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Webmaster,
        enabledHigh: false,
    ));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(authServiceTestRoot()));
    CurrentConfigTestFactory::get()->secretKey = 'test-secret-key';
});

afterEach(function (): void {
    // logUser()/autoLogin()/pwgLogin() (for a successful login) all call
    // session_start() for real -- session_write_close() genuinely resets
    // session_status() to PHP_SESSION_NONE (confirmed live, matching
    // SessionMiddlewareTest.php's own established pattern), so a session
    // left active here never leaks into whatever test runs next in this
    // same --parallel worker process. Without this, a later test's own
    // session_id($freshValue) call warns ("Session ID cannot be changed
    // when a session is active"), confirmed live.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
    CurrentUserTestFactory::get()->reset();
    PageStateTestFactory::get()->reset();
});

test('calculateAutoLoginKey() returns a key and username for a real user', function (): void {
    $result = authServiceTestService()
        ->calculateAutoLoginKey(1, 1000);

    expect($result->key)
        ->toBeString()
        ->and($result->username)
        ->toBe('fixture_admin');
});

test('calculateAutoLoginKey() returns false for a missing user', function (): void {
    $result = authServiceTestService()
        ->calculateAutoLoginKey(999999, 1000);

    expect($result->key)
        ->toBeFalse()
        ->and($result->username)
        ->toBe('');
});

test('calculateAutoLoginKey() is stable for the same inputs', function (): void {
    $service = authServiceTestService();

    $first = $service->calculateAutoLoginKey(1, 1000);
    $second = $service->calculateAutoLoginKey(1, 1000);

    expect($second->key)
        ->toBe($first->key);
});

test('calculateAutoLoginKey() changes when the time changes', function (): void {
    $service = authServiceTestService();

    $first = $service->calculateAutoLoginKey(1, 1000);
    $second = $service->calculateAutoLoginKey(1, 2000);

    expect($second->key)
        ->not->toBe($first->key);
});

test('calculateAutoLoginKey() changes when the secret key changes', function (): void {
    $service = authServiceTestService();
    $first = $service->calculateAutoLoginKey(1, 1000);

    CurrentConfigTestFactory::get()->secretKey = 'a-different-secret';

    $second = $service->calculateAutoLoginKey(1, 1000);

    expect($second->key)
        ->not->toBe($first->key);
});

test('tryLogUser() fails closed when no handler is registered', function (): void {
    // No handler is registered for this event, so
    // EventDispatcher::dispatch() returns the same event
    // (constructed with success=false) unchanged.
    expect(authServiceTestService()->tryLogUser('anyone', 'anything', false))
        ->toBeFalse();
});

test('findUserByUsernameOrEmail() matches by username', function (): void {
    $user = authServiceTestService()
        ->findUserByUsernameOrEmail('fixture_admin');

    expect($user)
        ->not->toBeNull();
    if (! $user instanceof AuthUser) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    expect($user->username)
        ->toBe('fixture_admin');
});

test('findUserByUsernameOrEmail() returns null for an unknown identifier', function (): void {
    expect(authServiceTestService()->findUserByUsernameOrEmail('no-such-user-' . uniqid()))->toBeNull();
});

test('hasAlreadyLoggedIn() is true for a user with no login activity history', function (): void {
    // Fixture user 4 (power_user) -- no login-activity rows exist for
    // it in the fixture, so countLoginActivity() === 0.
    $conn = DbConnection::build();
    expect(
        authServiceTestService()
            ->hasAlreadyLoggedIn(4, EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class))
    )->toBeTrue();
});

test('logUser() rejects a non-string lang cookie as an invalid request parameter', function (): void {
    // A real request can never send a scalar $_COOKIE['lang'] as an
    // array (only a crafted 'lang[]=x&lang[]=y' request could), but
    // $_COOKIE is untyped -- logUser() defends against it before
    // touching any session/cookie code, so this is safe to exercise
    // directly.
    authServiceTestSetCurrentUserToFixtureAdmin();
    $_COOKIE['lang'] = ['unexpected', 'array'];

    $exception = null;
    try {
        authServiceTestService()->logUser(1, false);
    } catch (ResponseReadyException $e) {
        $exception = $e;
    } finally {
        unset($_COOKIE['lang']);
    }

    expect($exception)
        ->toBeInstanceOf(ResponseReadyException::class);
    if (! $exception instanceof ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(500)
        ->and((string) $response->getBody())
        ->toContain('Invalid request parameter "lang"');
});

test('logUser() rejects an unrecognised language code as an unrecognized parameter value', function (): void {
    authServiceTestSetCurrentUserToFixtureAdmin();
    $_COOKIE['lang'] = 'zz_NOT_A_REAL_LANGUAGE';

    $exception = null;
    try {
        authServiceTestService()->logUser(1, false);
    } catch (ResponseReadyException $e) {
        $exception = $e;
    } finally {
        unset($_COOKIE['lang']);
    }

    expect($exception)
        ->toBeInstanceOf(ResponseReadyException::class);
    if (! $exception instanceof ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(500)
        ->and((string) $response->getBody())
        ->toContain('Unrecognized value for parameter "lang"');
});

test('logUser() syncs the language preference and clears the lang cookie when it differs', function (): void {
    // LangService::getLanguages() requires BOTH a `languages` DB row AND
    // a real on-disk `language/{id}/` directory under Paths::$root --
    // only 'en_UK' ships in the fixture DB row (see the two
    // hacking-attempt tests above, which rely on *every* other code
    // being rejected), and this file's own throwaway Kernel::boot()
    // root has no language/ directory at all, so both need to exist
    // for the duration of this test.
    $conn = DbConnection::build();
    $conn->executeStatement("INSERT INTO languages (id, version, name) VALUES ('fr_FR', '16.3.0', 'Francais')");
    mkdir(CurrentPathsTestFactory::get()->root . 'language/fr_FR', 0o777, true);

    authServiceTestSetCurrentUserToFixtureAdmin();
    $_COOKIE['lang'] = 'fr_FR';

    try {
        authServiceTestService()->logUser(1, false);

        $language = $conn->fetchOne('SELECT language FROM user_infos WHERE user_id = 1');
        expect($language)
            ->toBe('fr_FR');

        // setcookie('lang', '', ['expires' => time() - 3600]) itself
        // only ever mutates the outgoing response header -- this
        // process's own $_COOKIE superglobal is never touched by
        // setcookie() (that only affects the *next* real HTTP request)
        // -- so there's nothing further in-process to assert on it
        // directly; the DB write above plus the absence of any
        // uncaught warning/exception together prove this exact branch
        // (including the setcookie() call) ran.
    } finally {
        unset($_COOKIE['lang']);
        $conn->executeStatement("DELETE FROM languages WHERE id = 'fr_FR'");
        $conn->executeStatement("UPDATE user_infos SET language = 'en_UK' WHERE user_id = 1");
        unset($_SESSION['pwg_uid']);
    }
});

test('autoLogin() succeeds for a valid remember-me cookie and marks the session ui context', function (): void {
    $remember_me_name = CurrentConfigTestFactory::get()->rememberMeName;
    $time = time();
    $service = authServiceTestService();
    $calculated = $service->calculateAutoLoginKey(1, $time);
    expect($calculated->key)
        ->toBeString();

    $_COOKIE[$remember_me_name] = 1 . '-' . $time . '-' . $calculated->key;

    try {
        // Real bug, found live in the Integration original: autoLogin()
        // sets $_SESSION['connected_with'] = 'pwg_ui' BEFORE calling
        // logUser(), and logUser() itself only calls
        // session_regenerate_id() (which preserves the current
        // $_SESSION content) when a session is ALREADY active --
        // otherwise it calls session_start(), which *reloads* $_SESSION
        // from the persisted (DB-backed) store, clobbering the
        // in-memory 'connected_with' write made moments earlier. A real
        // HTTP request's bootstrap chain always has an active session
        // by the time autoLogin() runs, so this never bites in
        // production -- but this CLI test process starts with no
        // active session, so it must start one first to match that
        // real precondition.
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $result = $service->autoLogin();

        expect($result)
            ->toBeTrue();
        expect($_SESSION['connected_with'] ?? null)->toBe(ConnectedWith::PwgUi->value);
    } finally {
        unset($_COOKIE[$remember_me_name]);
        unset($_SESSION['pwg_uid'], $_SESSION['connected_with']);
    }
});

test('autoLogin() clears the cookie and returns false for a malformed remember-me cookie', function (): void {
    $remember_me_name = CurrentConfigTestFactory::get()->rememberMeName;
    // 5 dash-separated parts -- is_string() passes and explode() runs,
    // but count($cookie) === 3 fails immediately, short-circuiting the
    // rest of the compound condition. Exercises the fallback cleanup
    // setcookie() at the bottom of autoLogin() instead of the success
    // path the test above already covers.
    $_COOKIE[$remember_me_name] = 'not-a-valid-cookie-format';

    try {
        expect(authServiceTestService()->autoLogin())
            ->toBeFalse();
    } finally {
        unset($_COOKIE[$remember_me_name]);
    }
});

test('pwgLogin() returns true immediately when success is already true', function (): void {
    // The $success===true short-circuit at the very top of pwgLogin()
    // -- reached e.g. when a plugin's own 'try_log_user' handler
    // already authenticated the user before this default handler runs.
    expect(authServiceTestPwgLoginResult(true, 'irrelevant', 'irrelevant', false))
        ->toBeTrue();
});

test('pwgLogin() denies the login when a FinalizeLoginDecision override blocks it', function (): void {
    // fixture_admin / fixture_admin -- a real username+password that
    // passes pwgLogin()'s own password_verify() check, so execution
    // reaches the finalize-login decision rather than being rejected
    // earlier for a wrong password, using a real constructor-injected
    // FinalizeLoginDecision override.
    $override = new FinalizeLoginDecision(canLogin: false, reason: 'blocked_by_test_handler', authenticated: false);

    expect(authServiceTestPwgLoginResult(false, 'fixture_admin', 'fixture_admin', false, finalizeLoginOverride: $override))
        ->toBeFalse();
});

test('pwgLogin() records a failed login row for a wrong password', function (): void {
    $conn = DbConnection::build();
    $countFailedLoginsForFixtureAdmin = static fn (): int => (int) $conn->fetchOne(
        "SELECT COUNT(*) FROM user_failed_logins WHERE user_id = 1 AND ip = ''"
    );
    $before = $countFailedLoginsForFixtureAdmin();

    $result = authServiceTestPwgLoginResult(false, 'fixture_admin', 'definitely-wrong-password', false, $conn);

    expect($result)
        ->toBeFalse()
        ->and($countFailedLoginsForFixtureAdmin())
        ->toBe($before + 1);
});

test('pwgLogin() locks out the username after max attempts even with the correct password', function (): void {
    // Empty $_SERVER['REMOTE_ADDR'] in this CLI test process means
    // pwgLogin()'s ip-scoped check never fires, so this exercises the
    // username-scoped lockout alone.
    CurrentConfigTestFactory::get()->loginLockoutMaxAttempts = 3;

    try {
        for ($i = 0; $i < 3; $i++) {
            expect(authServiceTestPwgLoginResult(false, 'fixture_admin', 'definitely-wrong-password', false))
                ->toBeFalse();
        }

        // generateFakeUser() is the only thing that ever sets this --
        // unset it first so the assertion below can tell whether
        // pwgLogin() reached it on this specific call.
        unset($_SESSION['fake_user_cache']);

        $result = authServiceTestPwgLoginResult(false, 'fixture_admin', 'fixture_admin', false);

        expect($result)
            ->toBeFalse();
        expect($_SESSION)
            ->not->toHaveKey('fake_user_cache');
    } finally {
        unset($_SESSION['fake_user_cache']);
    }
});

test('pwgLogin() fast-rejects a locked-out username via the user-scoped lockout block directly', function (): void {
    // A minimal, deterministic reproduction of the user-scoped lockout
    // block itself: with maxAttempts=1, the single real failure below
    // is recorded by the *separate* "wrong password" block, and only
    // the *second* call -- this time with the correct password -- is
    // old enough to be fast-rejected by the user-scoped lockout block
    // itself.
    //
    // Real bug, found live in the Integration original: pwgLogin()'s
    // IP-scoped lockout check runs FIRST, before the username is even
    // resolved, and only when $_SERVER['REMOTE_ADDR'] is non-empty.
    // Force REMOTE_ADDR to the same guaranteed-empty state every
    // sibling test here relies on implicitly.
    $originalRemoteAddr = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
    $_SERVER['REMOTE_ADDR'] = '';
    $conn = DbConnection::build();
    CurrentConfigTestFactory::get()->loginLockoutMaxAttempts = 1;

    $countFailedLoginsForFixtureAdmin = static fn (): int => (int) $conn->fetchOne(
        "SELECT COUNT(*) FROM user_failed_logins WHERE user_id = 1 AND ip = ''"
    );

    try {
        expect(authServiceTestPwgLoginResult(false, 'fixture_admin', 'definitely-wrong-password', false, $conn))
            ->toBeFalse();
        $afterFirstFailure = $countFailedLoginsForFixtureAdmin();

        unset($_SESSION['fake_user_cache']);

        $result = authServiceTestPwgLoginResult(false, 'fixture_admin', 'fixture_admin', false, $conn);

        expect($result)
            ->toBeFalse();
        expect($_SESSION)
            ->not->toHaveKey('fake_user_cache');
        // The lockout block itself calls recordFailure() a second time.
        expect($countFailedLoginsForFixtureAdmin())
            ->toBe($afterFirstFailure + 1);
    } finally {
        unset($_SESSION['fake_user_cache']);
        $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
    }
});

test('pwgLogin() locks out by ip even for an unknown username', function (): void {
    CurrentConfigTestFactory::get()->loginLockoutMaxAttempts = 3;
    $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.55';

    try {
        for ($i = 0; $i < 3; $i++) {
            expect(authServiceTestPwgLoginResult(false, 'no-such-user-' . $i . '-' . uniqid(), 'irrelevant', false))->toBeFalse();
        }

        unset($_SESSION['fake_user_cache']);

        // A brand-new, never-before-seen username -- proves the
        // lockout is keyed on the IP, not on having seen this exact
        // username fail before.
        $result = authServiceTestPwgLoginResult(false, 'no-such-user-final-' . uniqid(), 'irrelevant', false);

        expect($result)
            ->toBeFalse();
        expect($_SESSION)
            ->not->toHaveKey('fake_user_cache');
    } finally {
        unset($_SESSION['fake_user_cache']);
        if ($originalRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
        }
        DbConnection::build()->executeStatement("DELETE FROM user_failed_logins WHERE ip = '203.0.113.55'");
    }
});

test('authKeyLogin() rejects a key with an invalid format', function (): void {
    expect(authServiceTestService()->authKeyLogin('not-a-valid-key-format'))
        ->toBeFalse();
});

test('authKeyLogin() returns false for a well-formed but unknown auth key', function (): void {
    // 30 lowercase alnum chars matches the auth_key format regex but
    // was never inserted into user_auth_keys.
    expect(authServiceTestService()->authKeyLogin(str_repeat('a', 30)))
        ->toBeFalse();
});

test('authKeyLogin() rejects an expired auth key', function (): void {
    $conn = DbConnection::build();
    $service = authServiceTestService();
    $created = $service->createUserAuthKey(4, 'normal');
    expect($created)
        ->toBeInstanceOf(CreatedUserAuthKey::class);
    if (! $created instanceof CreatedUserAuthKey) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $authKey = $created->authKey;

    $conn->executeStatement(
        "UPDATE user_auth_keys SET expired_on = '2000-01-01 00:00:00' WHERE auth_key = ?",
        [$authKey]
    );

    try {
        expect($service->authKeyLogin($authKey))
            ->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM user_auth_keys WHERE auth_key = ?', [$authKey]);
    }
});

test('authKeyLogin() rejects an auth key whose user status is no longer eligible', function (): void {
    // The key was created while user 4 was 'normal' (the only status
    // createUserAuthKey() itself allows); promoting them to 'admin'
    // afterward -- e.g. an admin action taken between key creation and
    // its use -- exercises authKeyLogin()'s own separate, defensive
    // status re-check.
    $conn = DbConnection::build();
    $service = authServiceTestService();
    $created = $service->createUserAuthKey(4, 'normal');
    expect($created)
        ->toBeInstanceOf(CreatedUserAuthKey::class);
    if (! $created instanceof CreatedUserAuthKey) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $authKey = $created->authKey;

    $conn->executeStatement("UPDATE user_infos SET status = 'admin' WHERE user_id = 4");

    try {
        expect($service->authKeyLogin($authKey))
            ->toBeFalse();
    } finally {
        $conn->executeStatement("UPDATE user_infos SET status = 'normal' WHERE user_id = 4");
        $conn->executeStatement('DELETE FROM user_auth_keys WHERE auth_key = ?', [$authKey]);
    }
});

test('authKeyLogin() rejects an api key with the wrong secret', function (): void {
    $conn = DbConnection::build();
    $apiKeyService = authServiceTestApiKeyService();
    $created = $apiKeyService->create(4, 30, 'Wrong Secret Test Key');

    // Tamper the last char of the 40-char plain secret so it no longer
    // password_verify()s against the stored hash, while keeping the
    // combined key's own format regex satisfied.
    $tamperedSecret = substr($created->apikeySecret, 0, -1) . (str_ends_with($created->apikeySecret, 'a') ? 'b' : 'a');

    try {
        expect(authServiceTestService()->authKeyLogin($created->authKey . ':' . $tamperedSecret))->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM user_auth_keys WHERE auth_key = ?', [$created->authKey]);
    }
});

test('authKeyLogin() rejects a revoked api key', function (): void {
    $conn = DbConnection::build();
    $apiKeyService = authServiceTestApiKeyService();
    $created = $apiKeyService->create(4, 30, 'Revoked Test Key');
    $revoked = $apiKeyService->revoke(4, $created->authKey);
    expect($revoked)
        ->toBeTrue();

    try {
        expect(authServiceTestService()->authKeyLogin($created->authKey . ':' . $created->apikeySecret))->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM user_auth_keys WHERE auth_key = ?', [$created->authKey]);
    }
});

test('createUserAuthKey() returns false when auth key duration is disabled', function (): void {
    CurrentConfigTestFactory::get()->authKeyDuration = 0;

    expect(authServiceTestService()->createUserAuthKey(4, 'normal'))
        ->toBeFalse();
});

test('generatePasswordLink() computes the reset link when not the first login', function (): void {
    $conn = DbConnection::build();

    try {
        $result = authServiceTestService()
            ->generatePasswordLink(4, UrlServiceTestFactory::build(), false);

        expect($result['password_link'])->toContain('password.php?key=');
    } finally {
        $conn->executeStatement('UPDATE user_infos SET activation_key = NULL, activation_key_expire = NULL WHERE user_id = 4');
    }
});

test('generatePasswordLink() still works for a user locked out of pwgLogin()', function (): void {
    // generatePasswordLink() is the password-reset escape hatch -- it
    // never routes through pwgLogin()/tryLogUser()/logUser(), so a
    // username-scoped lockout on user 4 must not affect it.
    CurrentConfigTestFactory::get()->loginLockoutMaxAttempts = 3;
    $conn = DbConnection::build();
    $conn->executeStatement("DELETE FROM user_failed_logins WHERE user_id = 4 AND ip = ''");

    for ($i = 0; $i < 3; $i++) {
        expect(authServiceTestPwgLoginResult(false, 'power_user', 'definitely-wrong-password', false))
            ->toBeFalse();
    }

    try {
        // Confirm the lockout genuinely took effect first, so this test
        // would actually fail if generatePasswordLink() ever started
        // depending on pwgLogin()'s own state.
        expect(authServiceTestPwgLoginResult(false, 'power_user', 'anything', false))
            ->toBeFalse();

        $result = authServiceTestService()
            ->generatePasswordLink(4, UrlServiceTestFactory::build(), false);

        expect($result['password_link'])->toContain('password.php?key=');
    } finally {
        $conn->executeStatement('UPDATE user_infos SET activation_key = NULL, activation_key_expire = NULL WHERE user_id = 4');
        $conn->executeStatement("DELETE FROM user_failed_logins WHERE user_id = 4 AND ip = ''");
    }
});
