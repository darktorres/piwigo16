<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\PwgTOTP;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\ExecutionMutex;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Event\User\RegisterUser;
use Piwigo\Event\User\RegisterUserCheck;
use Piwigo\Group\GroupRepository;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Lang\LangService;
use Piwigo\Language\LanguageService;
use Piwigo\Mail\MailService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Theme\ThemeService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Ws\WsError;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class UserService
{
    /** @var array<mixed>|false|null Memoised default-user row (false = "no default user", null = not loaded). */
    private array|false|null $defaultUserCache = null;

    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly HistoryRepository $histRepo,
        private readonly ActivityRepository $actRepo,
        private readonly GroupRepository $groupRepo,
        private readonly AuthKeyRepository $authKeyRepo,
        private readonly ActivityLogger $activityLogger,
        private readonly ExecutionMutex $mutex,
        private readonly LangService $langService,
        private readonly UrlGenerator $urlGenerator,
        private readonly MailService $mailService,
        private readonly MessageBusInterface $messageBus,
        private readonly HtmlService $htmlService,
        private readonly DateService $dateService,
        private readonly CategoryService $categoryService,
        private readonly \Piwigo\Image\ImageRepository $imageRepository,
        private readonly UserAdminService $userAdminService,
        private readonly SessionService $sessionService,
        private readonly AuthService $authService,
        private readonly PreferencesService $preferencesService,
        private readonly PermissionService $permissionService,
        private readonly LanguageService $languageService,
        private readonly ThemeService $themeService,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /** @param string[] $errors */
    public function registerUser(string $login, string $password, ?string $mailAddress = null, bool $notifyAdmin = true, array &$errors = [], bool $notifyUser = false): int|false
    {
        if ($login == '') {
            $errors[] = Lang::t('Please, enter a login');
        }
        if (preg_match('/^.* $/', $login)) {
            $errors[] = Lang::t('login mustn\'t end with a space character');
        }
        if (preg_match('/^ .*$/', $login)) {
            $errors[] = Lang::t('login mustn\'t start with a space character');
        }
        if ($this->getUserid($login) !== false && $this->getUserid($login) !== 0) {
            $errors[] = Lang::t('this login is already used');
        }
        if ($login != strip_tags($login)) {
            $errors[] = Lang::t('html tags are not allowed in login');
        }
        $mailError = $this->authService->validateMailAddress(null, $mailAddress);
        if (is_string($mailError) && $mailError !== '') {
            $errors[] = $mailError;
        }

        if (Config::insensitiveCaseLogon() == true) {
            $loginError = $this->authService->validateLoginCase($login);
            if (is_string($loginError) && $loginError !== '') {
                $errors[] = $loginError;
            }
        }

        $checkEvent = new RegisterUserCheck(array_values($errors), ['username' => $login, 'password' => $password, 'email' => $mailAddress]);
        $this->dispatcher->dispatch($checkEvent);
        $errors = $checkEvent->errors;

        if (empty($errors)) {
            $insert = [
                Config::userFields()['username'] => $login,
                Config::userFields()['password'] => password_hash($password, PASSWORD_BCRYPT),
                Config::userFields()['email']    => $mailAddress,
            ];

            $userId = $this->userRepo->insertNew(Tables::users(), $insert);

            $inserts = [];
            foreach ($this->userRepo->findDefaultGroupIds() as $groupId) {
                $inserts[] = ['user_id' => $userId, 'group_id' => $groupId];
            }
            $this->userRepo->insertUserGroupRows($inserts);

            $override = [];
            if (Config::browserLanguage() and ($language = $this->preferencesService->getBrowserLanguage()) !== false && $language !== '') {
                $override['language'] = $language;
            }

            $this->createUserInfos($userId, $override);

            if ($notifyAdmin and 'none' != Config::emailAdminOnNewUser()) {
                $adminUrl     = $this->urlGenerator->admin('user_list') . '&user_id=' . $userId;
                $keyargsContent = [
                    $this->langService->getL10nArgs('User: %s', stripslashes($login)),
                    $this->langService->getL10nArgs('Email: %s', $mailAddress),
                    $this->langService->getL10nArgs(''),
                    $this->langService->getL10nArgs('Admin: %s', $adminUrl),
                ];
                $groupId = null;
                if (preg_match('/^group:(\d+)$/', Config::emailAdminOnNewUser(), $matches)) {
                    $groupId = $matches[1];
                }
                $this->mailService->pwgMailNotificationAdmins($this->langService->getL10nArgs('Registration of %s', stripslashes($login)), $keyargsContent, true, (int) $groupId);
            }

            if ($notifyUser and StringUtil::emailCheckFormat($mailAddress ?? '')) {
                $length = random_int(10, 15);
                $keyargsContent = [
                    $this->langService->getL10nArgs('Hello %s,', stripslashes($login)),
                    $this->langService->getL10nArgs('Thank you for registering at %s!', Config::galleryTitle()),
                    $this->langService->getL10nArgs('', ''),
                    $this->langService->getL10nArgs('Here are your connection settings', ''),
                    $this->langService->getL10nArgs('', ''),
                    $this->langService->getL10nArgs('Link: %s', UrlService::getAbsoluteRootUrl()),
                    $this->langService->getL10nArgs('Username: %s', stripslashes($login)),
                    $this->langService->getL10nArgs('Password: %s', str_repeat('*', $length)),
                    $this->langService->getL10nArgs('Email: %s', $mailAddress),
                    $this->langService->getL10nArgs('', ''),
                    $this->langService->getL10nArgs('If you think you\'ve received this email in error, please contact us at %s', $this->mailService->getWebmasterMailAddress()),
                ];
                $this->messageBus->dispatch(
                    new SendNotificationEmailJob($userId, 'registration', [
                        'to'             => $mailAddress ?? '',
                        'subject'        => '[' . Config::galleryTitle() . '] ' . Lang::t('Registration'),
                        'content'        => $this->langService->l10nArgs($keyargsContent),
                        'content_format' => 'text/plain',
                    ])
                );
            }

            $this->dispatcher->dispatch(new RegisterUser(['id' => $userId, 'username' => $login, 'email' => $mailAddress]));
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, 'add'));

            return $userId;
        } else {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function buildUser(int $userId, bool $useCache = true): array
    {
        $user       = ['id' => $userId];
        $userDataResult = $this->getuserdata($userId, $useCache);
        $user       = array_merge($user, $userDataResult !== false ? $userDataResult : []);

        if ($user['id'] == Config::guestId() and $user['status'] <> 'guest') {
            $user['status'] = 'guest';
            if (!is_array($user['internal_status'])) {
                $user['internal_status'] = [];
            }
            $user['internal_status']['guest_must_be_guest'] = true;
        }

        $userThemeRaw = $user['theme'] ?? null;
        if (!isset($user['theme_name']) or !$this->themeService->isInstalled(is_string($userThemeRaw) ? $userThemeRaw : '')) {
            $user['theme']      = $this->getDefaultTheme();
            $user['theme_name'] = $user['theme'];
        }

        return $user;
    }

    /** @return array<string,mixed>|false */
    public function getuserdata(int $userId, bool $useCache = false): array|false
    {
        $logger = LoggerRegistry::current();

        $row = $this->userRepo->findByConfigFields(Config::userFields(), Tables::users(), $userId);

        if (Config::externalAuthentification()) {
            if ($this->userRepo->countExternalAuthInfoByUserId($userId) !== 1) {
                $this->createUserInfos($userId);
            }
        }

        $userInfosRow = $this->userRepo->findInfoCacheThemeByUserId($userId);

        $userdata = array_merge($row ?? [], $userInfosRow ?? []);

        foreach ($userdata as &$value) {
            if ($value == 'true') {
                $value = true;
            } elseif ($value == 'false') {
                $value = false;
            }
        }
        unset($value);

        $userdata['preferences'] = PreferencesService::decodePreferences($userdata['preferences'] ?? null);

        // forbidden_categories / image_access_list are stored as JSON int[]
        // on the DB (post-F5-a). Decode to native int[] for rawAttributes
        // consumers (PermissionService, CategoryService, AlbumController, etc.).
        foreach (['forbidden_categories', 'image_access_list'] as $jsonCol) {
            if (!array_key_exists($jsonCol, $userdata) || $userdata[$jsonCol] === null) {
                $userdata[$jsonCol] = [];
                continue;
            }
            $raw = $userdata[$jsonCol];
            if (!is_string($raw)) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            $userdata[$jsonCol] = is_array($decoded)
                ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $decoded))
                : [];
        }

        if ($useCache) {
            $generateUserCache = false;
            $udId              = is_numeric($userdata['id']) ? (int) $userdata['id'] : 0;
            $cacheTokenName    = 'generate_user_cache-u' . $udId;
            $execCode          = substr(sha1(random_bytes(1000)), 0, 4);
            $loggerMsgPrefix   = '[getuserdata][exec_code=' . $execCode . '][user_id=' . $udId . '] ';

            if (!isset($userdata['need_update']) or !is_bool($userdata['need_update']) or $userdata['need_update'] == true) {
                $logger->info($loggerMsgPrefix . 'needs user_cache to be rebuilt');

                $execId = $this->mutex->acquire($cacheTokenName);
                if (false === $execId) {
                    $logger->info($loggerMsgPrefix . 'starts to wait for another request to build user_cache');
                    $waitStart = StringUtil::getMoment();
                    for ($k = 0; $k < 20; $k++) {
                        sleep(1);
                        $nbCacheLines = $this->userRepo->countCacheByUserId($udId);
                        $waitingTime  = StringUtil::getElapsedTime($waitStart, StringUtil::getMoment());

                        if ($nbCacheLines > 0) {
                            $logger->info($loggerMsgPrefix . 'user_cache rebuilt, after waiting ' . $waitingTime);
                            return $this->getuserdata($userId, false);
                        } elseif (!$this->mutex->isHeld($cacheTokenName)) {
                            $logger->info($loggerMsgPrefix . 'user_cache rebuilt but has been reset since, after waiting ' . $waitingTime);
                            return $this->getuserdata($userId, true);
                        } else {
                            $logger->info($loggerMsgPrefix . 'user_cache not ready yet, after waiting ' . $waitingTime);
                        }
                    }
                    $logger->info($loggerMsgPrefix . 'user_cache generation waiting has timed out after ' . StringUtil::getElapsedTime($waitStart, StringUtil::getMoment()));
                    $this->htmlService->setStatusHeader(503, 'Service Unavailable');
                    if (!headers_sent()) {
                        header('Retry-After: 900');
                    }
                    header('Content-Type: text/html; charset=' . StringUtil::getPwgCharset());
                    echo Lang::t('Rebuilding user cache takes long. Please, come back later.');
                    echo str_repeat(' ', 512);
                    exit();
                } else {
                    $generateUserCache = true;
                }
            }

            if ($generateUserCache) {
                $genStart                    = StringUtil::getMoment();
                $userdata['cache_update_time'] = time();
                $userdata['need_update']       = false;

                $udStatusRaw     = $userdata['status'] ?? null;
                $udStatus        = is_string($udStatusRaw) ? $udStatusRaw : '';
                $udLevel         = is_numeric($userdata['level']) ? (int) $userdata['level'] : 0;
                // forbidden_categories carried as int[] both in $userdata and
                // on the DB (JSON column post-F5-a).
                $forbiddenCatIds = $this->permissionService->calculatePermissions($udId, $udStatus);
                $userdata['forbidden_categories'] = $forbiddenCatIds;

                $forbiddenImageIds = $this->imageRepository->findForbiddenImageIdsForUser($forbiddenCatIds, $udLevel);
                if ($forbiddenImageIds === []) {
                    $forbiddenImageIds = [0];
                }
                $userdata['image_access_type'] = 'NOT IN';
                $userdata['image_access_list'] = $forbiddenImageIds;

                $userdata['nb_total_images'] = $this->imageRepository->countVisibleDistinctImageIds($forbiddenCatIds, $forbiddenImageIds);

                $userCacheCats = $this->categoryService->getComputedCategories($userdata, null);
                if (!$this->permissionService->isAdmin($udStatus)) {
                    $emptyCatIds = [];
                    foreach ($userCacheCats as $cat) {
                        if ($cat['count_images'] == 0) {
                            $emptyCatIds[] = is_numeric($cat['cat_id']) ? (int) $cat['cat_id'] : 0;
                            $this->categoryService->removeComputedCategory($userCacheCats, $cat);
                        }
                    }
                    if ($emptyCatIds !== []) {
                        $forbiddenCatIds = array_values(array_unique(array_merge($forbiddenCatIds, $emptyCatIds)));
                        $userdata['forbidden_categories'] = $forbiddenCatIds;
                    }
                }

                $this->userRepo->deleteUserCacheCategoriesByUserId($udId);
                $cacheCatRows = [];
                foreach ($userCacheCats as $row) {
                    $cacheCatRows[] = $row;
                }
                $this->userRepo->insertUserCacheCategoriesBatch($udId, $cacheCatRows);

                $this->userRepo->deleteUserCacheByUserId($udId);
                $udNeedUpdate      = BoolUtil::fromMixed($userdata['need_update'] ?? null);
                $udCacheUpdateTime = is_numeric($userdata['cache_update_time']) ? (int) $userdata['cache_update_time'] : 0;
                $udNbTotalImages   = is_numeric($userdata['nb_total_images']) ? (int) $userdata['nb_total_images'] : 0;
                $lastPhotoDateRaw  = $userdata['last_photo_date'] ?? null;
                $udLastPhotoDate   = is_string($lastPhotoDateRaw) ? $lastPhotoDateRaw : '';
                $forbiddenCatsJson = json_encode($forbiddenCatIds, JSON_THROW_ON_ERROR);
                $imageAccessJson   = json_encode($forbiddenImageIds, JSON_THROW_ON_ERROR);
                $this->userRepo->insertUserCacheRow(
                    $udId,
                    $udNeedUpdate,
                    $udCacheUpdateTime,
                    $forbiddenCatsJson,
                    $udNbTotalImages,
                    $udLastPhotoDate === '' ? null : $udLastPhotoDate,
                    'NOT IN',
                    $imageAccessJson,
                );

                $this->mutex->release($cacheTokenName);
                $logger->info($loggerMsgPrefix . 'user_cache generated, executed in ' . StringUtil::getElapsedTime($genStart, StringUtil::getMoment()));
            }
        }

        return $userdata;
    }

    public function checkUserFavorites(): void
    {
        $currentUser = CurrentUser::get();
        $user        = $currentUser->rawAttributes;

        if (empty($user['forbidden_categories'])) {
            return;
        }

        [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'ic.category_id'], 'AND');
        $authorizeds = $this->userRepo->findAuthorizedFavoriteImageIds((int) $currentUser->id, $permSql, $permParams, $permTypes);
        $favorites   = $this->userRepo->findFavoriteImageIdsByUserPlain((int) $currentUser->id);

        $toDeletes = array_values(array_diff($favorites, $authorizeds));
        if (count($toDeletes) > 0) {
            $this->userRepo->deleteFavoritesByImageIds($toDeletes);
        }
    }

    public function getUserid(string $username): int|false
    {
        $userFields = Config::userFields();
        return $this->userRepo->findIdByUsername($userFields['username'], $userFields['id'], Tables::users(), $username);
    }

    public function getUseridByEmail(string $email): int|false
    {
        $userFields = Config::userFields();
        return $this->userRepo->findIdByEmail($userFields['email'], $userFields['id'], Tables::users(), $email);
    }

    /** @return array<mixed,mixed>|null */
    public function getDefaultUserInfo(bool $convertStr = true): ?array
    {
        if ($this->defaultUserCache === null) {
            $row = $this->userRepo->getDefaultUserInfo(Config::defaultUserId());
            if ($row !== null) {
                unset($row['user_id'], $row['status'], $row['registration_date'], $row['last_visit'], $row['last_visit_from_history']);
                $this->defaultUserCache = $row;
            } else {
                $this->defaultUserCache = false;
            }
        }

        if (is_array($this->defaultUserCache) && $convertStr) {
            $defaultUser = $this->defaultUserCache;
            foreach ($defaultUser as &$value) {
                if ($value == 'true') {
                    $value = true;
                } elseif ($value == 'false') {
                    $value = false;
                }
            }
            return $defaultUser;
        }
        return is_array($this->defaultUserCache) ? $this->defaultUserCache : null;
    }

    public function getDefaultUserValue(string $valueName, string $default): string
    {
        $defaultUser = $this->getDefaultUserInfo(true);
        $key         = $valueName;
        if ($defaultUser === null or empty($defaultUser[$key])) {
            return $default;
        }
        return is_string($defaultUser[$key]) ? $defaultUser[$key] : '';
    }

    public function getDefaultTheme(): string
    {
        $themeRaw = $this->getDefaultUserValue('theme', AppInfo::DEFAULT_TEMPLATE);
        $theme    = $themeRaw !== '' ? $themeRaw : AppInfo::DEFAULT_TEMPLATE;
        if ($this->themeService->isInstalled($theme)) {
            return $theme;
        }
        $activeThemes = array_keys($this->themeService->getActiveThemes());
        return $activeThemes[0] ?? '_base';
    }

    public function getDefaultLanguage(): string
    {
        $langRaw = $this->getDefaultUserValue('language', AppInfo::DEFAULT_LANGUAGE);
        return $langRaw !== '' ? $langRaw : AppInfo::DEFAULT_LANGUAGE;
    }

    /**
     * @param int[]|int                $userIds
     * @param array<string, mixed>|null $overrideValues
     */
    public function createUserInfos(array|int $userIds, ?array $overrideValues = null): void
    {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        if (!empty($userIds)) {
            $inserts     = [];
            $dbnow       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
            /** @var array<string, mixed> $defaultUser  DB rows are always string-keyed; getDefaultUserInfo's broader signature is shaped by internal foreach-by-ref. */
            $defaultUser = $this->getDefaultUserInfo(false) ?? [];

            if (!is_null($overrideValues)) {
                $defaultUser = array_merge($defaultUser, $overrideValues);
            }

            foreach ($userIds as $userId) {
                $level = array_key_exists('level', $defaultUser) ? $defaultUser['level'] : 0;
                if ($userId == Config::webmasterId()) {
                    $status = 'webmaster';
                    $level  = max(Config::availablePermissionLevels());
                } elseif (($userId == Config::guestId()) or ($userId == Config::defaultUserId())) {
                    $status = 'guest';
                } else {
                    $status = 'normal';
                }

                $insert    = array_merge(array_map(static fn (mixed $v): mixed => is_scalar($v) ? (string) $v : $v, $defaultUser), ['user_id' => $userId, 'status' => $status, 'registration_date' => $dbnow, 'level' => $level]);
                $inserts[] = $insert;
            }

            $this->userRepo->insertUserInfosBatch($inserts);
        }
    }

    public function getUserLastVisitFromHistory(int $userId, bool $saveInUserInfos = false): ?string
    {
        $uid       = $userId;
        $lastVisit = null;
        $histRow   = $this->histRepo->findLastVisitByUserId($uid);
        if ($histRow !== null) {
            $lastVisit = $histRow['date'] . ' ' . $histRow['time'];
        }
        if ($saveInUserInfos) {
            $this->userRepo->updateLastVisitFromHistory($uid, $lastVisit ?? null);
        }
        return $lastVisit;
    }

    public function hasAlreadyLoggedIn(int $userId): bool
    {
        return $this->actRepo->hasLoggedIn($userId);
    }

    /**
     * @param array<mixed>  $params
     * @return array<mixed>
     */
    public function checkAndSaveUserInfos(array $params): array
    {
        if (isset($params['username']) and strlen(str_replace(' ', '', is_string($params['username']) ? $params['username'] : '')) == 0) {
            return ['error' => ['code' => WsError::InvalidParam->value, 'message' => 'Name field must not be empty']];
        }

        $currentUser = CurrentUser::get();
        $updates = $updatesInfos = [];
        $updateStatus = null;

        $paramUserId = is_array($params['user_id']) ? $params['user_id'] : [];
        if (count($paramUserId) == 1) {
            if ($this->userAdminService->getUsername(is_numeric($paramUserId[0]) ? (int) $paramUserId[0] : 0) === false) {
                return ['error' => ['code' => WsError::InvalidParam->value, 'message' => 'This user does not exist.']];
            }

            if (isset($params['username']) && $params['username'] !== '') {
                $userId = $this->getUserid(is_string($params['username']) ? $params['username'] : '');
                if ($userId !== false && $userId !== 0 && $userId != $paramUserId[0]) {
                    return ['error' => ['code' => WsError::InvalidParam->value, 'message' => Lang::t('this login is already used')]];
                }
                $usernameStr = is_string($params['username']) ? $params['username'] : '';
                if ($usernameStr != strip_tags($usernameStr)) {
                    return ['error' => ['code' => WsError::InvalidParam->value, 'message' => Lang::t('html tags are not allowed in login')]];
                }
                $updates[Config::userFields()['username']] = $params['username'];
            }

            if (!empty($params['email'])) {
                if (($error = $this->authService->validateMailAddress(is_numeric($paramUserId[0]) ? (int) $paramUserId[0] : null, is_scalar($params['email']) ? (string) $params['email'] : null)) != '') {
                    return ['error' => ['code' => WsError::InvalidParam->value, 'message' => $error]];
                }
                $updates[Config::userFields()['email']] = $params['email'];
            }

            if (!empty($params['password'])) {
                if (!$this->permissionService->isWebmaster()) {
                    $passwordProtectedUsers = [Config::guestId()];
                    $adminIds = $this->userRepo->findAdminUserIds();
                    $passwordProtectedUsers = array_merge($passwordProtectedUsers, array_diff(array_map(static fn (int $v): string => (string) $v, $adminIds), [(string) $currentUser->id]));
                    if (in_array($paramUserId[0], $passwordProtectedUsers)) {
                        return ['error' => ['code' => 403, 'message' => 'Only webmasters can change password of other "webmaster/admin" users']];
                    }
                }
                $updates[Config::userFields()['password']] = password_hash(is_string($params['password']) ? $params['password'] : '', PASSWORD_BCRYPT);
            }
        }

        if (!empty($params['status'])) {
            if (in_array($params['status'], ['webmaster', 'admin']) and !$this->permissionService->isWebmaster()) {
                return ['error' => ['code ' => 403, 'message' => 'Only webmasters can grant "webmaster/admin" status']];
            }
            if (!in_array($params['status'], ['guest', 'generic', 'normal', 'admin', 'webmaster'])) {
                return ['error' => ['code' => WsError::InvalidParam->value, 'message' => 'Invalid status']];
            }

            $protectedUsers = [$currentUser->id, Config::guestId(), Config::webmasterId()];
            if ('admin' == $currentUser->status) {
                $protectedUsers = array_merge($protectedUsers, $this->userRepo->findAdminUserIds());
            }
            $params['user_id_for_status'] = array_values(array_diff(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId), $protectedUsers));
            $updateStatus = $params['status'];
        }

        foreach (['level', 'language', 'theme', 'nb_image_page', 'recent_period', 'expand', 'show_nb_comments', 'show_nb_hits', 'enabled_high'] as $field) {
            if (!empty($params[$field]) or ($params[$field] ?? null) === 0 or ($params[$field] ?? null) === false) {
                $v = $params[$field];
                if (in_array($field, ['expand', 'show_nb_comments', 'show_nb_hits', 'enabled_high'])) {
                    $updatesInfos[$field] = BoolUtil::toInt(is_bool($v) ? $v : (is_string($v) ? $v : ''));
                } else {
                    $updatesInfos[$field] = $v;
                }
            }
        }

        if (!empty($params['level']) or $params['level'] === 0) {
            if (!in_array($params['level'], Config::availablePermissionLevels())) {
                return ['error' => ['code' => WsError::InvalidParam->value, 'message' => 'Invalid level']];
            }
        }
        if (!empty($params['language']) and !in_array($params['language'], array_keys($this->languageService->getActiveLanguages()))) {
            return ['error' => ['code' => WsError::InvalidParam->value, 'message' => 'Invalid language']];
        }
        if (!empty($params['theme']) and !in_array($params['theme'], array_keys($this->themeService->getActiveThemes()))) {
            return ['error' => ['code' => WsError::InvalidParam->value, 'message' => 'Invalid theme']];
        }

        $paramUid0   = is_numeric($paramUserId[0]) ? (int) $paramUserId[0] : 0;
        $paramGroupId = is_array($params['group_id'] ?? null) ? $params['group_id'] : [];

        $this->userRepo->updateUserById(Tables::users(), Config::userFields()['id'], (int) $paramUid0, $updates);

        if (isset($updates[Config::userFields()['password']])) {
            $this->authService->deactivateUserAuthKeys($paramUid0);
        }
        if (isset($updates[Config::userFields()['email']])) {
            $this->authService->deactivatePasswordResetKey($paramUid0);
        }

        $paramUserIdForStatus = is_array($params['user_id_for_status'] ?? null) ? $params['user_id_for_status'] : [];
        if (isset($updateStatus) and count($paramUserIdForStatus) > 0) {
            $updateStatusStr = is_scalar($updateStatus) ? (string) $updateStatus : '';
            $this->userRepo->updateStatusForUsers($updateStatusStr, array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserIdForStatus));
            if ('guest' == $updateStatus) {
                foreach ($paramUserIdForStatus as $uidForStatus) {
                    $this->sessionService->deleteUserSessions(is_numeric($uidForStatus) ? (int) $uidForStatus : 0);
                }
            }
        }

        if (count($updatesInfos) > 0) {
            $sets   = [];
            $vals   = [];
            $types  = [];
            foreach ($updatesInfos as $field => $value) {
                $sets[]  = $field . ' = ?';
                $vals[]  = is_scalar($value) ? (string) $value : '';
                $types[] = ParameterType::STRING;
            }
            $userIds = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId));
            $sql     = 'UPDATE ' . Tables::userInfos() . ' SET ' . implode(', ', $sets) . ' WHERE user_id IN (?)';
            $vals[]  = $userIds;
            $types[] = ArrayParameterType::INTEGER;
            $this->userRepo->executeRawStatement($sql, $vals, $types);
        }

        if (count($paramGroupId) > 0) {
            $this->groupRepo->deleteUserGroupByUserIds(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId));
            $groupIdsInput = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramGroupId);
            $groupIds      = $this->userRepo->findExistingGroupIdsAmong($groupIdsInput);
            if (count($groupIds) > 0) {
                $inserts = [];
                foreach ($groupIds as $gid) {
                    foreach ($paramUserId as $uid) {
                        $inserts[] = ['user_id' => is_numeric($uid) ? (int) $uid : 0, 'group_id' => $gid];
                    }
                }
                $this->userRepo->insertUserGroupRows($inserts);
            }
        }

        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId), 'edit'));

        return ['user_id' => $params['user_id'], 'infos' => $updatesInfos, 'account' => $updates];
    }

    /** @return array<string,mixed> */
    public function createApiKey(int $userId, int $duration, string $keyName): array
    {
        $keyId     = 'pkid-' . date('Ymd') . '-' . StringUtil::generateKey(20);
        $keySecret = StringUtil::generateKey(40);
        $dbnow     = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $key = [
            'auth_key'       => $keyId,
            'apikey_secret'  => password_hash($keySecret, PASSWORD_BCRYPT),
            'apikey_name'    => $keyName,
            'user_id'        => $userId,
            'created_on'     => $dbnow,
            'key_type'       => 'api_key',
        ];

        $expiration = null;
        if (!empty($duration)) {
            $expiration   = new \DateTimeImmutable()->modify('+' . ($duration * 86400) . ' seconds')->format('Y-m-d H:i:s');
            $key['duration'] = $duration;
        }
        $key['expired_on'] = $expiration;

        $this->authKeyRepo->insertKey($key);
        $key['apikey_secret'] = $keySecret;
        return $key;
    }

    public function revokeApiKey(int $userId, string $pkid): string|bool
    {
        if (!$this->authKeyRepo->existsByKeyAndUser($pkid, $userId)) {
            return Lang::t('API Key not found');
        }
        $this->authKeyRepo->revokeKey($userId, $pkid, new \DateTimeImmutable()->format('Y-m-d H:i:s'));
        return true;
    }

    public function editApiKey(int $userId, string $pkid, string $apiName): string|true
    {
        if (!$this->authKeyRepo->existsByKeyAndUser($pkid, $userId)) {
            return Lang::t('API Key not found');
        }
        $this->authKeyRepo->updateApiKeyName($userId, $pkid, $apiName);
        return true;
    }

    /** @return list<array<mixed>>|false */
    public function getApiKey(string $userId): false|array
    {
        $apiKeys = $this->authKeyRepo->findApiKeysByUserId((int) $userId);
        if (count($apiKeys) === 0) {
            return false;
        }

        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        foreach ($apiKeys as $i => $apiKey) {
            $apiKey['apikey_secret'] = str_repeat('*', 40);
            unset($apiKey['auth_key_id'], $apiKey['user_id'], $apiKey['key_type']);
            $apiKey['apikey_name']        = stripslashes(is_string($apiKey['apikey_name']) ? $apiKey['apikey_name'] : '');
            $akCreatedOn                   = is_scalar($apiKey['created_on']) ? (string) $apiKey['created_on'] : null;
            $akExpiredOn                   = is_scalar($apiKey['expired_on']) ? (string) $apiKey['expired_on'] : null;
            $akLastUsedOn                  = is_scalar($apiKey['last_used_on']) ? (string) $apiKey['last_used_on'] : null;
            $akRevokedOn                   = is_scalar($apiKey['revoked_on']) ? (string) $apiKey['revoked_on'] : null;
            $apiKey['created_on_format']   = $this->dateService->formatDate($akCreatedOn, ['day', 'month', 'year']);
            $apiKey['expired_on_format']   = $this->dateService->formatDate($akExpiredOn, ['day', 'month', 'year']);
            $apiKey['last_used_on_since']  = ($akLastUsedOn !== null && $akLastUsedOn !== '') ? $this->dateService->timeSince($akLastUsedOn, 'day') : Lang::t('Never');
            $expiredOn                     = $this->dateService->str2DateTime($akExpiredOn);
            $nowDt                         = $this->dateService->str2DateTime($now);
            $apiKey['is_expired']          = $expiredOn < $nowDt;
            if ($apiKey['is_expired']) {
                $apiKey['expiration'] = Lang::t('Expired');
            } elseif ($nowDt !== false && $expiredOn !== false) {
                $diff = $this->dateService->dateDiff($nowDt, $expiredOn);
                if ($diff->days > 0) {
                    $apiKey['expiration'] = Lang::t('%d days', $diff->days);
                } elseif ($diff->h > 0) {
                    $apiKey['expiration'] = Lang::t('%d hours', $diff->h);
                } else {
                    $apiKey['expiration'] = Lang::t('%d minutes', $diff->i);
                }
            }
            $apiKey['expired_on_since'] = $this->dateService->timeSince($akExpiredOn, 'day');
            $apiKey['revoked_on_since'] = ($akRevokedOn !== null && $akRevokedOn !== '') ? $this->dateService->timeSince($akRevokedOn, 'day') : null;
            $apiKey['revoked_on_message'] = ($akRevokedOn !== null && $akRevokedOn !== '') ? Lang::t('This API key was manually revoked on %s', $this->dateService->formatDate($akRevokedOn, ['day', 'month', 'year'])) : null;
            $apiKeys[$i] = $apiKey;
        }

        return $apiKeys;
    }

    /** @return array<mixed>|false */
    public function getAvailableApiKey(string $userId): array|false
    {
        $apiKeys = $this->getApiKey($userId);
        if ($apiKeys === false || count($apiKeys) === 0) {
            return false;
        }
        $available = [];
        foreach ($apiKeys as $apiKey) {
            if (!$apiKey['is_expired'] && empty($apiKey['revoked_on'])) {
                $available[] = $apiKey;
            }
        }
        return count($available) > 0 ? $available : false;
    }

    public function notificationApiKeyExpiration(string $username, string $email, int $daysLeft): bool
    {
        $daysLeftStr = $daysLeft <= 1 ? Lang::t('Your API key will expire in %d day.', $daysLeft) : Lang::t('Your API key will expire in %d days.', $daysLeft);
        $message     = '<p style="margin: 20px 0">' . Lang::t('Hello %s,', $username) . '</p>';
        $message    .= '<p style="margin: 20px 0">' . $daysLeftStr . '</p>';
        $message    .= '<p style="margin: 20px 0">' . Lang::t('To continue using the API, please renew your key before it expires.') . '</p>';
        $message    .= '<p style="margin: 20px 0">' . Lang::t('You can manage your API keys in your <a href="%s">account settings.</a>', $this->urlGenerator->profile()) . '</p>';
        return $this->mailService->pwgMail($email, ['subject' => '[' . Config::galleryTitle() . '] ' . Lang::t('Your API key will expire soon'), 'content' => $message, 'content_format' => 'text/html']);
    }

    /** @return array<string,mixed> */
    public function generateUserCode(): array
    {
        $secret = PwgTOTP::generateSecret();
        $code   = PwgTOTP::generateCode($secret, min(Config::passwordResetCodeDuration(), 900));
        return ['secret' => $secret, 'code' => $code];
    }

    public function verifyUserCode(string $secret, string $code): bool
    {
        return PwgTOTP::verifyCode($code, $secret, min(Config::passwordResetCodeDuration(), 900), 1);
    }

    public function saveEditContext(): void
    {
        $ctx = SectionContextRegistry::current();
        if (!$this->permissionService->isAdmin() or $ctx->sectionUrl === '' or $ctx->imageId === null) {
            return;
        }
        $_SESSION['edit_context'] ??= [];
        $existingContext          = is_array($_SESSION['edit_context']) ? $_SESSION['edit_context'] : [];
        $imageId                  = $ctx->imageId;
        $sectionUrl               = $ctx->sectionUrl;
        $_SESSION['edit_context'] = array_slice([$imageId => $sectionUrl] + $existingContext, 0, 10, true);
    }

    public function getEditContext(int $imageId): false|string|null
    {
        $imageIdStr  = (string) $imageId;
        $editContext = is_array($_SESSION['edit_context'] ?? null) ? $_SESSION['edit_context'] : [];
        if (!isset($editContext[$imageIdStr])) {
            return false;
        }
        return preg_replace('/^\/' . preg_quote($imageIdStr, '/') . '\//', '', is_scalar($editContext[$imageIdStr]) ? (string) $editContext[$imageIdStr] : '');
    }
}
