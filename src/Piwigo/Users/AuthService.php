<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Core\InstallSentinel;
use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Config\Config;

final readonly class AuthService
{
    public function __construct(
        private UserRepository $userRepo,
        private AuthKeyRepository $authKeyRepo,
        private Connection $conn,
    ) {
    }

    public function validateMailAddress(?int $userId, ?string $mailAddress): string|null
    {
        if (empty($mailAddress) and
            !(Config::obligatoryUserMailAddress() and in_array(script_basename(), ['register', 'profile']))) {
            return '';
        }

        if (!email_check_format($mailAddress ?? '')) {
            return l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if (InstallSentinel::isInstalled() and !empty($mailAddress)) {
            $userFields = Config::userFields();
            $count = $this->userRepo->countByEmail(
                $userFields['email'],
                $userFields['id'],
                USERS_TABLE,
                $mailAddress,
                is_numeric($userId) ? (int) $userId : null
            );
            if ($count != 0) {
                return l10n('this email address is already in use');
            }
        }
        return null;
    }

    public function validateLoginCase(mixed $login): string|null
    {
        if (InstallSentinel::isInstalled()) {
            $count = $this->userRepo->countByUsernameInsensitive(
                Config::userFields()['username'],
                USERS_TABLE,
                is_scalar($login) ? (string) $login : ''
            );
            if ($count > 0) {
                return l10n('this login is already used');
            }
        }
        return null;
    }

    public function searchCaseUsername(mixed $username): string
    {
        $usernameStr = is_scalar($username) ? (string) $username : '';
        $usernameLo  = strtolower($usernameStr);
        $allUsernames = $this->userRepo->findAllUsernames(Config::userFields()['username'], USERS_TABLE);
        $scuUsers = [];
        foreach ($allUsernames as $u) {
            $scuUsers[$u] = strtolower($u);
        }
        $usersFound = array_keys($scuUsers, $usernameLo);
        if (count($usersFound) != 1) {
            return $usernameStr;
        }
        return (string) $usersFound[0];
    }

    public function calculateAutoLoginKey(mixed $userId, mixed $time, mixed &$username): string|false
    {
        $userFields = Config::userFields();
        $row = $this->userRepo->findAuthFieldsById(
            $userFields['username'],
            $userFields['password'],
            $userFields['id'],
            USERS_TABLE,
            is_numeric($userId) ? (int) $userId : 0
        );
        if ($row !== null) {
            $username  = stripslashes($row['username']);
            $timeStr   = is_scalar($time) ? (string) $time : '';
            $userIdStr = is_scalar($userId) ? (string) $userId : '';
            $data      = $timeStr . $userIdStr . $username;
            return base64_encode(hash_hmac('sha1', $data, Config::secretKey() . $row['password'], true));
        }
        return false;
    }

    public function logUser(mixed $userId, mixed $rememberMe): void
    {
        $cookieLang = isset($_COOKIE['lang']) && is_scalar($_COOKIE['lang']) ? (string) $_COOKIE['lang'] : '';
        if ($cookieLang !== '' and CurrentUser::get()->language != $cookieLang) {
            if (!array_key_exists($cookieLang, get_languages())) {
                fatal_error('[Hacking attempt] the input parameter "' . $cookieLang . '" is not valid');
            }
            single_update(USER_INFOS_TABLE, ['language' => $cookieLang], ['user_id' => $userId]);
            setcookie('lang', '', ['expires' => time() - 3600, 'samesite' => 'Strict']);
        }

        if ($rememberMe and Config::authorizeRemembering()) {
            $now = time();
            $key = $this->calculateAutoLoginKey($userId, $now, $username);
            if ($key !== false) {
                $cookie = (is_scalar($userId) ? (string) $userId : '') . '-' . $now . '-' . $key;
                setcookie(Config::rememberMeName(), $cookie, [
                    'expires'  => time() + Config::rememberMeLength(),
                    'path'     => (string) cookie_path(),
                    'domain'   => (string) ini_get('session.cookie_domain'),
                    'secure'   => (bool) ini_get('session.cookie_secure'),
                    'httponly' => (bool) ini_get('session.cookie_httponly'),
                    'samesite' => 'Strict',
                ]);
            }
        } else {
            setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        }
        if (session_id() != '') {
            session_regenerate_id(true);
        } else {
            session_start();
        }
        $uid = is_numeric($userId) ? (int) $userId : 0;
        $_SESSION['pwg_uid'] = $uid;

        trigger_notify('user_login', $uid);
        pwg_activity('user', $uid, 'login');
    }

    public function autoLogin(): bool
    {
        $rememberCookieRaw = $_COOKIE[Config::rememberMeName()] ?? null;
        if (isset($rememberCookieRaw)) {
            $cookie = explode('-', stripslashes(is_scalar($rememberCookieRaw) ? (string) $rememberCookieRaw : ''));
            if (count($cookie) === 3
                and is_numeric($cookie[0])
                and is_numeric($cookie[1])
                and time() - Config::rememberMeLength() <= $cookie[1]
                and time() >= $cookie[1]) {
                $key = $this->calculateAutoLoginKey((int) $cookie[0], (int) $cookie[1], $username);
                if ($key !== false and $key === $cookie[2]) {
                    if (script_basename() != 'ws') {
                        $_SESSION['connected_with'] = 'pwg_ui';
                    }
                    $this->logUser((int) $cookie[0], true);
                    trigger_notify('login_success', is_scalar($username) ? stripslashes((string) $username) : '');
                    return true;
                }
            }
            setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        }
        return false;
    }

    public function tryLogUser(mixed $username, mixed $password, mixed $rememberMe): bool
    {
        return (bool) trigger_change('try_log_user', false, $username, $password, $rememberMe);
    }

    public function pwgLogin(bool $success, string $username, string $password, bool $rememberMe): bool
    {
        if ($success === true) {
            return true;
        }

        pwg_session_gc();

        $userFound = $this->findUserByUsernameOrEmail($username);
        $fakeUser  = $this->generateFakeUser();
        $hash      = $userFound['password'] ?? $fakeUser['password'];
        $passwordVerify = password_verify($password, is_string($hash) ? $hash : '');
        $ufId = is_numeric($userFound['id'] ?? null) ? (int) $userFound['id'] : 0;

        if (empty($userFound) || 'guest' === $userFound['status'] || !$passwordVerify) {
            if (!empty($userFound) && !$passwordVerify) {
                pwg_activity('user', $ufId, 'login_failure_wrong_password');
            }
            trigger_notify('login_failure', stripslashes($username));
            return false;
        }

        $stateInit = ['can_login' => true, 'reason' => null, 'authenticated' => false];
        $state     = trigger_change('finalize_login', $stateInit, $userFound, $rememberMe);

        if (!$state['can_login']) {
            $stateReason = is_scalar($state['reason']) ? (string) $state['reason'] : 'login_failure_before_log_user';
            pwg_activity('user', $ufId, $stateReason);
            trigger_notify('login_failure_before_log_user', stripslashes($username));
            return false;
        }

        if (!$state['authenticated']) {
            $this->logUser($ufId, $rememberMe);
        }

        $this->clearFakeUserCache();
        trigger_notify('login_success', stripslashes($username));
        return true;
    }

    /** @return array<string,mixed>|null */
    public function findUserByUsernameOrEmail(string $usernameOrEmail): ?array
    {
        $userFields = Config::userFields();
        $user = $this->userRepo->findByUsernameOrEmail(
            $userFields['username'],
            $userFields['email'],
            $userFields['id'],
            $userFields['password'],
            USERS_TABLE,
            $usernameOrEmail
        );

        if (!empty($user)) {
            $user['status'] ??= 'normal';
            return $user;
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function generateFakeUser(): array
    {
        if (!isset($_SESSION['fake_user_cache'])) {
            $fakePassword = bin2hex(random_bytes(10));
            $_SESSION['fake_user_cache'] = [
                'id'       => null,
                'password' => password_hash($fakePassword, PASSWORD_BCRYPT),
            ];
        }
        $fakeCache = $_SESSION['fake_user_cache'];
        return is_array($fakeCache) ? $fakeCache : ['id' => null, 'password' => ''];
    }

    public function clearFakeUserCache(): void
    {
        unset($_SESSION['fake_user_cache']);
    }

    public function logoutUser(): void
    {
        $logoutUid = isset($_SESSION['pwg_uid']) && is_numeric($_SESSION['pwg_uid']) ? (int) $_SESSION['pwg_uid'] : 0;
        trigger_notify('user_logout', $logoutUid);
        pwg_activity('user', $logoutUid, 'logout');

        $_SESSION = [];
        session_unset();
        session_destroy();
        setcookie((string) session_name(), '', ['expires' => 0, 'path' => (string) ini_get('session.cookie_path'), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
    }

    public function authKeyLogin(string $authKey, bool $connectionByHeader = false): bool
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $user = &$GLOBALS['user'];
        if (!is_array($user)) {
            $user = [];
        }
        $validKey  = false;
        $secretKey = null;
        if (preg_match('/^[a-z0-9]{30}$/i', $authKey)) {
            $validKey = 'auth_key';
        } elseif (preg_match('/^pkid-\d{8}-[a-z0-9]{20}:[a-z0-9]{40}$/i', $authKey)) {
            $validKey = 'api_key';
            $tmp      = explode(':', $authKey);
            $authKey  = $tmp[0];
            $secretKey = $tmp[1];
        }

        if (!$validKey) {
            return false;
        }

        $query = '
SELECT
    *,
    ' . Config::userFields()['username'] . ' AS username,
    ' . Config::userFields()['email'] . ' AS email,
    NOW() AS dbnow,
    DATEDIFF(uak.expired_on, NOW()) AS days_left,
    SUBDATE(NOW(), INTERVAL 48 HOUR) AS 48h_ago
  FROM ' . USER_AUTH_KEYS_TABLE . ' AS uak
    JOIN ' . USER_INFOS_TABLE . ' AS ui ON uak.user_id = ui.user_id
    JOIN ' . USERS_TABLE . ' AS u ON u.' . Config::userFields()['id'] . ' = ui.user_id
  WHERE auth_key = ' . $this->conn->quote($authKey) . '
;';
        $keys = $this->conn->executeQuery($query)->fetchAllAssociative();

        if (count($keys) == 0) {
            return false;
        }

        $key = $keys[0];

        if (strtotime(is_scalar($key['expired_on']) ? (string) $key['expired_on'] : '') < strtotime(is_scalar($key['dbnow']) ? (string) $key['dbnow'] : '')) {
            $page['auth_key_invalid'] = true;
            return false;
        }

        if ('auth_key' === $validKey and !in_array($key['status'], ['normal', 'generic'])) {
            return false;
        }

        if ('api_key' === $validKey) {
            $apikeySecret = is_scalar($key['apikey_secret']) ? (string) $key['apikey_secret'] : '';
            if (!password_verify((string) $secretKey, $apikeySecret)) {
                return false;
            }
            if (null != $key['revoked_on']) {
                return false;
            }
            $daysLeft = is_numeric($key['days_left']) ? (int) $key['days_left'] : 0;
            if ($daysLeft <= 7 and !empty($key['email']) and
                (null === $key['last_notified_on'] or
                 strtotime(is_scalar($key['last_notified_on']) ? (string) $key['last_notified_on'] : '') < strtotime(is_scalar($key['48h_ago']) ? (string) $key['48h_ago'] : ''))) {
                $page['notify_api_key_expiration'] = [
                    'days_left' => $daysLeft,
                    'dbnow'     => $key['dbnow'],
                    'auth_key'  => $key['auth_key'],
                ];
            }
        }

        $user['id'] = $key['user_id'];
        CurrentUser::setRawAttributes($user);

        single_update(USER_AUTH_KEYS_TABLE, ['last_used_on' => $key['dbnow']], ['user_id' => $user['id'], 'auth_key' => $key['auth_key']]);

        $_SESSION['connected_with'] = $validKey;

        if ($connectionByHeader) {
            return true;
        }

        $this->logUser(is_numeric($user['id']) ? (int) $user['id'] : 0, false);
        trigger_notify('login_success', $key['username']);
        $page['auth_key_id'] = $key['auth_key_id'];

        return true;
    }

    /** @return array<string,mixed>|false */
    public function createUserAuthKey(int $userId, ?string $userStatus = null): array|false
    {
        if (0 == Config::authKeyDuration()) {
            return false;
        }

        if (!isset($userStatus)) {
            $userInfos = $this->conn->executeQuery('SELECT status FROM ' . USER_INFOS_TABLE . ' WHERE user_id = ' . $userId . ';')->fetchAllAssociative();
            if (count($userInfos) == 0) {
                return false;
            }
            $userStatus = is_scalar($userInfos[0]['status']) ? (string) $userInfos[0]['status'] : null;
        }

        if (!in_array($userStatus, ['normal', 'generic'])) {
            return false;
        }

        $candidate = generate_key(30);
        if (!$this->authKeyRepo->existsByKey($candidate)) {
            $now      = new \DateTimeImmutable()->format('Y-m-d H:i:s');
            $duration = Config::authKeyDuration();
            $expiry   = new \DateTimeImmutable()->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s');
            $key      = [
                'auth_key'   => $candidate,
                'user_id'    => $userId,
                'created_on' => $now,
                'duration'   => $duration,
                'expired_on' => $expiry,
                'key_type'   => 'auth_key',
            ];
            single_insert(USER_AUTH_KEYS_TABLE, $key);
            $lastId = get_dbal_connection()->lastInsertId();
            $key['auth_key_id'] = is_numeric($lastId) ? (int) $lastId : 0;
            return $key;
        } else {
            return $this->createUserAuthKey($userId, $userStatus);
        }
    }

    public function deactivateUserAuthKeys(mixed $userId): void
    {
        $this->authKeyRepo->deactivateForUser(is_numeric($userId) ? (int) $userId : 0);
    }

    public function deactivatePasswordResetKey(mixed $userId): void
    {
        single_update(USER_INFOS_TABLE, ['activation_key' => null, 'activation_key_expire' => null], ['user_id' => $userId]);
    }

    /** @return array<string,mixed> */
    public function generatePasswordLink(int $userId, bool $firstLogin = false): array
    {
        $activationKey = generate_key(20);
        $duration      = $firstLogin ? Config::passwordActivationDuration() : Config::passwordResetDuration();
        $expire        = new \DateTimeImmutable()->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s');

        single_update(USER_INFOS_TABLE, [
            'activation_key'        => password_hash($activationKey, PASSWORD_BCRYPT),
            'activation_key_expire' => $expire,
        ], ['user_id' => $userId]);

        set_make_full_url();
        $passwordLink = get_root_url() . 'password.php?key=' . $activationKey;
        unset_make_full_url();

        $timeValidation = time_since(strtotime('now -' . $duration . ' second') ?: null, 'second', null, false);

        return ['time_validation' => $timeValidation, 'password_link' => $passwordLink];
    }

    public function connectedWithPwgUi(): bool
    {
        return isset($_SESSION['connected_with']) and 'pwg_ui' === $_SESSION['connected_with'];
    }
}
