<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Session\SessionService;

/**
 * Login/logout session lifecycle: remember-me auto-login, session
 * creation/regeneration/teardown. Constructor-injects AuthRepository,
 * plain constructor injection (same shape as PermalinkService/GroupService).
 *
 * The remember-me cookie is set via raw setcookie() calls, matching the
 * original -- a genuinely different cookie namespace/lifecycle than
 * Piwigo\Auth\CookieService's 'pwg_*' preference-cookie storage, not a
 * missed opportunity to reuse it.
 *
 * [SEC-27] calculateAutoLoginKey()/autoLogin() built with sha256 +
 * hash_equals() from the start, unlike the original
 * calculate_auto_login_key()/auto_login() (hash_hmac('sha1', ...), key
 * compared with a plain === ) -- there's no insecure intermediate state to
 * carry forward since this class doesn't exist yet on this branch.
 *
 * calculate_auto_login_key()'s own secret_key read was already correct in
 * the original (global \Piwigo\Config\CurrentConfig::secretKey(), never Piwigo\Config\Config)
 * -- preserved as-is, not the CurrentConfig::secretKey() bug found and fixed
 * elsewhere this phase (CsrfService/EphemeralKeyService).
 *
 * pwgLogin() also enforces a dual-scope (IP and username) lockout backed
 * by UserFailedLoginRepository (user_failed_logins table) -- see that
 * method's own comments for why the IP check runs before the username is
 * even resolved, and why the username check running before
 * password_verify() doesn't regress the constant-time anti-enumeration
 * property below it. Scoped to username/password login only: autoLogin()
 * and authKeyLogin() authenticate against high-entropy random tokens, a
 * different threat model that doesn't call for the same lockout.
 * generatePasswordLink() (password reset) never routes through
 * pwgLogin()/tryLogUser()/logUser() at all, so it stays a working escape
 * hatch for a legitimately locked-out user.
 */
