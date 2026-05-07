<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\PwgTOTP;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class UserService
{
    public function __construct(
        private UserRepository $userRepo,
        private Connection $conn,
        private HistoryRepository $histRepo,
        private ActivityRepository $actRepo,
        private GroupRepository $groupRepo,
        private AuthKeyRepository $authKeyRepo,
    ) {
    }

    public static function get(): self
    {
        return ServiceLocator::get(self::class);
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
        if ($this->getUserid($login)) {
            $errors[] = Lang::t('this login is already used');
        }
        if ($login != strip_tags($login)) {
            $errors[] = Lang::t('html tags are not allowed in login');
        }
        $mailError = AuthService::get()->validateMailAddress(null, $mailAddress);
        if ('' != $mailError) {
            $errors[] = $mailError;
        }

        if (Config::insensitiveCaseLogon() == true) {
            $loginError = AuthService::get()->validateLoginCase($login);
            if ($loginError != '') {
                $errors[] = $loginError;
            }
        }

        $errors = EventDispatcher::dispatch('register_user_check', $errors, ['username' => $login, 'password' => $password, 'email' => $mailAddress]);

        if (empty($errors)) {
            $insert = [
                Config::userFields()['username'] => $login,
                Config::userFields()['password'] => password_hash($password, PASSWORD_BCRYPT),
                Config::userFields()['email']    => $mailAddress,
            ];

            Dml::singleInsert(Tables::users(), $insert);
            $userId = (int) DbConnection::get()->lastInsertId();

            $inserts = [];
            foreach ($this->userRepo->findDefaultGroupIds() as $groupId) {
                $inserts[] = ['user_id' => $userId, 'group_id' => $groupId];
            }
            if (count($inserts) != 0) {
                Dml::massInserts(Tables::userGroup(), ['user_id', 'group_id'], $inserts);
            }

            $override = [];
            if (Config::browserLanguage() and $language = PreferencesService::get()->getBrowserLanguage()) {
                $override['language'] = $language;
            }

            $this->createUserInfos((int) $userId, $override);

            if ($notifyAdmin and 'none' != Config::emailAdminOnNewUser()) {
                $adminUrl     = ServiceLocator::get(UrlGenerator::class)->admin('user_list') . '&user_id=' . $userId;
                $keyargsContent = [
                    LangService::get()->getL10nArgs('User: %s', stripslashes($login)),
                    LangService::get()->getL10nArgs('Email: %s', $mailAddress),
                    LangService::get()->getL10nArgs(''),
                    LangService::get()->getL10nArgs('Admin: %s', $adminUrl),
                ];
                $groupId = null;
                if (preg_match('/^group:(\d+)$/', Config::emailAdminOnNewUser(), $matches)) {
                    $groupId = $matches[1];
                }
                ServiceLocator::get(MailService::class)->pwgMailNotificationAdmins(LangService::get()->getL10nArgs('Registration of %s', stripslashes($login)), $keyargsContent, true, (int) $groupId);
            }

            if ($notifyUser and ServiceLocator::get(StringUtil::class)->emailCheckFormat($mailAddress ?? '')) {
                $length = random_int(10, 15);
                $keyargsContent = [
                    LangService::get()->getL10nArgs('Hello %s,', stripslashes($login)),
                    LangService::get()->getL10nArgs('Thank you for registering at %s!', Config::galleryTitle()),
                    LangService::get()->getL10nArgs('', ''),
                    LangService::get()->getL10nArgs('Here are your connection settings', ''),
                    LangService::get()->getL10nArgs('', ''),
                    LangService::get()->getL10nArgs('Link: %s', UrlService::getAbsoluteRootUrl()),
                    LangService::get()->getL10nArgs('Username: %s', stripslashes($login)),
                    LangService::get()->getL10nArgs('Password: %s', str_repeat('*', $length)),
                    LangService::get()->getL10nArgs('Email: %s', $mailAddress),
                    LangService::get()->getL10nArgs('', ''),
                    LangService::get()->getL10nArgs('If you think you\'ve received this email in error, please contact us at %s', ServiceLocator::get(Util::class)->getWebmasterMailAddress()),
                ];
                ServiceLocator::get(MessageBusInterface::class)->dispatch(
                    new SendNotificationEmailJob((int) $userId, 'registration', [
                        'to'             => $mailAddress ?? '',
                        'subject'        => '[' . Config::galleryTitle() . '] ' . Lang::t('Registration'),
                        'content'        => LangService::get()->l10nArgs($keyargsContent),
                        'content_format' => 'text/plain',
                    ])
                );
            }

            EventDispatcher::notify('register_user', ['id' => $userId, 'username' => $login, 'email' => $mailAddress]);
            ServiceLocator::get(Util::class)->pwgActivity('user', $userId, 'add');

            return (int) $userId;
        } else {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function buildUser(int $userId, bool $useCache = true): array
    {
        $user       = ['id' => $userId];
        $user       = array_merge($user, $this->getuserdata($userId, $useCache) ?: []);

        if ($user['id'] == Config::guestId() and $user['status'] <> 'guest') {
            $user['status'] = 'guest';
            if (!is_array($user['internal_status'])) {
                $user['internal_status'] = [];
            }
            $user['internal_status']['guest_must_be_guest'] = true;
        }

        if (!isset($user['theme_name']) or !ServiceLocator::get(Util::class)->checkThemeInstalled(is_scalar($user['theme']) ? (string) $user['theme'] : '')) {
            $user['theme']      = $this->getDefaultTheme();
            $user['theme_name'] = $user['theme'];
        }

        return $user;
    }

    /** @return array<string,mixed>|false */
    public function getuserdata(int $userId, bool $useCache = false): array|false
    {
        $logger = LoggerRegistry::current();

        $query    = 'SELECT ';
        $isFirst  = true;
        foreach (Config::userFields() as $pwgfield => $dbfield) {
            if ($isFirst) {
                $isFirst = false;
            } else {
                $query .= '
     , ';
            }
            $query .= $dbfield . ' AS ' . $pwgfield;
        }
        $query .= '
  FROM ' . Tables::users() . '
  WHERE ' . Config::userFields()['id'] . ' = \'' . $userId . '\'';

        $row = $this->conn->executeQuery($query)->fetchAssociative() ?: null;

        if (Config::externalAuthentification()) {
            $counter = $this->conn->executeQuery(
                'SELECT COUNT(1) AS counter FROM ' . Tables::userInfos() . ' AS ui
                 LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
                 LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
                 WHERE ui.user_id = ? GROUP BY ui.user_id',
                [$userId]
            )->fetchOne();
            if ((is_numeric($counter) ? (int) $counter : 0) !== 1) {
                $this->createUserInfos($userId);
            }
        }

        $userInfosRow = $this->conn->executeQuery(
            'SELECT ui.*, uc.*, t.name AS theme_name
             FROM ' . Tables::userInfos() . ' AS ui
             LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
             LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
             WHERE ui.user_id = ?',
            [$userId]
        )->fetchAssociative() ?: null;

        $userdata = array_merge($row ?? [], $userInfosRow ?? []);

        foreach ($userdata as &$value) {
            if ($value == 'true') {
                $value = true;
            } elseif ($value == 'false') {
                $value = false;
            }
        }
        unset($value);

        $userdata['preferences'] = [];

        if ($useCache) {
            $generateUserCache = false;
            $udId              = is_numeric($userdata['id']) ? (int) $userdata['id'] : 0;
            $cacheTokenName    = 'generate_user_cache-u' . $udId;
            $execCode          = substr(sha1(random_bytes(1000)), 0, 4);
            $loggerMsgPrefix   = '[getuserdata][exec_code=' . $execCode . '][user_id=' . $udId . '] ';

            if (!isset($userdata['need_update']) or !is_bool($userdata['need_update']) or $userdata['need_update'] == true) {
                $logger->info($loggerMsgPrefix . 'needs user_cache to be rebuilt');

                $execId = ServiceLocator::get(Util::class)->pwgUniqueExecBegins($cacheTokenName);
                if (false === $execId) {
                    $logger->info($loggerMsgPrefix . 'starts to wait for another request to build user_cache');
                    $waitStart = ServiceLocator::get(StringUtil::class)->getMoment();
                    for ($k = 0; $k < 20; $k++) {
                        sleep(1);
                        $nbCacheLines = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . Tables::userCache() . ' WHERE user_id=' . $udId . ';')->fetchOne();
                        $waitingTime  = StringUtil::getElapsedTime($waitStart, ServiceLocator::get(StringUtil::class)->getMoment());

                        if ($nbCacheLines > 0) {
                            $logger->info($loggerMsgPrefix . 'user_cache rebuilt, after waiting ' . $waitingTime);
                            return $this->getuserdata($userId, false);
                        } elseif (!ServiceLocator::get(Util::class)->pwgUniqueExecIsRunning($cacheTokenName)) {
                            $logger->info($loggerMsgPrefix . 'user_cache rebuilt but has been reset since, after waiting ' . $waitingTime);
                            return $this->getuserdata($userId, true);
                        } else {
                            $logger->info($loggerMsgPrefix . 'user_cache not ready yet, after waiting ' . $waitingTime);
                        }
                    }
                    $logger->info($loggerMsgPrefix . 'user_cache generation waiting has timed out after ' . StringUtil::getElapsedTime($waitStart, ServiceLocator::get(StringUtil::class)->getMoment()));
                    ServiceLocator::get(HtmlService::class)->setStatusHeader(503, 'Service Unavailable');
                    if (!headers_sent()) {
                        header('Retry-After: 900');
                    }
                    header('Content-Type: text/html; charset=' . ServiceLocator::get(StringUtil::class)->getPwgCharset());
                    echo Lang::t('Rebuilding user cache takes long. Please, come back later.');
                    echo str_repeat(' ', 512);
                    exit();
                } else {
                    $generateUserCache = true;
                }
            }

            if ($generateUserCache) {
                $genStart                    = ServiceLocator::get(StringUtil::class)->getMoment();
                $userdata['cache_update_time'] = time();
                $userdata['need_update']       = false;

                $udStatus            = is_scalar($userdata['status']) ? (string) $userdata['status'] : '';
                $udLevel             = is_numeric($userdata['level']) ? (int) $userdata['level'] : 0;
                $udForbiddenCats     = PermissionService::get()->calculatePermissions($udId, $udStatus);
                $userdata['forbidden_categories'] = $udForbiddenCats;

                $query = 'SELECT DISTINCT(id) FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id WHERE category_id NOT IN (' . $udForbiddenCats . ') AND level>' . $udLevel;
                $forbiddenIds = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');
                if (empty($forbiddenIds)) {
                    $forbiddenIds[] = 0;
                }
                $userdata['image_access_type'] = 'NOT IN';
                $userdata['image_access_list'] = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $forbiddenIds));

                $query = 'SELECT COUNT(DISTINCT(image_id)) as total FROM ' . Tables::imageCategory() . ' WHERE category_id NOT IN (' . $udForbiddenCats . ') AND image_id ' . $userdata['image_access_type'] . ' (' . $userdata['image_access_list'] . ')';
                $userdata['nb_total_images'] = $this->conn->executeQuery($query)->fetchOne();

                $userCacheCats = ServiceLocator::get(CategoryService::class)->getComputedCategories($userdata, null);
                if (!PermissionService::get()->isAdmin($udStatus)) {
                    $forbiddenIds = [];
                    foreach ($userCacheCats as $cat) {
                        if ($cat['count_images'] == 0) {
                            $forbiddenIds[] = is_numeric($cat['cat_id']) ? (int) $cat['cat_id'] : 0;
                            ServiceLocator::get(CategoryService::class)->removeComputedCategory($userCacheCats, $cat);
                        }
                    }
                    if (!empty($forbiddenIds)) {
                        $udForbiddenCatsStr = is_string($userdata['forbidden_categories']) ? $userdata['forbidden_categories'] : '';
                        $forbiddenIdsStr    = implode(',', array_map(static fn (int $v): string => (string) $v, $forbiddenIds));
                        $userdata['forbidden_categories'] = empty($udForbiddenCatsStr) ? $forbiddenIdsStr : $udForbiddenCatsStr . ',' . $forbiddenIdsStr;
                    }
                }

                $this->conn->executeStatement('DELETE FROM ' . Tables::userCacheCategories() . ' WHERE user_id = ?', [$udId]);
                Dml::massInserts(Tables::userCacheCategories(), ['user_id', 'cat_id', 'date_last', 'max_date_last', 'nb_images', 'count_images', 'nb_categories', 'count_categories'], $userCacheCats, ['ignore' => true]);

                $this->conn->executeStatement('DELETE FROM ' . Tables::userCache() . ' WHERE user_id = ?', [$udId]);
                $udNeedUpdate        = ($userdata['need_update'] ?? '') === 'true';
                $udCacheUpdateTime   = is_numeric($userdata['cache_update_time']) ? (int) $userdata['cache_update_time'] : 0;
                $udForbiddenCatsStr2 = is_scalar($userdata['forbidden_categories']) ? (string) $userdata['forbidden_categories'] : '';
                $udNbTotalImages     = is_numeric($userdata['nb_total_images']) ? (int) $userdata['nb_total_images'] : 0;
                $udLastPhotoDate     = is_scalar($userdata['last_photo_date']) ? (string) $userdata['last_photo_date'] : '';
                $udImageAccessType   = is_scalar($userdata['image_access_type']) ? (string) $userdata['image_access_type'] : '';
                $udImageAccessList   = is_scalar($userdata['image_access_list']) ? (string) $userdata['image_access_list'] : '';
                $lastPhotoPlaceholder = empty($udLastPhotoDate) ? 'NULL' : '?';
                $cacheParams = [$udId, $udNeedUpdate ? 'true' : 'false', $udCacheUpdateTime, $udForbiddenCatsStr2, $udNbTotalImages, $udImageAccessType, $udImageAccessList];
                if (!empty($udLastPhotoDate)) {
                    array_splice($cacheParams, 5, 0, [$udLastPhotoDate]);
                }
                $this->conn->executeStatement(
                    'INSERT IGNORE INTO ' . Tables::userCache() .
                    ' (user_id, need_update, cache_update_time, forbidden_categories, nb_total_images,' .
                    '  last_photo_date, image_access_type, image_access_list)' .
                    ' VALUES (?, ?, ?, ?, ?, ' . $lastPhotoPlaceholder . ', ?, ?)',
                    $cacheParams
                );

                ServiceLocator::get(Util::class)->pwgUniqueExecEnds($cacheTokenName);
                $logger->info($loggerMsgPrefix . 'user_cache generated, executed in ' . StringUtil::getElapsedTime($genStart, ServiceLocator::get(StringUtil::class)->getMoment()));
            }
        }

        return $userdata;
    }

    public function checkUserFavorites(): void
    {
        $currentUser = CurrentUser::get();
        $user        = $currentUser->rawAttributes;

        if (($user['forbidden_categories'] ?? '') == '') {
            return;
        }

        $query = '
SELECT DISTINCT f.image_id
  FROM ' . Tables::favorites() . ' AS f INNER JOIN ' . Tables::imageCategory() . ' AS ic
    ON f.image_id = ic.image_id
  WHERE f.user_id = ' . $currentUser->id . '
  ' . PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'ic.category_id'], 'AND') . '
;';
        $authorizeds = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'image_id');
        $favorites   = array_column(DbConnection::get()->executeQuery('SELECT image_id FROM ' . Tables::favorites() . ' WHERE user_id = ' . $currentUser->id . ';')->fetchAllAssociative(), 'image_id');

        $toDeletes = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $favorites), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $authorizeds));
        if (count($toDeletes) > 0) {
            $this->userRepo->deleteFavoritesByImageIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_values($toDeletes)));
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
        $cache = &$GLOBALS['cache'];
        if (!is_array($cache)) {
            $cache = [];
        }

        if (!isset($cache['default_user'])) {
            $row = $this->userRepo->getDefaultUserInfo(Config::defaultUserId());
            if ($row !== null) {
                unset($row['user_id'], $row['status'], $row['registration_date'], $row['last_visit'], $row['last_visit_from_history']);
                $cache['default_user'] = $row;
            } else {
                $cache['default_user'] = false;
            }
        }

        if (is_array($cache['default_user']) and $convertStr) {
            $defaultUser = $cache['default_user'];
            foreach ($defaultUser as &$value) {
                if ($value == 'true') {
                    $value = true;
                } elseif ($value == 'false') {
                    $value = false;
                }
            }
            return $defaultUser;
        } else {
            return is_array($cache['default_user']) ? $cache['default_user'] : null;
        }
    }

    public function getDefaultUserValue(mixed $valueName, mixed $default): mixed
    {
        $defaultUser = $this->getDefaultUserInfo(true);
        $key         = is_scalar($valueName) ? (string) $valueName : '';
        if ($defaultUser === null or empty($defaultUser[$key])) {
            return $default;
        }
        return $defaultUser[$key];
    }

    public function getDefaultTheme(): string
    {
        $themeRaw = $this->getDefaultUserValue('theme', AppInfo::DEFAULT_TEMPLATE);
        $theme    = is_scalar($themeRaw) ? (string) $themeRaw : AppInfo::DEFAULT_TEMPLATE;
        if (ServiceLocator::get(Util::class)->checkThemeInstalled($theme)) {
            return $theme;
        }
        $activeThemes = array_keys(ServiceLocator::get(Util::class)->getPwgThemes());
        return $activeThemes[0] ?? '_base';
    }

    public function getDefaultLanguage(): string
    {
        $langRaw = $this->getDefaultUserValue('language', AppInfo::DEFAULT_LANGUAGE);
        return is_scalar($langRaw) ? (string) $langRaw : AppInfo::DEFAULT_LANGUAGE;
    }

    /**
     * @param int[]|int         $userIds
     * @param array<mixed>|null $overrideValues
     */
    public function createUserInfos(array|int $userIds, ?array $overrideValues = null): void
    {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        if (!empty($userIds)) {
            $inserts     = [];
            $dbnow       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
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

                $insert    = array_merge(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $defaultUser), ['user_id' => $userId, 'status' => $status, 'registration_date' => $dbnow, 'level' => $level]);
                $inserts[] = $insert;
            }

            Dml::massInserts(Tables::userInfos(), array_keys($inserts[0]), $inserts);
        }
    }

    public function getUserLastVisitFromHistory(mixed $userId, bool $saveInUserInfos = false): ?string
    {
        $uid       = is_numeric($userId) ? (int) $userId : 0;
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

    public function hasAlreadyLoggedIn(mixed $userId): bool
    {
        return $this->actRepo->hasLoggedIn(is_numeric($userId) ? (int) $userId : 0);
    }

    /**
     * @param array<mixed>  $params
     * @return array<mixed>
     */
    public function checkAndSaveUserInfos(array $params): array
    {
        if (isset($params['username']) and strlen(str_replace(' ', '', is_scalar($params['username']) ? (string) $params['username'] : '')) == 0) {
            return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => 'Name field must not be empty']];
        }

        $currentUser = CurrentUser::get();
        $updates = $updatesInfos = [];
        $updateStatus = null;

        $paramUserId = is_array($params['user_id']) ? $params['user_id'] : [];
        if (count($paramUserId) == 1) {
            if (ServiceLocator::get(UserAdminService::class)->getUsername(is_numeric($paramUserId[0]) ? (int) $paramUserId[0] : 0) === false) {
                return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => 'This user does not exist.']];
            }

            if (!empty($params['username'])) {
                $userId = $this->getUserid(is_scalar($params['username']) ? (string) $params['username'] : '');
                if ($userId and $userId != $paramUserId[0]) {
                    return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => Lang::t('this login is already used')]];
                }
                $usernameStr = is_scalar($params['username']) ? (string) $params['username'] : '';
                if ($usernameStr != strip_tags($usernameStr)) {
                    return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => Lang::t('html tags are not allowed in login')]];
                }
                $updates[Config::userFields()['username']] = $params['username'];
            }

            if (!empty($params['email'])) {
                if (($error = AuthService::get()->validateMailAddress(is_numeric($paramUserId[0]) ? (int) $paramUserId[0] : null, is_scalar($params['email']) ? (string) $params['email'] : null)) != '') {
                    return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => $error]];
                }
                $updates[Config::userFields()['email']] = $params['email'];
            }

            if (!empty($params['password'])) {
                if (!PermissionService::get()->isWebmaster()) {
                    $passwordProtectedUsers = [Config::guestId()];
                    $adminIds = array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userInfos() . ' WHERE status IN (\'webmaster\', \'admin\');')->fetchAllAssociative(), 'user_id');
                    $passwordProtectedUsers = array_merge($passwordProtectedUsers, array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $adminIds), [(string) $currentUser->id]));
                    if (in_array($paramUserId[0], $passwordProtectedUsers)) {
                        return ['error' => ['code' => 403, 'message' => 'Only webmasters can change password of other "webmaster/admin" users']];
                    }
                }
                $updates[Config::userFields()['password']] = password_hash(is_scalar($params['password']) ? (string) $params['password'] : '', PASSWORD_BCRYPT);
            }
        }

        if (!empty($params['status'])) {
            if (in_array($params['status'], ['webmaster', 'admin']) and !PermissionService::get()->isWebmaster()) {
                return ['error' => ['code ' => 403, 'message' => 'Only webmasters can grant "webmaster/admin" status']];
            }
            if (!in_array($params['status'], ['guest', 'generic', 'normal', 'admin', 'webmaster'])) {
                return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => 'Invalid status']];
            }

            $protectedUsers = [$currentUser->id, Config::guestId(), Config::webmasterId()];
            if ('admin' == $currentUser->status) {
                $protectedUsers = array_merge($protectedUsers, array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userInfos() . ' WHERE status IN (\'webmaster\', \'admin\');')->fetchAllAssociative(), 'user_id')));
            }
            $params['user_id_for_status'] = array_values(array_diff(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId), $protectedUsers));
            $updateStatus = $params['status'];
        }

        foreach (['level', 'language', 'theme', 'nb_image_page', 'recent_period', 'expand', 'show_nb_comments', 'show_nb_hits', 'enabled_high'] as $field) {
            if (!empty($params[$field]) or ($params[$field] ?? null) === 0 or ($params[$field] ?? null) === false) {
                $v = $params[$field];
                if (in_array($field, ['expand', 'show_nb_comments', 'show_nb_hits', 'enabled_high'])) {
                    $updatesInfos[$field] = BoolUtil::toString(is_bool($v) ? $v : (is_string($v) ? $v : ''));
                } else {
                    $updatesInfos[$field] = $v;
                }
            }
        }

        if (!empty($params['level']) or $params['level'] === 0) {
            if (!in_array($params['level'], Config::availablePermissionLevels())) {
                return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => 'Invalid level']];
            }
        }
        if (!empty($params['language']) and !in_array($params['language'], array_keys(Util::get()->getLanguages()))) {
            return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => 'Invalid language']];
        }
        if (!empty($params['theme']) and !in_array($params['theme'], array_keys(ServiceLocator::get(Util::class)->getPwgThemes()))) {
            return ['error' => ['code' => WS_ERR_INVALID_PARAM, 'message' => 'Invalid theme']];
        }

        $paramUid0   = is_numeric($paramUserId[0]) ? (int) $paramUserId[0] : 0;
        $paramGroupId = is_array($params['group_id'] ?? null) ? $params['group_id'] : [];

        Dml::singleUpdate(Tables::users(), $updates, [Config::userFields()['id'] => $paramUid0]);

        if (isset($updates[Config::userFields()['password']])) {
            AuthService::get()->deactivateUserAuthKeys($paramUid0);
        }
        if (isset($updates[Config::userFields()['email']])) {
            AuthService::get()->deactivatePasswordResetKey($paramUid0);
        }

        $paramUserIdForStatus = is_array($params['user_id_for_status'] ?? null) ? $params['user_id_for_status'] : [];
        if (isset($updateStatus) and count($paramUserIdForStatus) > 0) {
            $updateStatusStr = is_scalar($updateStatus) ? (string) $updateStatus : '';
            $this->userRepo->updateStatusForUsers($updateStatusStr, array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserIdForStatus));
            if ('guest' == $updateStatus) {
                foreach ($paramUserIdForStatus as $uidForStatus) {
                    ServiceLocator::get(SessionService::class)->deleteUserSessions(is_numeric($uidForStatus) ? (int) $uidForStatus : 0);
                }
            }
        }

        if (count($updatesInfos) > 0) {
            $query  = 'UPDATE ' . Tables::userInfos() . ' SET ';
            $first  = true;
            foreach ($updatesInfos as $field => $value) {
                if (!$first) {
                    $query .= ', ';
                } else {
                    $first = false;
                }
                $query .= $field . ' = "' . (is_scalar($value) ? (string) $value : '') . '"';
            }
            $query .= ' WHERE user_id IN(' . implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $paramUserId)) . ')';
            $this->conn->executeStatement($query);
        }

        if (!empty($paramGroupId)) {
            $this->groupRepo->deleteUserGroupByUserIds(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId));
            $groupIds = array_column($this->conn->executeQuery('SELECT id FROM `' . Tables::groups() . '` WHERE id IN (' . implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $paramGroupId)) . ');')->fetchAllAssociative(), 'id');
            if (count($groupIds) > 0) {
                $inserts = [];
                foreach ($groupIds as $gid) {
                    foreach ($paramUserId as $uid) {
                        $inserts[] = ['user_id' => $uid, 'group_id' => $gid];
                    }
                }
                Dml::massInserts(Tables::userGroup(), array_keys($inserts[0]), $inserts);
            }
        }

        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        ServiceLocator::get(Util::class)->pwgActivity('user', array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $paramUserId), 'edit');

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

        Dml::singleInsert(Tables::userAuthKeys(), $key);
        $key['apikey_secret'] = $keySecret;
        return $key;
    }

    public function revokeApiKey(mixed $userId, string $pkid): string|bool
    {
        $uid = is_numeric($userId) ? (int) $userId : 0;
        if (!$this->authKeyRepo->existsByKeyAndUser($pkid, $uid)) {
            return Lang::t('API Key not found');
        }
        Dml::singleUpdate(Tables::userAuthKeys(), ['revoked_on' => new \DateTimeImmutable()->format('Y-m-d H:i:s')], ['auth_key' => $pkid, 'user_id' => $uid]);
        return true;
    }

    public function editApiKey(int $userId, string $pkid, string $apiName): string|true
    {
        if (!$this->authKeyRepo->existsByKeyAndUser($pkid, $userId)) {
            return Lang::t('API Key not found');
        }
        Dml::singleUpdate(Tables::userAuthKeys(), ['apikey_name' => $apiName], ['auth_key' => $pkid, 'user_id' => $userId]);
        return true;
    }

    /** @return list<array<mixed>>|false */
    public function getApiKey(string $userId): false|array
    {
        $apiKeys = DbConnection::get()->executeQuery('SELECT * FROM `' . Tables::userAuthKeys() . '` WHERE user_id = ' . $userId . ' AND key_type = "api_key";')->fetchAllAssociative();
        if (!$apiKeys) {
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
            $apiKey['created_on_format']   = ServiceLocator::get(DateService::class)->formatDate($akCreatedOn, ['day', 'month', 'year']);
            $apiKey['expired_on_format']   = ServiceLocator::get(DateService::class)->formatDate($akExpiredOn, ['day', 'month', 'year']);
            $apiKey['last_used_on_since']  = $akLastUsedOn ? ServiceLocator::get(DateService::class)->timeSince($akLastUsedOn, 'day') : Lang::t('Never');
            $expiredOn                     = ServiceLocator::get(DateService::class)->str2DateTime($akExpiredOn);
            $nowDt                         = ServiceLocator::get(DateService::class)->str2DateTime($now);
            $apiKey['is_expired']          = $expiredOn < $nowDt;
            if ($apiKey['is_expired']) {
                $apiKey['expiration'] = Lang::t('Expired');
            } elseif ($nowDt !== false && $expiredOn !== false) {
                $diff = ServiceLocator::get(DateService::class)->dateDiff($nowDt, $expiredOn);
                if ($diff->days > 0) {
                    $apiKey['expiration'] = Lang::t('%d days', $diff->days);
                } elseif ($diff->h > 0) {
                    $apiKey['expiration'] = Lang::t('%d hours', $diff->h);
                } else {
                    $apiKey['expiration'] = Lang::t('%d minutes', $diff->i);
                }
            }
            $apiKey['expired_on_since'] = ServiceLocator::get(DateService::class)->timeSince($akExpiredOn, 'day');
            $apiKey['revoked_on_since'] = $akRevokedOn ? ServiceLocator::get(DateService::class)->timeSince($akRevokedOn, 'day') : null;
            $apiKey['revoked_on_message'] = $akRevokedOn ? Lang::t('This API key was manually revoked on %s', ServiceLocator::get(DateService::class)->formatDate($akRevokedOn, ['day', 'month', 'year'])) : null;
            $apiKeys[$i] = $apiKey;
        }

        return $apiKeys;
    }

    /** @return array<mixed>|false */
    public function getAvailableApiKey(string $userId): array|false
    {
        $apiKeys = $this->getApiKey($userId);
        if (!$apiKeys) {
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
        $message    .= '<p style="margin: 20px 0">' . Lang::t('You can manage your API keys in your <a href="%s">account settings.</a>', ServiceLocator::get(UrlGenerator::class)->profile()) . '</p>';
        return ServiceLocator::get(MailService::class)->pwgMail($email, ['subject' => '[' . Config::galleryTitle() . '] ' . Lang::t('Your API key will expire soon'), 'content' => $message, 'content_format' => 'text/html']);
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
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        if (!PermissionService::get()->isAdmin() or !isset($page['section_url']) or !isset($page['image_id'])) {
            return;
        }
        $_SESSION['edit_context'] ??= [];
        $existingContext          = is_array($_SESSION['edit_context']) ? $_SESSION['edit_context'] : [];
        $imageId                  = is_scalar($page['image_id']) ? (string) $page['image_id'] : '';
        $sectionUrl               = is_scalar($page['section_url']) ? (string) $page['section_url'] : '';
        $_SESSION['edit_context'] = array_slice([$imageId => $sectionUrl] + $existingContext, 0, 10, true);
    }

    public function getEditContext(mixed $imageId): false|string|null
    {
        $imageIdStr  = is_scalar($imageId) ? (string) $imageId : '';
        $editContext = is_array($_SESSION['edit_context'] ?? null) ? $_SESSION['edit_context'] : [];
        if (!isset($editContext[$imageIdStr])) {
            return false;
        }
        return preg_replace('/^\/' . preg_quote($imageIdStr, '/') . '\//', '', is_scalar($editContext[$imageIdStr]) ? (string) $editContext[$imageIdStr] : '');
    }
}
