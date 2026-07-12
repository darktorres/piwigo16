<?php

declare(strict_types=1);

namespace Piwigo\Auth;

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
 * the original (global $conf['secret_key'], never Piwigo\Config\Config)
 * -- preserved as-is, not the Config::secretKey() bug found and fixed
 * elsewhere this phase (CsrfService/EphemeralKeyService).
 */
final class AuthService
{
    public function __construct(
        private readonly AuthRepository $repo,
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
        /** @var array<string, mixed> $conf */
        global $conf;

        // see validate_mail_address() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

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
        $secret_key = $conf['secret_key'] ?? '';
        $secret_key = is_string($secret_key) ? $secret_key : '';
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
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        // false is not reachable in practice -- see this method's own
        // docblock on $userId
        assert($userId !== false);

        // remember_me_name defaults to 'pwg_remember' (string),
        // remember_me_length to 5184000 (int) in
        // include/config_default.inc.php, but once persisted to the config
        // DB table both come back as raw strings (see load_conf_from_db())
        // -- accept either.
        $remember_me_name = $conf['remember_me_name'] ?? null;
        $remember_me_name = is_string($remember_me_name) ? $remember_me_name : 'pwg_remember';
        $remember_me_length = $conf['remember_me_length'] ?? null;
        $remember_me_length = is_numeric($remember_me_length) ? (int) $remember_me_length : 5184000;

        // New default login and register pages, if users changes languages
        // and succesfully logs in we want to update the userpref language
        // stored in a cookie
        if (isset($_COOKIE['lang']) && ($user['language'] ?? null) !== $_COOKIE['lang']) {
            $lang_cookie = $_COOKIE['lang'];
            if (! is_string($lang_cookie)) {
                fatal_error('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($lang_cookie, get_languages())) {
                fatal_error('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
            }

            $this->repo->updateLanguage($userId, $lang_cookie);

            // We unset the lang cookie, if user has changed their language
            // using interface we don't want to keep setting it back to
            // what was chosen using standard pages lang switch
            setcookie('lang', '', [
                'expires' => time() - 3600,
            ]);
        }

        if ($rememberMe && (bool) $conf['authorize_remembering']) {
            $now = time();
            $calculated = $this->calculateAutoLoginKey($userId, $now);
            if ($calculated['key'] !== false) {
                $cookie = $userId . '-' . $now . '-' . $calculated['key'];
                setcookie(
                    $remember_me_name,
                    $cookie,
                    [
                        'expires' => time() + $remember_me_length,
                        'path' => cookie_path(),
                        'domain' => (string) ini_get('session.cookie_domain'),
                        'secure' => (bool) ini_get('session.cookie_secure'),
                        'httponly' => (bool) ini_get('session.cookie_httponly'),
                    ]
                );
            }
        } else { // make sure we clean any remember me ...
            setcookie($remember_me_name, '', [
                'expires' => 0,
                'path' => cookie_path(),
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
        pwg_activity('user', $user['id'], 'login');
    }

    /**
     * Performs auto-connection when cookie remember_me exists.
     */
    public function autoLogin(): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // see logUser() for why these accept both the config-default
        // scalar type and the DB-persisted string form
        $remember_me_name = $conf['remember_me_name'] ?? null;
        $remember_me_name = is_string($remember_me_name) ? $remember_me_name : 'pwg_remember';
        $remember_me_length = $conf['remember_me_length'] ?? null;
        $remember_me_length = is_numeric($remember_me_length) ? (int) $remember_me_length : 5184000;

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
                        if (script_basename() !== 'ws') {
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
                'path' => cookie_path(),
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
        /** @var array<string, mixed> $conf */
        global $conf;

        $pwg_uid = $_SESSION['pwg_uid'] ?? null;
        trigger_notify('user_logout', $pwg_uid);
        if (is_int($pwg_uid) || is_string($pwg_uid)) {
            pwg_activity('user', $pwg_uid, 'logout');
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
        $remember_me_name = $conf['remember_me_name'] ?? null;
        $remember_me_name = is_string($remember_me_name) ? $remember_me_name : 'pwg_remember';
        setcookie($remember_me_name, '', [
            'expires' => 0,
            'path' => cookie_path(),
            'domain' => (string) ini_get('session.cookie_domain'),
        ]);
    }
}
