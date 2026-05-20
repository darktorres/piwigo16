<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\CookieService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Event\User\FinalizeLogin;
use Piwigo\Event\User\LoginFailure;
use Piwigo\Event\User\LoginFailureBeforeLogUser;
use Piwigo\Event\User\LoginSuccess;
use Piwigo\Event\User\TryLogUser;
use Piwigo\Event\User\UserLogin;
use Piwigo\Event\User\UserLogout;
use Piwigo\Html\HtmlService;
use Piwigo\Language\LanguageService;
use Piwigo\Session\ConnectionType;
use Piwigo\Session\Session;
use Piwigo\Session\SessionService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class AuthService
{
    public function __construct(
        private UserRepository $userRepo,
        private AuthKeyRepository $authKeyRepo,
        private ActivityLogger $activityLogger,
        private SessionService $sessionService,
        private Session $session,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private DateService $dateService,
        private LanguageService $languageService,
        private EventDispatcherInterface $dispatcher,
        private Paths $paths,
    ) {
    }

    public function validateMailAddress(?int $userId, ?string $mailAddress): string|null
    {
        if (($mailAddress === null || $mailAddress === '') and
            !(Config::obligatoryUserMailAddress() and in_array(StringUtil::scriptBasename(), ['register', 'profile']))) {
            return '';
        }

        if (!StringUtil::emailCheckFormat($mailAddress ?? '')) {
            return Lang::t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if (InstallSentinel::isInstalled($this->paths) and $mailAddress !== null && $mailAddress !== '') {
            $userFields = Config::userFields();
            $count = $this->userRepo->countByEmail(
                $userFields->email,
                $userFields->id,
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
        if (InstallSentinel::isInstalled($this->paths)) {
            $count = $this->userRepo->countByUsernameInsensitive(
                Config::userFields()->username,
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
        $allUsernames = $this->userRepo->findAllUsernames(Config::userFields()->username, Tables::users());
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
            $userFields->username,
            $userFields->password,
            $userFields->id,
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
            if (!array_key_exists($cookieLang, $this->languageService->getActiveLanguages())) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $cookieLang . '" is not valid');
            }
            $this->userRepo->updateLanguage($userId, $cookieLang);
            setcookie('lang', '', ['expires' => time() - 3600, 'samesite' => 'Strict']);
        }

        if ($rememberMe and Config::authorizeRemembering()) {
            $now = time();
            $key = $this->calculateAutoLoginKey($userId, $now, $username);
            if ($key !== false) {
                $cookie = $userId . '-' . $now . '-' . $key;
                setcookie(Config::rememberMeName(), $cookie, [
                    'expires'  => time() + Config::rememberMeLength(),
                    'path'     => CookieService::cookiePath(),
                    'domain'   => (string) ini_get('session.cookie_domain'),
                    'secure'   => (bool) ini_get('session.cookie_secure'),
                    'httponly' => (bool) ini_get('session.cookie_httponly'),
                    'samesite' => 'Strict',
                ]);
            }
        } else {
            setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => CookieService::cookiePath(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        }
        if (session_id() != '') {
            session_regenerate_id(true);
        } else {
            session_start();
        }
        $this->session->userId = UserId::from($userId);

        $this->dispatcher->dispatch(new UserLogin($userId));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, ActivityAction::Login));
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
                        $this->session->connectedWith = ConnectionType::PwgUi->value;
                    }
                    $this->logUser((int) $cookie[0], true);
                    $this->dispatcher->dispatch(new LoginSuccess(is_scalar($username) ? stripslashes((string) $username) : ''));
                    return true;
                }
            }
            setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => CookieService::cookiePath(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        }
        return false;
    }

    public function tryLogUser(string $username, string $password, bool $rememberMe): bool
    {
        $event = new TryLogUser(false, $username, $password, $rememberMe);
        $this->dispatcher->dispatch($event);
        return $event->success;
    }

    public function pwgLogin(bool $success, string $username, string $password, bool $rememberMe): bool
    {
        if ($success === true) {
            return true;
        }

        $this->sessionService->gc(0);

        $userFound = $this->findUserByUsernameOrEmail($username);
        $fakeUser  = $this->generateFakeUser();
        $hash      = ($userFound !== null ? ($userFound['password'] ?? null) : null) ?? $fakeUser['password'];
        $passwordVerify = password_verify($password, is_string($hash) ? $hash : '');
        $ufIdRaw = $userFound !== null ? ($userFound['id'] ?? null) : null;
        $ufId = is_numeric($ufIdRaw) ? (int) $ufIdRaw : 0;

        if ($userFound === null || count($userFound) === 0 || 'guest' === $userFound['status'] || !$passwordVerify) {
            if ($userFound !== null && count($userFound) > 0 && !$passwordVerify) {
                $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $ufId, ActivityAction::LoginFailureWrongPassword));
            }
            $this->dispatcher->dispatch(new LoginFailure(stripslashes($username)));
            return false;
        }

        $stateInit = ['can_login' => true, 'reason' => null, 'authenticated' => false];
        $finalizeEvent = new FinalizeLogin($stateInit, $userFound, $rememberMe);
        $this->dispatcher->dispatch($finalizeEvent);
        /** @var array<string, mixed> $state */
        $state = $finalizeEvent->state;

        if (!$state['can_login']) {
            /** @psalm-var mixed $stateReasonRaw */
            $stateReasonRaw = $state['reason'] ?? null;
            $stateReason    = is_string($stateReasonRaw) ? (ActivityAction::tryFrom($stateReasonRaw) ?? ActivityAction::LoginFailureBeforeLogUser) : ActivityAction::LoginFailureBeforeLogUser;
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $ufId, $stateReason));
            $this->dispatcher->dispatch(new LoginFailureBeforeLogUser(stripslashes($username)));
            return false;
        }

        if (!$state['authenticated']) {
            $this->logUser($ufId, $rememberMe);
        }

        $this->clearFakeUserCache();
        $this->dispatcher->dispatch(new LoginSuccess(stripslashes($username)));
        return true;
    }

    /** @return array<string,mixed>|null */
    public function findUserByUsernameOrEmail(string $usernameOrEmail): ?array
    {
        $userFields = Config::userFields();
        $user = $this->userRepo->findByUsernameOrEmail(
            $userFields->username,
            $userFields->email,
            $userFields->id,
            $userFields->password,
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
        if ($this->session->fakeUserCache === null) {
            $fakePassword = bin2hex(random_bytes(10));
            $this->session->fakeUserCache = [
                'id'       => null,
                'password' => password_hash($fakePassword, PASSWORD_BCRYPT),
            ];
        }
        return $this->session->fakeUserCache;
    }

    public function clearFakeUserCache(): void
    {
        $this->session->fakeUserCache = null;
    }

    public function logoutUser(): void
    {
        $logoutUid = $this->session->userId !== null ? $this->session->userId->value : 0;
        $this->dispatcher->dispatch(new UserLogout($logoutUid));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $logoutUid, ActivityAction::Logout));

        // Reset typed Session slots; persistInto() at request end will then
        // diff against the hydration snapshot and unset every key Session
        // had populated. session_unset() also wipes $_SESSION so any plugin
        // / legacy scratch goes with it.
        $this->session->logout();
        $_SESSION = [];
        session_unset();
        session_destroy();
        setcookie((string) session_name(), '', ['expires' => 0, 'path' => (string) ini_get('session.cookie_path'), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
        setcookie(Config::rememberMeName(), '', ['expires' => 0, 'path' => CookieService::cookiePath(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
    }

    public function authKeyLogin(string $authKey, bool $connectionByHeader = false): bool
    {
        $user = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
        $validKey  = null;
        $secretKey = null;
        if (preg_match('/^[a-z0-9]{30}$/i', $authKey)) {
            $validKey = ConnectionType::AuthKey;
        } elseif (preg_match('/^pkid-\d{8}-[a-z0-9]{20}:[a-z0-9]{40}$/i', $authKey)) {
            $validKey  = ConnectionType::ApiKey;
            $tmp       = explode(':', $authKey);
            $authKey   = $tmp[0];
            $secretKey = $tmp[1];
        }

        if ($validKey === null) {
            return false;
        }

        $key = $this->authKeyRepo->findAuthKeyDetails(
            $authKey,
            Config::userFields()->id,
            Config::userFields()->username,
            Config::userFields()->email,
            Tables::users(),
        );
        if ($key === null) {
            return false;
        }

        if (strtotime(is_string($key['expired_on'] ?? null) ? $key['expired_on'] : '') < strtotime(is_string($key['dbnow'] ?? null) ? $key['dbnow'] : '')) {
            PageState::current()->authKeyInvalid = true;
            return false;
        }

        if (ConnectionType::AuthKey === $validKey and !in_array($key['status'], ['normal', 'generic'])) {
            return false;
        }

        if (ConnectionType::ApiKey === $validKey) {
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
                PageState::current()->notifyApiKeyExpiration = [
                    'days_left' => $daysLeft,
                    'dbnow'     => $key['dbnow'],
                    'auth_key'  => $key['auth_key'],
                ];
            }
        }

        $user['id'] = $key['user_id'];
        CurrentUser::setRawAttributes($user);

        $authUserId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $keyAuth    = is_string($key['auth_key'] ?? null) ? $key['auth_key'] : '';
        $keyDbnow   = is_string($key['dbnow'] ?? null) ? $key['dbnow'] : '';
        $this->authKeyRepo->updateLastUsedOn($authUserId, $keyAuth, $keyDbnow);

        $this->session->connectedWith = $validKey->value;

        if ($connectionByHeader) {
            return true;
        }

        $this->logUser(is_numeric($user['id']) ? (int) $user['id'] : 0, false);
        $this->dispatcher->dispatch(new LoginSuccess(is_string($key['username'] ?? null) ? $key['username'] : ''));
        PageState::current()->authKeyId = is_numeric($key['auth_key_id']) ? (int) $key['auth_key_id'] : null;

        return true;
    }

    /** @return array<string,mixed>|false */
    public function createUserAuthKey(int $userId, ?string $userStatus = null): array|false
    {
        if (0 == Config::authKeyDuration()) {
            return false;
        }

        if (!isset($userStatus)) {
            $userStatus = $this->userRepo->findStatusByUserId($userId);
            if ($userStatus === null) {
                return false;
            }
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
            $key['auth_key_id'] = $this->authKeyRepo->insertKey($key);
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
        $this->userRepo->clearActivationKey($userId);
    }

    /** @return array<string,mixed> */
    public function generatePasswordLink(int $userId, bool $firstLogin = false): array
    {
        $activationKey = StringUtil::generateKey(20);
        $duration      = $firstLogin ? Config::passwordActivationDuration() : Config::passwordResetDuration();
        $expire        = new \DateTimeImmutable()->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s');

        $this->userRepo->setActivationKey($userId, password_hash($activationKey, PASSWORD_BCRYPT), $expire);

        $this->urlService->setMakeFullUrl();
        $passwordLink = $this->urlService->addUrlParams($this->urlGenerator->password(), ['key' => $activationKey]);
        $this->urlService->unsetMakeFullUrl();

        $strAuthResult = strtotime('now -' . $duration . ' second');
        $timeValidation = $this->dateService->timeSince($strAuthResult !== false ? $strAuthResult : null, 'second', null, false);

        return ['time_validation' => $timeValidation, 'password_link' => $passwordLink];
    }

    public function connectedWithPwgUi(): bool
    {
        return $this->session->connectedWith === ConnectionType::PwgUi->value;
    }
}