final readonly class AuthService
{
    public function __construct(
        private AuthRepository $repo,
        private ActivityLoggerInterface $activityLogger,
        private HtmlRenderingInterface $htmlRenderer,
        private PasswordService $passwordService,
        private CookieService $cookieService,
        private UserFailedLoginRepository $failedLoginRepo,
    ) {}

    /**
     * Returns the auto login key for a user, and the username it belongs
     * to (only meaningful when the key isn't false). Returns
     * key: false when the user isn't found.
     *
     * @param int|numeric-string $userId auto_login() passes the raw
     *   remember-me cookie's exploded, is_numeric()-checked string parts
     * @param int|numeric-string $time ditto -- only ever used in string
     *   concatenation (the HMAC input) or SQL interpolation, never
     *   arithmetic
     * @return array{key: string|false, username: string}
     */
    public function calculateAutoLoginKey(int|string $userId, int|string $time): array
    {

        // see validate_mail_address() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $found = $this->repo->findUsernameAndPassword(
            $userId,
            $user_fields['id'],
            $user_fields['username'],
            $user_fields['password']
        );

        if ($found === null) {
            return [
                'key' => false,
                'username' => '',
            ];
        }

        $username = stripslashes($found['username']);
        $data = $time . $userId . $username;
        // secret_key is a random string generated at install time (see
        // install/index.php), always a string in a working install
        $secret_key = \Piwigo\Config\CurrentConfig::secretKey();
        $key = base64_encode(hash_hmac('sha256', $data, $secret_key . $found['password'], true));

        return [
            'key' => $key,
            'username' => $username,
        ];
    }

    /**
     * Current password hash for $userId -- Controller\
     * ProfileFormHandler::saveFromPost()'s own "changing password requires
     * old password" check. Reuses
     * {@see AuthRepository::findUsernameAndPassword()}'s own query
     * (same shape as {@see calculateAutoLoginKey()} above), discarding the
     * username half.
     */
    public function getPasswordHash(int|string $userId, string $idColumn, string $usernameColumn, string $passwordColumn): ?string
    {
        $found = $this->repo->findUsernameAndPassword($userId, $idColumn, $usernameColumn, $passwordColumn);

        return $found['password'] ?? null;
    }

    /**
     * Performs all required actions for user login.
     *
     * @param int|numeric-string|false $userId auto_login() passes a raw
     *   remember-me cookie's numeric-string user id; register.php passes
     *   get_userid()'s result directly, which is typed int|false (false is
     *   not reachable here in practice since it looks up the user just
     *   created by the immediately-preceding successful register_user()
     *   call)
     */
    public function logUser(int|string|false $userId, bool $rememberMe): void
    {
        // false is not reachable in practice -- see this method's own
        // docblock on $userId
        assert($userId !== false);

        // remember_me_name defaults to 'pwg_remember' (string),
        // remember_me_length to 5184000 (int) in
        // include/config_default.inc.php, but once persisted to the config
        // DB table both come back as raw strings (see load_conf_from_db())
        // -- accept either.
        $remember_me_name = \Piwigo\Config\CurrentConfig::rememberMeName();
        $remember_me_length = \Piwigo\Config\CurrentConfig::rememberMeLength();

        // New default login and register pages, if users changes languages
        // and succesfully logs in we want to update the userpref language
        // stored in a cookie
        if (isset($_COOKIE['lang']) && \Piwigo\Users\CurrentUser::get()->language !== $_COOKIE['lang']) {
            $lang_cookie = $_COOKIE['lang'];
            if (! is_string($lang_cookie)) {
                $this->htmlRenderer->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($lang_cookie, \Piwigo\Lang\LangService::getLanguages())) {
                $this->htmlRenderer->fatalError('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
            }

            $this->repo->updateLanguage(UserId::from((int) $userId), $lang_cookie);

            // We unset the lang cookie, if user has changed their language
            // using interface we don't want to keep setting it back to
            // what was chosen using standard pages lang switch
            setcookie('lang', '', [
                'expires' => time() - 3600,
            ]);
        }

        if ($rememberMe && \Piwigo\Config\CurrentConfig::authorizeRemembering()) {
            $now = time();
            $calculated = $this->calculateAutoLoginKey($userId, $now);
            if ($calculated['key'] !== false) {
                $cookie = $userId . '-' . $now . '-' . $calculated['key'];
                setcookie(
                    $remember_me_name,
                    $cookie,
                    [
                        'expires' => time() + $remember_me_length,
                        'path' => $this->cookieService->cookiePath(),
                        'domain' => (string) ini_get('session.cookie_domain'),
                        'secure' => (bool) ini_get('session.cookie_secure'),
                        'httponly' => (bool) ini_get('session.cookie_httponly'),
                    ]
                );
            }
        } else { // make sure we clean any remember me ...
            setcookie($remember_me_name, '', [
                'expires' => 0,
                'path' => $this->cookieService->cookiePath(),
                'domain' => (string) ini_get('session.cookie_domain'),
            ]);
        }

        // Real bug, found via a new Integration test that calls this
        // directly (no SessionMiddleware ahead of it to have already
        // started a session): session_id() !== '' (the original
        // include/functions_user.inc.php's own check, unchanged here) only
        // tells you an id string has been *set* -- e.g. via a fixed
        // session_id('...') call, same as this project's own test helpers
        // do -- not that a session is actually *active*.
        // session_regenerate_id() requires an active session and emits a
        // PHP warning otherwise; session_status() is the real predicate
        // this branch needs.
        if (session_status() === \PHP_SESSION_ACTIVE) { // we regenerate the session for security reasons
            // see http://www.acros.si/papers/session_fixation.pdf
            session_regenerate_id(true);
        } else {
            session_start();
        }
        $_SESSION['pwg_uid'] = (int) $userId;

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('user_login', $_SESSION['pwg_uid']);
        $this->activityLogger->record('user', $_SESSION['pwg_uid'], 'login');
    }

    /**
     * Performs auto-connection when cookie remember_me exists.
     */
    public function autoLogin(): bool
    {

        // see logUser() for why these accept both the config-default
        // scalar type and the DB-persisted string form
        $remember_me_name = \Piwigo\Config\CurrentConfig::rememberMeName();
        $remember_me_length = \Piwigo\Config\CurrentConfig::rememberMeLength();

        if (isset($_COOKIE[$remember_me_name])) {
            $remember_me_cookie = $_COOKIE[$remember_me_name];
            if (is_string($remember_me_cookie)) {
                $cookie = explode('-', stripslashes($remember_me_cookie));
                if (
                    count($cookie) === 3
                    && is_numeric($cookie[0]) /* user id */
                    && is_numeric($cookie[1]) /* time */
                    && time() - $remember_me_length <= $cookie[1]
                    && time() >= $cookie[1] /* cookie generated in the past */
                ) {
                    $calculated = $this->calculateAutoLoginKey($cookie[0], $cookie[1]);
                    if ($calculated['key'] !== false && hash_equals($calculated['key'], $cookie[2])) {
                        // Since Piwigo 16, 'connected_with' in the session
                        // defines the authentication context (UI, API,
                        // etc). Auto-login via remember-me may miss this,
                        // so we set it to 'pwg_ui' for UI logins (not API).
                        if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'ws') {
                            $_SESSION['connected_with'] = 'pwg_ui';
                        }
                        $this->logUser($cookie[0], true);
                        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_success', stripslashes($calculated['username']));

                        return true;
                    }
                }
            }
            setcookie($remember_me_name, '', [
                'expires' => 0,
                'path' => $this->cookieService->cookiePath(),
                'domain' => (string) ini_get('session.cookie_domain'),
            ]);
        }

        return false;
    }

    /**
     * Tries to login a user given username and password (must be MySql
     * escaped).
     *
     * @param string|null $password both real callers (identification.php's
     *   $_POST, ws_session_login()'s optional WS param) can genuinely pass
     *   null when the field is omitted
     */
    public function tryLogUser(string $username, ?string $password, bool $rememberMe): bool
    {
        $result = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('try_log_user', false, $username, $password, $rememberMe);

        // trigger_change()'s own return type is mixed; the only registered
        // handler (pwg_login()) returns bool, but fail closed (deny login)
        // rather than trust a misbehaving third-party handler.
        return is_bool($result) ? $result : false;
    }

    /**
     * Performs all the cleanup on user logout.
     */
    public function logoutUser(): void
    {

        $pwg_uid = $_SESSION['pwg_uid'] ?? null;
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('user_logout', $pwg_uid);
        if (is_int($pwg_uid) || is_string($pwg_uid)) {
            $this->activityLogger->record('user', $pwg_uid, 'logout');
        }

        $_SESSION = [];
        session_unset();
        session_destroy();
        $current_session_name = session_name();
        if ($current_session_name !== false) {
            setcookie(
                $current_session_name,
                '',
                [
                    'expires' => 0,
                    'path' => (string) ini_get('session.cookie_path'),
                    'domain' => (string) ini_get('session.cookie_domain'),
                ]
            );
        }
        // see logUser() for why this accepts both the config-default
        // scalar type and the DB-persisted string form
        $remember_me_name = \Piwigo\Config\CurrentConfig::rememberMeName();
        setcookie($remember_me_name, '', [
            'expires' => 0,
            'path' => $this->cookieService->cookiePath(),
            'domain' => (string) ini_get('session.cookie_domain'),
        ]);
    }

    /**
     * Default method for user login, can be overwritten with the
     * 'try_log_user' trigger.
     * @see tryLogUser()
     */
    public function pwgLogin(bool $success, string $username, ?string $password, bool $rememberMe): bool
    {
        if ($success === true) {
            return true;
        }

        // we force the session table to be clean
        SessionService::get()->sessionGc();

        // see IpAddress's own docblock for why this is a plain -> (not
        // ?->) before the ?? -- the null-coalescing already handles a null
        // left-hand side safely.
        $ip = \Piwigo\Common\ValueObject\IpAddress::fromRemoteAddr()->value ?? '';
        $now = Env::now();
        $nowFormatted = $now->format('Y-m-d H:i:s');
        $windowStart = (clone $now)->modify('-' . \Piwigo\Config\CurrentConfig::loginLockoutWindowMinutes() . ' minutes')->format('Y-m-d H:i:s');
        $maxAttempts = \Piwigo\Config\CurrentConfig::loginLockoutMaxAttempts();

        // LOCKOUT (IP-scoped): checked before the username is even resolved
        // below, so a fast reject here can never leak whether $username
        // exists -- it doesn't depend on the lookup at all.
        if ($ip !== '' && $this->failedLoginRepo->countRecentByIp($ip, $windowStart) >= $maxAttempts) {
            $this->failedLoginRepo->recordFailure(null, $ip, $nowFormatted);
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_failure', stripslashes($username));
            return false;
        }

        // Find user by username or email (if it exists)
        $user_found = $this->findUserByUsernameOrEmail($username);
        $userId = $this->resolveUserId($user_found);

        // LOCKOUT (user-scoped): checked before password_verify() runs
        // below. Only reachable once $maxAttempts real failed attempts
        // already happened against this exact username -- by then the
        // attacker already knows the account exists (they caused those
        // failures), so fast-rejecting here doesn't regress the
        // constant-time anti-enumeration property that the block below
        // still provides for every not-yet-locked-out attempt.
        if ($userId !== null && $this->failedLoginRepo->countRecentByUserId($userId, $windowStart) >= $maxAttempts) {
            $this->failedLoginRepo->recordFailure($userId, $ip, $nowFormatted);
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_failure', stripslashes($username));
            return false;
        }

        // SECURITY: Constant-time authentication to prevent timing attacks
        //
        // We always perform password verification, even when the user doesn't exist,
        // to prevent attackers from distinguishing between:
        //  - "user exists, wrong password" (slow: runs password_verify)
        //  - "user doesn't exist" (fast: would skip verification)
        //
        // This timing difference could allow user enumeration. By using a fake user
        // with a pre-generated hash, we ensure consistent execution time regardless
        // of whether the account exists or not.
        $fake_user = $this->generateFakeUser();

        // Verify password with fallback to fake user
        $hash = $user_found->password ?? $fake_user['password'];
        $verify_user_id = $user_found->id ?? $fake_user['id'];
        if ($verify_user_id !== null) {
            assert(is_numeric($verify_user_id));
            $verify_user_id = (int) $verify_user_id;
        }
        $password_verify = $this->passwordService->verify($password ?? '', $hash, $verify_user_id);

        // If the user was not found, is a guest, or the password is incorrect
        if ($user_found === null || $user_found->status === 'guest' || ! $password_verify) {
            if ($user_found !== null && ! $password_verify) {
                $this->activityLogger->record('user', $user_found->id, 'login_failure_wrong_password');
            }
            $this->failedLoginRepo->recordFailure($userId, $ip, $nowFormatted);
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_failure', stripslashes($username));
            return false;
        }

        // PLUGIN HOOK: Allow plugins to intercept authentication before log_user()
        //
        // Expected $state array structure:
        //  - 'can_login' (bool): Set to false to block login
        //  - 'reason' (string|null): Custom activity log reason if login blocked
        //  - 'authenticated' (bool): Set to true if plugin handles log_user() itself
        //
        // Example plugin implementation:
        //   add_event_handler('finalize_login', 'my_2fa_check');
        //   function my_2fa_check($state, $user, $remember_me) {
        //     if (!verify_2fa_code()) {
        //       $state['can_login'] = false;
        //       $state['reason'] = '2fa_failed';
        //     }
        //     return $state;
        //   }
        $state = [
            'can_login' => true,
            'reason' => null,
            'authenticated' => false,
        ];
        $state = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('finalize_login', $state, $user_found, $rememberMe);

        // trigger_change()'s own return type is mixed; `&&` always yields a
        // real bool regardless of operand types, which is what narrows
        // $can_login/$authenticated below without needing an assert().
        $can_login = is_array($state) && (bool) ($state['can_login'] ?? null);
        $reason = is_array($state) ? ($state['reason'] ?? null) : null;
        $authenticated = is_array($state) && (bool) ($state['authenticated'] ?? null);

        if (! $can_login) {
            $this->failedLoginRepo->recordFailure($userId, $ip, $nowFormatted);
            $this->activityLogger->record('user', $user_found->id, is_string($reason) ? $reason : 'login_failure_before_log_user');
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_failure_before_log_user', stripslashes($username));
            return false;
        }

        // If plugin handled authentication, skip log_user()
        if (! $authenticated) {
            assert(is_numeric($user_found->id));
            $this->logUser($user_found->id, $rememberMe);
        }

        $this->clearFakeUserCache();
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_success', stripslashes($username));
        return true;
    }

    /**
     * AuthUser::$id is a numeric string by convention (see the class's own
     * docblock) -- narrows to a real int for UserFailedLoginRepository, or
     * null when there's no resolved user to attribute the attempt to.
     */
    private function resolveUserId(?\Piwigo\Auth\Projection\AuthUser $user): ?int
    {
        return $user !== null && is_numeric($user->id) ? (int) $user->id : null;
    }

    /**
     * Find user by username or email search by username first then email.
     *
     * @since 16
     */
    public function findUserByUsernameOrEmail(string $usernameOrEmail): ?\Piwigo\Auth\Projection\AuthUser
    {

        // see UserService::validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        return $this->repo->findByUsernameOrEmail(
            $usernameOrEmail,
            $user_fields['id'],
            $user_fields['username'],
            $user_fields['email'],
            $user_fields['password'],
        );
    }

    /**
     * Generate a fake user with hashed password (with the current algo).
     *
     * SECURITY: This function is used for timing attack mitigation in
     * pwgLogin(). The fake user hash is cached per session to avoid
     * repeated hashing overhead while maintaining constant-time
     * authentication behavior.
     *
     * @since 16
     * @return array{id: null, password: string} id and password
     */
    public function generateFakeUser(): array
    {
        // Generate once per session to avoid repeated hashing overhead.
        // Uses current password_hash algorithm to match real user verification costs.
        if (! isset($_SESSION['fake_user_cache'])) {
            $fake_password = bin2hex(random_bytes(10));
            $_SESSION['fake_user_cache'] = [
                'id' => null,
                'password' => $this->passwordService->hash($fake_password),
            ];
        }

        $fake_user = $_SESSION['fake_user_cache'];
        assert(is_array($fake_user));
        assert(array_key_exists('id', $fake_user) && $fake_user['id'] === null);
        assert(isset($fake_user['password']) && is_string($fake_user['password']));

        return [
            'id' => null,
            'password' => $fake_user['password'],
        ];
    }

    /**
     * Clear current session fake user cache.
     *
     * @since 16
     */
    public function clearFakeUserCache(): void
    {
        unset($_SESSION['fake_user_cache']);
    }

    /**
     * Performs auto-connection if authentication key is valid.
     *
     * @since 2.8
     * @param mixed $authKey raw, unvalidated request input ($_GET['auth'], an
     *   Authorization header value, or a ws param) -- normalized to '' when
     *   not already a string (a malicious/malformed request can hand this an
     *   array), which safely fails every format check below
     */
    public function authKeyLogin(mixed $authKey, bool $connectionByHeader = false): bool
    {
        // see UserService::validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $authKey = is_string($authKey) ? $authKey : '';

        $valid_key = false;
        $secret_key = null;
        if ((bool) preg_match('/^[a-z0-9]{30}$/i', $authKey)) {
            $valid_key = 'auth_key';
        } elseif ((bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}:[a-z0-9]{40}$/i', $authKey)) {
            $valid_key = 'api_key';
            $tmp_key = explode(':', $authKey);
            $authKey = $tmp_key[0];
            $secret_key = $tmp_key[1];
        }

        if (! (bool) $valid_key) {
            return false;
        }

        $key = $this->repo->findAuthKeyDetails($authKey, $user_fields['id'], $user_fields['username'], $user_fields['email']);

        if ($key === null) {
            return false;
        }

        $now = Env::now();

        // is the key still valid?
        if (strtotime($key->expiredOn) < $now->getTimestamp()) {
            \Piwigo\Core\PageState::current()->markAuthKeyInvalid();
            return false;
        }

        // admin/webmaster/guest can't get connected with authentication keys
        if ($valid_key === 'auth_key' and ! in_array($key->status, ['normal', 'generic'], true)) {
            return false;
        }

        // the key is an api_key
        if ($valid_key === 'api_key') {
            // check secret
            $apikey_secret = $key->apikeySecret;
            if ($apikey_secret === null || ! $this->passwordService->verify($secret_key, $apikey_secret)) {
                return false;
            }

            // is the key is revoked?
            if ($key->revokedOn !== null) {
                return false;
            }

            // check if we need to notificate the user -- DATEDIFF() is a
            // calendar-day (not 24h-period) difference, sign-aware; matched
            // here via two date-only DateTimeImmutable instances rather
            // than a raw timestamp subtraction.
            $expiredOnDateOnly = new \DateTimeImmutable(substr($key->expiredOn, 0, 10));
            $nowDateOnly = new \DateTimeImmutable($now->format('Y-m-d'));
            $days_left = (int) $nowDateOnly->diff($expiredOnDateOnly)
                ->format('%r%a');
            $fortyEightHoursAgo = (clone $now)->modify('-48 hours');
            if (
                $days_left <= 7 // the key expire in max 7 days
                and $key->email !== '' // the user have an email
                and (
                    $key->lastNotifiedOn === null // we never send an email for this key
                    or strtotime($key->lastNotifiedOn) < $fortyEightHoursAgo->getTimestamp() // OR when the last email was sent more than 48 hours ago
                )
            ) {
                \Piwigo\Core\PageState::current()->setNotifyApiKeyExpiration([
                    'days_left' => $days_left,
                    'dbnow' => $now->format('Y-m-d H:i:s'),
                    'auth_key' => $key->authKey,
                ]);
            }
        }

        $key_user_id = $key->userId;
        // user_id is a NOT NULL FK column, always a numeric string --
        // narrows for logUser()'s own numeric-string docblock type.
        assert(is_numeric($key_user_id));

        // update last used key
        $this->repo->touchAuthKeyLastUsed($key_user_id, $key->authKey, $now);

        // set the type of connection
        $_SESSION['connected_with'] = $valid_key;

        // if the connection is made via an API key in the header,
        // access is authenticated without creating a persistent user session
        // this enables stateless authentication for API calls
        if ($connectionByHeader) {
            return true;
        }

        $this->logUser($key_user_id, false);
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('login_success', $key->username);

        // to be registered in history table by HistoryService::logVisit()
        \Piwigo\Core\PageState::current()->setAuthKeyId((int) $key->authKeyId);

        return true;
    }

    /**
     * Creates an authentication key.
     *
     * @since 2.8
     * @return array{auth_key: string, user_id: int, created_on: string, duration: int, expired_on: string, key_type: string, auth_key_id: string}|false false if auth keys are disabled or the user status is ineligible
     */
    public function createUserAuthKey(int $userId, ?string $userStatus = null): array|false
    {
        // auth_key_duration defaults to 3*24*60*60 (int) in
        // include/config_default.inc.php, but once persisted to the config DB
        // table it comes back as a raw string (see load_conf_from_db())
        $auth_key_duration = \Piwigo\Config\CurrentConfig::authKeyDuration();

        if ($auth_key_duration === 0) {
            return false;
        }

        if (! isset($userStatus)) {
            // we have to find the user status
            $userStatus = $this->repo->findUserStatus(UserId::from($userId));

            if ($userStatus === null) {
                return false;
            }
        }

        if (! in_array($userStatus, ['normal', 'generic'], true)) {
            return false;
        }

        $candidate = SessionService::get()->generateKey(30);

        if (! $this->repo->authKeyCandidateExists($candidate)) {
            $now = Env::now();
            $expiration = (clone $now)->modify('+' . $auth_key_duration . ' seconds');

            $key = [
                'auth_key' => $candidate,
                'user_id' => $userId,
                'created_on' => $now->format('Y-m-d H:i:s'),
                'duration' => $auth_key_duration,
                'expired_on' => $expiration->format('Y-m-d H:i:s'),
                'key_type' => 'auth_key',
            ];

            $key['auth_key_id'] = $this->repo->insertAuthKey($key);

            return $key;
        } else {
            return $this->createUserAuthKey($userId, $userStatus);
        }
    }

    /**
     * Deactivates authentication keys.
     *
     * @since 2.8
     */
    public function deactivateUserAuthKeys(int $userId): void
    {
        $this->repo->deactivateAuthKeys($userId, Env::now());
    }

    /**
     * @since 11
     */
    public function deactivatePasswordResetKey(int $userId): void
    {
        $this->repo->clearActivationKey(UserId::from($userId));
    }

    /**
     * Generate reset password link.
     *
     * @since 15
     * @return array{time_validation: string, password_link: string}
     */
    public function generatePasswordLink(int $userId, UrlServiceInterface $urlService, bool $firstLogin = false): array
    {

        $activation_key = SessionService::get()->generateKey(20);

        $duration = $firstLogin
        ? \Piwigo\Config\CurrentConfig::passwordActivationDuration()
        : \Piwigo\Config\CurrentConfig::passwordResetDuration();
        $expire = (clone Env::now())->modify('+' . $duration . ' seconds');

        $this->repo->setActivationKey(UserId::from($userId), $this->passwordService->hash($activation_key), $expire);

        $urlService->setMakeFullUrl();

        $password_link = $urlService->getRootUrl() . 'password.php?key=' . $activation_key;

        $urlService->unsetMakeFullUrl();

        $validation_timestamp = strtotime('now -' . $duration . ' second');
        if ($validation_timestamp === false) {
            throw new \Exception('generatePasswordLink(): strtotime() failed for duration ' . $duration);
        }
        $time_validation = \Piwigo\Core\DateHelper::timeSince($validation_timestamp, 'second', null, false);

        return [
            'time_validation' => $time_validation,
            'password_link' => $password_link,
        ];
    }

    /**
     * Gets the last visit (datetime) of a user, based on history table.
     *
     * @since 2.9
     * @param bool $saveInUserInfos to store result in user_infos.last_visit
     * @return string|null date & time of last visit
     */
    public function getUserLastVisitFromHistory(int $userId, bool $saveInUserInfos = false): ?string
    {
        $last_visit = $this->repo->findLastVisitFromHistory($userId);

        if ($saveInUserInfos) {
            $this->repo->saveLastVisitFromHistory($userId, $last_visit);
        }

        return $last_visit;
    }

    /**
     * See if this is the first time the user has logged on.
     *
     * @since 15
     */
    public function hasAlreadyLoggedIn(int $userId): bool
    {
        return $this->repo->countLoginActivity($userId) === 0;
    }

    /**
     * Generate a user code for verification.
     *
     * @since 16
     * @return array{secret: string, code: string}
     */
    public static function generateUserCode(): array
    {

        $secret = PwgTOTP::generateSecret();
        // password_reset_code_duration defaults to 5*60 (int) in
        // include/config_default.inc.php, but once persisted to the config DB
        // table it comes back as a raw string (see load_conf_from_db())
        $password_reset_code_duration = \Piwigo\Config\CurrentConfig::passwordResetCodeDuration();
        $code = PwgTOTP::generateCode($secret, min($password_reset_code_duration, 900)); // max 15 minutes

        return [
            'secret' => $secret,
            'code' => $code,
        ];
    }

    /**
     * @since 16
     */
    public static function verifyUserCode(string $secret, string $code): bool
    {

        // see generateUserCode() for why this needs numeric narrowing
        $password_reset_code_duration = \Piwigo\Config\CurrentConfig::passwordResetCodeDuration();
        return PwgTOTP::verifyCode($code, $secret, min($password_reset_code_duration, 900), 1);
    }
}
