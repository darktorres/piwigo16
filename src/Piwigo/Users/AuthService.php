<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;

final readonly class AuthService
{
    public function __construct(
        private UserRepository $userRepo,
        private AuthKeyRepository $authKeyRepo,
        private Connection $conn,
        private StringUtil $stringUtil,
        private Util $util,
        private SessionService $sessionService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private DateService $dateService,
    ) {
    }

    /** @deprecated use constructor injection; will be removed when last caller is migrated. */
    public static function get(): self
    {
        return Kernel::service(self::class);
    }

    public function validateMailAddress(?int $userId, ?string $mailAddress): string|null
    {
        if (($mailAddress === null || $mailAddress === '') and
            !(Config::obligatoryUserMailAddress() and in_array(StringUtil::scriptBasename(), ['register', 'profile']))) {
            return '';
        }

        if (!$this->stringUtil->emailCheckFormat($mailAddress ?? '')) {
            return Lang::t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if (InstallSentinel::isInstalled() and $mailAddress !== null && $mailAddress !== '') {
            $userFields = Config::userFields();
            $count = $this->userRepo->countByEmail(
                $userFields['email'],
                $userFields['id'],
                Tables::users(),
                $mailAddress,
                $userId
            );
            if ($count != 0) {
                return Lang::t('this email address is already in use');
            }
        }
        return null;
    }

    public function validateLoginCase(string $login): string|null
    {
        if (InstallSentinel::isInstalled()) {
            $count = $this->userRepo->countByUsernameInsensitive(
                Config::userFields()['username'],
                Tables::users(),
                $login
            );
            if ($count > 0) {
                return Lang::t('this login is already used');
            }
        }
        return null;
    }

    public function searchCaseUsername(string $username): string
    {
        $usernameLo  = strtolower($username);
        $allUsernames = $this->userRepo->findAllUsernames(Config::userFields()['username'], Tables::users());
        $scuUsers = [];
        foreach ($allUsernames as $u) {
            $scuUsers[$u] = strtolower($u);
        }
        $usersFound = array_keys($scuUsers, $usernameLo);
        if (count($usersFound) != 1) {
            return $username;
        }
        return $usersFound[0];
    }

    public function calculateAutoLoginKey(int $userId, int $time, mixed &$username): string|false
    {
        $userFields = Config::userFields();
        $row = $this->userRepo->findAuthFieldsById(
            $userFields['username'],
            $userFields['password'],
            $userFields['id'],
            Tables::users(),
            $userId
        );
        if ($row !== null) {
            $username  = stripslashes($row['username']);
            $timeStr   = (string) $time;
            $userIdStr = (string) $userId;
            $data      = $timeStr . $userIdStr . $username;
            return base64_encode(hash_hmac('sha1', $data, Config::secretKey() . $row['password'], true));
        }
        return false;
    }

    public function logUser(int $userId, bool $rememberMe): void
    {
        $cookieLang = is_string($_COOKIE['lang'] ?? null) ? $_COOKIE['lang'] : '';
        if ($cookieLang !== '' and CurrentUser::get()->language != $cookieLang) {
            if (!array_key_exists($cookieLang, $this->util->getLanguages())) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $cookieLang . '" is not valid');
            }
            Dml::singleUpdate(Tables::userInfos(), ['language' => $cookieLang], ['user_id' => $userId]);
            setcookie('lang', '', ['expires' => time() - 3600, 'samesite' => 'Strict']);
        }

        if ($rememberMe and Config::authorizeRemembering()) {
            $now = time();
            $key = $this->calculateAutoLoginKey($userId, $now, $username);
            if ($key !== false) {
                $cookie = $userId . '-' . $now . '-' . $key;
                setcookie(Config::rememberMeName(), $cookie, [
                    'expires'  => time() + Config::rememberMeLength(),
                    'path'     => (string) CookieService::cookiePath(),
                    'domain'   => (string) ini_get('session.cookie_domain'),
                    'secure'   => (bool) ini_get('session.cookie_secure'),
                    'httponly' => (bool) ini_get('session.cookie_httponly'),
                    'samesite' => 'Strict',
                ]);
            }
        } else {
            setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) CookieService::cookiePath(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        }
        if (session_id() != '') {
            session_regenerate_id(true);
        } else {
            session_start();
        }
        $_SESSION['pwg_uid'] = $userId;

        EventDispatcher::notify('user_login', $userId);
        $this->util->pwgActivity('user', $userId, 'login');
    }

    public function autoLogin(): bool
    {
        $rememberMeName = Config::rememberMeName();
        $rememberCookieRaw = ($rememberMeName !== '') ? ($_COOKIE[$rememberMeName] ?? null) : null;
        if (is_string($rememberCookieRaw)) {
            $cookie = explode('-', stripslashes($rememberCookieRaw));
            if (count($cookie) === 3
                and is_numeric($cookie[0])
                and is_numeric($cookie[1])
                and time() - Config::rememberMeLength() <= $cookie[1]
                and time() >= $cookie[1]) {
                $key = $this->calculateAutoLoginKey((int) $cookie[0], (int) $cookie[1], $username);
                if ($key !== false and $key === $cookie[2]) {
                    if (StringUtil::scriptBasename() != 'ws') {
                        $_SESSION['connected_with'] = 'pwg_ui';
                    }
                    $this->logUser((int) $cookie[0], true);
                    EventDispatcher::notify('login_success', is_scalar($username) ? stripslashes((string) $username) : '');
                    return true;
                }
            }
            setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) CookieService::cookiePath(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        }
        return false;
    }

    public function tryLogUser(string $username, string $password, bool $rememberMe): bool
    {
        return (bool) EventDispatcher::dispatch('try_log_user', false, $username, $password, $rememberMe);
    }

    public function pwgLogin(bool $success, string $username, string $password, bool $rememberMe): bool
    {
        if ($success === true) {
            return true;
        }

        $this->sessionService->sessionGc();

        $userFound = $this->findUserByUsernameOrEmail($username);
        $fakeUser  = $this->generateFakeUser();
        $hash      = ($userFound !== null ? ($userFound['password'] ?? null) : null) ?? $fakeUser['password'];
        $passwordVerify = password_verify($password, is_string($hash) ? $hash : '');
        $ufIdRaw = $userFound !== null ? ($userFound['id'] ?? null) : null;
        $ufId = is_numeric($ufIdRaw) ? (int) $ufIdRaw : 0;

        if ($userFound === null || count($userFound) === 0 || 'guest' === $userFound['status'] || !$passwordVerify) {
            if ($userFound !== null && count($userFound) > 0 && !$passwordVerify) {
                $this->util->pwgActivity('user', $ufId, 'login_failure_wrong_password');
            }
            EventDispatcher::notify('login_failure', stripslashes($username));
            return false;
        }

        $stateInit = ['can_login' => true, 'reason' => null, 'authenticated' => false];
        /** @var array<string, mixed> $state */
        $state     = EventDispatcher::dispatch('finalize_login', $stateInit, $userFound, $rememberMe);

        if (!$state['can_login']) {
            /** @psalm-var mixed $stateReasonRaw */
            $stateReasonRaw = $state['reason'] ?? null;
            $stateReason    = is_string($stateReasonRaw) ? $stateReasonRaw : 'login_failure_before_log_user';
            $this->util->pwgActivity('user', $ufId, $stateReason);
            EventDispatcher::notify('login_failure_before_log_user', stripslashes($username));
            return false;
        }

        if (!$state['authenticated']) {
            $this->logUser($ufId, $rememberMe);
        }

        $this->clearFakeUserCache();
        EventDispatcher::notify('login_success', stripslashes($username));
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
            Tables::users(),
            $usernameOrEmail
        );

        if ($user !== null && count($user) > 0) {
            $user['status'] ??= 'normal';
            return $user;
        }
        return null;
    }

    /** @return array<mixed> */
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
        EventDispatcher::notify('user_logout', $logoutUid);
        $this->util->pwgActivity('user', $logoutUid, 'logout');

        $_SESSION = [];
        session_unset();
        session_destroy();
        setcookie((string) session_name(), '', ['expires' => 0, 'path' => (string) ini_get('session.cookie_path'), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) CookieService::cookiePath(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
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
  FROM ' . Tables::userAuthKeys() . ' AS uak
    JOIN ' . Tables::userInfos() . ' AS ui ON uak.user_id = ui.user_id
    JOIN ' . Tables::users() . ' AS u ON u.' . Config::userFields()['id'] . ' = ui.user_id
  WHERE auth_key = ' . $this->conn->quote($authKey) . '
;';
        $keys = $this->conn->executeQuery($query)->fetchAllAssociative();

        if (count($keys) == 0) {
            return false;
        }

        $key = $keys[0];

        if (strtotime(is_string($key['expired_on'] ?? null) ? $key['expired_on'] : '') < strtotime(is_string($key['dbnow'] ?? null) ? $key['dbnow'] : '')) {
            $page['auth_key_invalid'] = true;
            return false;
        }

        if ('auth_key' === $validKey and !in_array($key['status'], ['normal', 'generic'])) {
            return false;
        }

        if ('api_key' === $validKey) {
            $apikeySecret = is_string($key['apikey_secret'] ?? null) ? $key['apikey_secret'] : '';
            if (!password_verify((string) $secretKey, $apikeySecret)) {
                return false;
            }
            if (null != $key['revoked_on']) {
                return false;
            }
            $daysLeft = is_numeric($key['days_left']) ? (int) $key['days_left'] : 0;
            $lastNotifiedOnRaw = $key['last_notified_on'] ?? null;
            $ago48hRaw         = $key['48h_ago'] ?? null;
            if ($daysLeft <= 7 and !empty($key['email']) and
                (null === $lastNotifiedOnRaw or
                 strtotime(is_string($lastNotifiedOnRaw) ? $lastNotifiedOnRaw : '') < strtotime(is_string($ago48hRaw) ? $ago48hRaw : ''))) {
                $page['notify_api_key_expiration'] = [
                    'days_left' => $daysLeft,
                    'dbnow'     => $key['dbnow'],
                    'auth_key'  => $key['auth_key'],
                ];
            }
        }

        $user['id'] = $key['user_id'];
        CurrentUser::setRawAttributes($user);

        Dml::singleUpdate(Tables::userAuthKeys(), ['last_used_on' => $key['dbnow']], ['user_id' => $user['id'], 'auth_key' => $key['auth_key']]);

        $_SESSION['connected_with'] = $validKey;

        if ($connectionByHeader) {
            return true;
        }

        $this->logUser(is_numeric($user['id']) ? (int) $user['id'] : 0, false);
        EventDispatcher::notify('login_success', $key['username']);
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
            $userInfos = $this->conn->executeQuery('SELECT status FROM ' . Tables::userInfos() . ' WHERE user_id = ' . $userId . ';')->fetchAllAssociative();
            if (count($userInfos) == 0) {
                return false;
            }
            $userStatus = is_scalar($userInfos[0]['status']) ? (string) $userInfos[0]['status'] : null;
        }

        if (!in_array($userStatus, ['normal', 'generic'])) {
            return false;
        }

        $candidate = StringUtil::generateKey(30);
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
            Dml::singleInsert(Tables::userAuthKeys(), $key);
            $lastId = $this->conn->lastInsertId();
            $key['auth_key_id'] = is_numeric($lastId) ? (int) $lastId : 0;
            return $key;
        } else {
            return $this->createUserAuthKey($userId, $userStatus);
        }
    }

    public function deactivateUserAuthKeys(int $userId): void
    {
        $this->authKeyRepo->deactivateForUser($userId);
    }

    public function deactivatePasswordResetKey(int $userId): void
    {
        Dml::singleUpdate(Tables::userInfos(), ['activation_key' => null, 'activation_key_expire' => null], ['user_id' => $userId]);
    }

    /** @return array<string,mixed> */
    public function generatePasswordLink(int $userId, bool $firstLogin = false): array
    {
        $activationKey = StringUtil::generateKey(20);
        $duration      = $firstLogin ? Config::passwordActivationDuration() : Config::passwordResetDuration();
        $expire        = new \DateTimeImmutable()->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s');

        Dml::singleUpdate(Tables::userInfos(), [
            'activation_key'        => password_hash($activationKey, PASSWORD_BCRYPT),
            'activation_key_expire' => $expire,
        ], ['user_id' => $userId]);

        $this->urlService->setMakeFullUrl();
        $passwordLink = $this->urlService->addUrlParams($this->urlGenerator->password(), ['key' => $activationKey]);
        $this->urlService->unsetMakeFullUrl();

        $strAuthResult = strtotime('now -' . $duration . ' second');
        $timeValidation = $this->dateService->timeSince($strAuthResult !== false ? $strAuthResult : null, 'second', null, false);

        return ['time_validation' => $timeValidation, 'password_link' => $passwordLink];
    }

    public function connectedWithPwgUi(): bool
    {
        return isset($_SESSION['connected_with']) and 'pwg_ui' === $_SESSION['connected_with'];
    }
}
