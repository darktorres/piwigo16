<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
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
 * the original (global \Piwigo\Config\Config::secretKey(), never Piwigo\Config\Config)
 * -- preserved as-is, not the Config::secretKey() bug found and fixed
 * elsewhere this phase (CsrfService/EphemeralKeyService).
 */
final readonly class AuthService
{
    public function __construct(
        private AuthRepository $repo,
        private ActivityLoggerInterface $activityLogger,
        private HtmlRenderingInterface $htmlRenderer,
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
        $user_fields = \Piwigo\Config\Config::userFields();

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
        $secret_key = \Piwigo\Config\Config::secretKey();
        $key = base64_encode(hash_hmac('sha256', $data, $secret_key . $found['password'], true));

        return [
            'key' => $key,
            'username' => $username,
        ];
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
        /**
         * @var array<string, mixed>
         */
        global $user;

        // false is not reachable in practice -- see this method's own
        // docblock on $userId
        assert($userId !== false);

        // remember_me_name defaults to 'pwg_remember' (string),
        // remember_me_length to 5184000 (int) in
        // include/config_default.inc.php, but once persisted to the config
        // DB table both come back as raw strings (see load_conf_from_db())
        // -- accept either.
        $remember_me_name = \Piwigo\Config\Config::rememberMeName();
        $remember_me_length = \Piwigo\Config\Config::rememberMeLength();

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

            $this->repo->updateLanguage($userId, $lang_cookie);

            // We unset the lang cookie, if user has changed their language
            // using interface we don't want to keep setting it back to
            // what was chosen using standard pages lang switch
            setcookie('lang', '', [
                'expires' => time() - 3600,
            ]);
        }

        if ($rememberMe && \Piwigo\Config\Config::authorizeRemembering()) {
            $now = time();
            $calculated = $this->calculateAutoLoginKey($userId, $now);
            if ($calculated['key'] !== false) {
                $cookie = $userId . '-' . $now . '-' . $calculated['key'];
                setcookie(
                    $remember_me_name,
                    $cookie,
                    [
                        'expires' => time() + $remember_me_length,
                        'path' => new CookieService()
                            ->cookiePath(),
                        'domain' => (string) ini_get('session.cookie_domain'),
                        'secure' => (bool) ini_get('session.cookie_secure'),
                        'httponly' => (bool) ini_get('session.cookie_httponly'),
                    ]
                );
            }
        } else { // make sure we clean any remember me ...
            setcookie($remember_me_name, '', [
                'expires' => 0,
                'path' => new CookieService()
                    ->cookiePath(),
                'domain' => (string) ini_get('session.cookie_domain'),
            ]);
        }

        if (session_id() !== '') { // we regenerate the session for security reasons
            // see http://www.acros.si/papers/session_fixation.pdf
            session_regenerate_id(true);
        } else {
            session_start();
        }
        $_SESSION['pwg_uid'] = (int) $userId;

        $user['id'] = $_SESSION['pwg_uid'];
        trigger_notify('user_login', $user['id']);
        $this->activityLogger->record('user', $user['id'], 'login');
    }

    /**
     * Performs auto-connection when cookie remember_me exists.
     */
    public function autoLogin(): bool
    {

        // see logUser() for why these accept both the config-default
        // scalar type and the DB-persisted string form
        $remember_me_name = \Piwigo\Config\Config::rememberMeName();
        $remember_me_length = \Piwigo\Config\Config::rememberMeLength();

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
                        trigger_notify('login_success', stripslashes($calculated['username']));

                        return true;
                    }
                }
            }
            setcookie($remember_me_name, '', [
                'expires' => 0,
                'path' => new CookieService()
                    ->cookiePath(),
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
        $result = trigger_change('try_log_user', false, $username, $password, $rememberMe);

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
        trigger_notify('user_logout', $pwg_uid);
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
        $remember_me_name = \Piwigo\Config\Config::rememberMeName();
        setcookie($remember_me_name, '', [
            'expires' => 0,
            'path' => new CookieService()
                ->cookiePath(),
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

        // Find user by username or email (if it exists)
        $user_found = $this->findUserByUsernameOrEmail($username);

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
        $hash = $user_found['password'] ?? $fake_user['password'];
        assert(is_string($hash));
        $verify_user_id = $user_found['id'] ?? $fake_user['id'];
        if ($verify_user_id !== null) {
            assert(is_numeric($verify_user_id));
            $verify_user_id = (int) $verify_user_id;
        }
        $password_verify = new PasswordService(new PasswordRepository(DbConnection::build()))
            ->verify($password ?? '', $hash, $verify_user_id);

        // If the user was not found, is a guest, or the password is incorrect
        if (empty($user_found) || $user_found['status'] === 'guest' || ! $password_verify) {
            if (! empty($user_found) && ! $password_verify) {
                $found_user_id = $user_found['id'];
                assert(is_string($found_user_id));
                $this->activityLogger->record('user', $found_user_id, 'login_failure_wrong_password');
            }
            trigger_notify('login_failure', stripslashes($username));
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
        $state = trigger_change('finalize_login', $state, $user_found, $rememberMe);

        // trigger_change()'s own return type is mixed; `&&` always yields a
        // real bool regardless of operand types, which is what narrows
        // $can_login/$authenticated below without needing an assert().
        $can_login = is_array($state) && (bool) ($state['can_login'] ?? null);
        $reason = is_array($state) ? ($state['reason'] ?? null) : null;
        $authenticated = is_array($state) && (bool) ($state['authenticated'] ?? null);

        if (! $can_login) {
            $found_user_id = $user_found['id'];
            assert(is_string($found_user_id));
            $this->activityLogger->record('user', $found_user_id, is_string($reason) ? $reason : 'login_failure_before_log_user');
            trigger_notify('login_failure_before_log_user', stripslashes($username));
            return false;
        }

        // If plugin handled authentication, skip log_user()
        if (! $authenticated) {
            $found_user_id = $user_found['id'];
            assert(is_string($found_user_id) && is_numeric($found_user_id));
            $this->logUser($found_user_id, $rememberMe);
        }

        $this->clearFakeUserCache();
        trigger_notify('login_success', stripslashes($username));
        return true;
    }

    /**
     * Find user by username or email search by username first then email.
     *
     * @since 16
     * @return array<string, mixed>|null
     */
    public function findUserByUsernameOrEmail(string $usernameOrEmail): ?array
    {

        // see UserService::validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();

        $usernameOrEmail = \Piwigo\Db\MysqliDb::realEscapeString($usernameOrEmail);

        $query = '
SELECT
  ' . $user_fields['id'] . ' AS id,
  ' . $user_fields['username'] . ' AS username,
  ' . $user_fields['email'] . ' AS email,
  ' . $user_fields['password'] . ' AS password,
  status
FROM ' . Tables::users() . ' AS u
  LEFT JOIN ' . Tables::userInfos() . ' AS i
    ON u.' . $user_fields['id'] . ' = i.user_id
  WHERE ';

        $where_username = $user_fields['username'] . ' = \'' . $usernameOrEmail . '\'';
        $where_email = $user_fields['email'] . ' = \'' . $usernameOrEmail . '\'';

        $user_by_username = \Piwigo\Db\MysqliDb::fetchAssoc(\Piwigo\Db\MysqliDb::query($query . $where_username));
        $user = (bool) $user_by_username ? $user_by_username : \Piwigo\Db\MysqliDb::fetchAssoc(\Piwigo\Db\MysqliDb::query($query . $where_email));

        if (! empty($user)) {
            // The user may not exist in the user_infos table, so we consider it's a "normal" user by default
            $user['status'] ??= 'normal';
            return $user;
        }

        return null;
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
                'password' => new PasswordService(new PasswordRepository(DbConnection::build()))->hash($fake_password),
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
        /**
         * @var array<string, mixed>
         */
        global $user;

        // see UserService::validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();

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

        $query = '
SELECT
    *,
    ' . $user_fields['username'] . ' AS username,
    ' . $user_fields['email'] . ' AS email,
    NOW() AS dbnow,
    DATEDIFF(uak.expired_on, NOW()) AS days_left,
    SUBDATE(NOW(), INTERVAL 48 HOUR) AS 48h_ago
  FROM ' . Tables::userAuthKeys() . ' AS uak
    JOIN ' . Tables::userInfos() . ' AS ui ON uak.user_id = ui.user_id
    JOIN ' . Tables::users() . ' AS u ON u.' . $user_fields['id'] . ' = ui.user_id
  WHERE auth_key = \'' . $authKey . '\'
;';
        $keys = \Piwigo\Db\MysqliDb::query2Array($query);

        if (count($keys) == 0) {
            return false;
        }

        $key = $keys[0];

        // is the key still valid?
        if (strtotime((string) $key['expired_on']) < strtotime((string) $key['dbnow'])) {
            \Piwigo\Core\PageState::current()->markAuthKeyInvalid();
            return false;
        }

        // admin/webmaster/guest can't get connected with authentication keys
        if ($valid_key === 'auth_key' and ! in_array($key['status'], ['normal', 'generic'])) {
            return false;
        }

        // the key is an api_key
        if ($valid_key === 'api_key') {
            // check secret
            $apikey_secret = $key['apikey_secret'];
            if (! is_string($apikey_secret) || ! new PasswordService(new PasswordRepository(DbConnection::build()))->verify($secret_key ?? '', $apikey_secret)) {
                return false;
            }

            // is the key is revoked?
            if ($key['revoked_on'] != null) {
                return false;
            }

            // check if we need to notificate the user
            $days_left = intval($key['days_left']);
            if (
                $days_left <= 7 // the key expire in max 7 days
                and ! empty($key['email']) // the user have an email
                and (
                    $key['last_notified_on'] === null // we never send an email for this key
                    or strtotime($key['last_notified_on']) < strtotime((string) $key['48h_ago']) // OR when the last email was sent more than 48 hours ago
                )
            ) {
                \Piwigo\Core\PageState::current()->setNotifyApiKeyExpiration([
                    'days_left' => $days_left,
                    'dbnow' => $key['dbnow'],
                    'auth_key' => $key['auth_key'],
                ]);
            }
        }

        $key_user_id = $key['user_id'];
        assert(is_numeric($key_user_id));
        $user['id'] = $key_user_id;

        // update last used key
        \Piwigo\Db\MysqliDb::singleUpdate(
            Tables::userAuthKeys(),
            [
                'last_used_on' => $key['dbnow'],
            ],
            [
                'user_id' => $key_user_id,
                'auth_key' => $key['auth_key'],
            ],
        );

        // set the type of connection
        $_SESSION['connected_with'] = $valid_key;

        // if the connection is made via an API key in the header,
        // access is authenticated without creating a persistent user session
        // this enables stateless authentication for API calls
        if ($connectionByHeader) {
            return true;
        }

        $this->logUser($key_user_id, false);
        trigger_notify('login_success', $key['username']);

        // to be registered in history table by HistoryService::logVisit()
        $auth_key_id = $key['auth_key_id'];
        \Piwigo\Core\PageState::current()->setAuthKeyId(is_numeric($auth_key_id) ? (int) $auth_key_id : null);

        return true;
    }

    /**
     * Creates an authentication key.
     *
     * @since 2.8
     * @return array<string, mixed>|false false if auth keys are disabled or the user status is ineligible
     */
    public function createUserAuthKey(int $userId, ?string $userStatus = null): array|false
    {
        // auth_key_duration defaults to 3*24*60*60 (int) in
        // include/config_default.inc.php, but once persisted to the config DB
        // table it comes back as a raw string (see load_conf_from_db())
        $auth_key_duration = \Piwigo\Config\Config::authKeyDuration();

        if ($auth_key_duration == 0) {
            return false;
        }

        if (! isset($userStatus)) {
            // we have to find the user status
            $query = '
SELECT
    status
  FROM ' . Tables::userInfos() . '
  WHERE user_id = ' . $userId . '
;';
            $user_infos = \Piwigo\Db\MysqliDb::query2Array($query);

            if (count($user_infos) == 0) {
                return false;
            }

            $userStatus = $user_infos[0]['status'];
        }

        if (! in_array($userStatus, ['normal', 'generic'])) {
            return false;
        }

        $candidate = SessionService::get()->generateKey(30);

        $query = '
SELECT
    COUNT(*),
    NOW(),
    ADDDATE(NOW(), INTERVAL ' . $auth_key_duration . ' SECOND)
  FROM ' . Tables::userAuthKeys() . '
  WHERE auth_key = \'' . $candidate . '\'
;';
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
        assert($row !== null);
        [$counter, $now, $expiration] = $row;
        if ($counter == 0) {
            $key = [
                'auth_key' => $candidate,
                'user_id' => $userId,
                'created_on' => $now,
                'duration' => $auth_key_duration,
                'expired_on' => $expiration,
                'key_type' => 'auth_key',
            ];

            \Piwigo\Db\MysqliDb::singleInsert(Tables::userAuthKeys(), $key);

            $key['auth_key_id'] = \Piwigo\Db\MysqliDb::insertId();

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
        $query = '
UPDATE ' . Tables::userAuthKeys() . '
  SET expired_on = NOW()
  WHERE user_id = ' . $userId . '
    AND expired_on > NOW()
    AND key_type = \'auth_key\'
;';
        \Piwigo\Db\MysqliDb::query($query);
    }

    /**
     * Deactivates password reset key.
     *
     * @since 11
     */
    public function deactivatePasswordResetKey(int $userId): void
    {
        \Piwigo\Db\MysqliDb::singleUpdate(
            Tables::userInfos(),
            [
                'activation_key' => null,
                'activation_key_expire' => null,
            ],
            [
                'user_id' => $userId,
            ]
        );
    }

    /**
     * Generate reset password link.
     *
     * @since 15
     * @return array{time_validation: string, password_link: string}
     */
    public function generatePasswordLink(int $userId, bool $firstLogin = false): array
    {

        $activation_key = SessionService::get()->generateKey(20);

        // password_activation_duration/password_reset_duration default to ints
        // in include/config_default.inc.php, but once persisted to the config
        // DB table they come back as raw strings (see load_conf_from_db())
        $duration = $firstLogin
        ? \Piwigo\Config\Config::passwordActivationDuration()
        : \Piwigo\Config\Config::passwordResetDuration();
        $duration = is_numeric($duration) ? (int) $duration : 0;
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query('SELECT ADDDATE(NOW(), INTERVAL ' . $duration . ' SECOND)'));
        assert($row !== null);
        [$expire] = $row;

        \Piwigo\Db\MysqliDb::singleUpdate(
            Tables::userInfos(),
            [
                'activation_key' => new PasswordService(new PasswordRepository(DbConnection::build()))->hash($activation_key),
                'activation_key_expire' => $expire,
            ],
            [
                'user_id' => $userId,
            ]
        );

        set_make_full_url();

        $password_link = get_root_url() . 'password.php?key=' . $activation_key;

        unset_make_full_url();

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
        $last_visit = null;

        $query = '
SELECT
    date,
    time
FROM ' . Tables::history() . '
  WHERE user_id = ' . $userId . '
  ORDER BY id DESC
  LIMIT 1
;';
        $result = \Piwigo\Db\MysqliDb::query($query);
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $last_visit = $row['date'] . ' ' . $row['time'];
        }

        if ($saveInUserInfos) {
            $query = '
UPDATE ' . Tables::userInfos() . '
  SET last_visit = ' . ($last_visit === null ? 'NULL' : "'" . $last_visit . "'") . ',
      last_visit_from_history = \'true\',
      lastmodified = lastmodified
  WHERE user_id = ' . $userId . '
';
            \Piwigo\Db\MysqliDb::query($query);
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
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::activity() . '
  WHERE action = \'login\' and performed_by = ' . $userId . '';

        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
        assert($row !== null);
        [$logged_in] = $row;
        if ($logged_in > 0) {
            return false;
        }
        return true;
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
        $password_reset_code_duration = \Piwigo\Config\Config::passwordResetCodeDuration();
        $code = PwgTOTP::generateCode($secret, min($password_reset_code_duration, 900)); // max 15 minutes

        return [
            'secret' => $secret,
            'code' => $code,
        ];
    }

    /**
     * Verify a user code.
     *
     * @since 16
     */
    public static function verifyUserCode(string $secret, string $code): bool
    {

        // see generateUserCode() for why this needs numeric narrowing
        $password_reset_code_duration = \Piwigo\Config\Config::passwordResetCodeDuration();
        return PwgTOTP::verifyCode($code, $secret, min($password_reset_code_duration, 900), 1);
    }
}
