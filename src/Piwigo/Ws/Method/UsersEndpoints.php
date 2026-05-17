<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Event\User\WsUsersGetList;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\OpenApi\ApiMethod;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class UsersEndpoints
{
    public function __construct(
        private Connection $conn,
        private AuthService $authService,
        private ConfigService $configService,
        private DateService $dateService,
        private GroupRepository $groupRepository,
        private ImageRepository $imageRepository,
        private MailService $mailService,
        private PermissionService $permissionService,
        private PreferencesService $preferencesService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
        private UserService $userService,
        private CsrfService $csrfService,
        private WsHelper $wsHelper,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Retrieves a list of all the users. display controls which data are returned: all, basics, none.', tags: ['users'])]
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $orderStr = is_string($params['order'] ?? null) ? $params['order'] : '';
        if (!preg_match(ValidationPattern::ORDER, $orderStr)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter order');
        }
        if (isset($params['order']) && str_contains($orderStr, 'username')) {
            $orderStr = str_ireplace('username', 'LOWER(username)', $orderStr);
        }
        $whereClauses = ['1=1'];
        if (!empty($params['user_id'])) {
            $userIdArr      = is_array($params['user_id']) ? $params['user_id'] : [];
            $whereClauses[] = 'u.' . Config::userFields()['id'] . ' IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIdArr)) . ')';
        }
        if (!empty($params['username'])) {
            $whereClauses[] = 'u.' . Config::userFields()['username'] . ' LIKE ' . $this->conn->quote(is_string($params['username']) ? $params['username'] : '');
        }
        $filteredGroups = [];
        if (!empty($params['filter'])) {
            $filterStr      = is_string($params['filter']) ? $params['filter'] : '';
            $filteredGroups = $this->groupRepository->findIdsByNameLike($filterStr);
            $filterQuoted   = $this->conn->quote('%' . $filterStr . '%');
            $filterWhere    = '(u.' . Config::userFields()['username'] . ' LIKE ' . $filterQuoted . ' OR u.' . Config::userFields()['email'] . ' LIKE ' . $filterQuoted;
            if (!empty($filteredGroups)) {
                $filterWhere .= ' OR ug.group_id IN (' . implode(',', array_map(fn (int $v): string => (string) $v, $filteredGroups)) . ')';
            }
            $whereClauses[] = $filterWhere . ')';
        }
        if (!empty($params['min_register'])) {
            $minRegisterStr = is_string($params['min_register']) ? $params['min_register'] : '';
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $minRegisterStr)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter min_register');
            }
            $dateTokens     = explode('-', $minRegisterStr);
            $minDate        = sprintf('%u-%02u-%02u', $dateTokens[0], $dateTokens[1] ?? 1, $dateTokens[2] ?? 1);
            $whereClauses[] = "ui.registration_date >= '$minDate 00:00:00'";
        }
        if (!empty($params['max_register'])) {
            $maxRegisterStr = is_string($params['max_register']) ? $params['max_register'] : '';
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $maxRegisterStr)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter max_register');
            }
            $maxDateTokens = explode('-', $maxRegisterStr);
            $strResult = strtotime($maxDateTokens[0] . '-' . ($maxDateTokens[1] ?? '12') . '-1');
            $maxDay        = $maxDateTokens[2] ?? date('t', $strResult !== false ? $strResult : null);
            $maxDate       = sprintf('%u-%02u-%02u', $maxDateTokens[0], $maxDateTokens[1] ?? '12', $maxDay);
            $whereClauses[] = "ui.registration_date <= '$maxDate 23:59:59'";
        }
        if (!empty($params['status'])) {
            $statusArr  = is_array($params['status']) ? $params['status'] : [];
            $statusArr  = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $statusArr);
            $statusArr  = array_intersect($statusArr, SchemaHelper::getEnums(Tables::userInfos(), 'status'));
            if (count($statusArr) > 0) {
                $whereClauses[] = 'ui.status IN("' . implode('","', $statusArr) . '")';
            }
        }
        if (!empty($params['min_level'])) {
            if (!in_array($params['min_level'], Config::availablePermissionLevels())) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid level');
            }
            $whereClauses[] = 'ui.level >= ' . (is_numeric($params['min_level']) ? (int) $params['min_level'] : 0);
        }
        if (!empty($params['max_level'])) {
            if (!in_array($params['max_level'], Config::availablePermissionLevels())) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid level');
            }
            $whereClauses[] = 'ui.level <= ' . (is_numeric($params['max_level']) ? (int) $params['max_level'] : 0);
        }
        if (!empty($params['group_id'])) {
            $groupIdArr     = is_array($params['group_id']) ? $params['group_id'] : [];
            $whereClauses[] = 'ug.group_id IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groupIdArr)) . ')';
        }
        if (!empty($params['exclude'])) {
            $excludeArr     = is_array($params['exclude']) ? $params['exclude'] : [];
            $whereClauses[] = 'u.' . Config::userFields()['id'] . ' NOT IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $excludeArr)) . ')';
        }
        $display       = ['u.' . Config::userFields()['id'] => 'id'];
        $displayParam  = is_string($params['display'] ?? null) ? $params['display'] : 'none';
        if ($displayParam !== 'none') {
            $params['display'] = array_map(trim(...), explode(',', $displayParam));
            if (in_array('all', $params['display'])) {
                $params['display'] = ['username','email','status','level','groups','language','theme','nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits','enabled_high','registration_date','registration_date_string','registration_date_since','last_visit','last_visit_string','last_visit_since','total_count'];
            } elseif (in_array('basics', $params['display'])) {
                $params['display'] = array_merge($params['display'], ['username','email','status','level','groups']);
            } elseif (in_array('only_id', $params['display'])) {
                $params['display'] = [];
            }
            $params['display'] = array_flip($params['display']);
            if (isset($params['display']['registration_date_string']) || isset($params['display']['registration_date_since'])) {
                $params['display']['registration_date'] = true;
            }
            if (isset($params['display']['last_visit_string']) || isset($params['display']['last_visit_since'])) {
                $params['display']['last_visit'] = true;
            }
            if (isset($params['display']['username'])) {
                $display['u.' . Config::userFields()['username']] = 'username';
            }
            if (isset($params['display']['email'])) {
                $display['u.' . Config::userFields()['email']] = 'email';
            }
            $uiFields = ['status','level','language','theme','nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits','enabled_high','registration_date','last_visit'];
            foreach ($uiFields as $field) {
                if (isset($params['display'][$field])) {
                    $display['ui.' . $field] = $field;
                }
            }
        } else {
            $params['display'] = [];
        }
        $query = 'SELECT DISTINCT ';
        if (isset($params['display']['total_count'])) {
            $query .= 'SQL_CALC_FOUND_ROWS ';
        }
        $first = true;
        foreach ($display as $field => $name) {
            if (!$first) {
                $query .= ', ';
            } else {
                $first = false;
            }
            $query .= $field . ' AS ' . $name;
        }
        if (isset($display['ui.last_visit'])) {
            $query .= ', ui.last_visit_from_history AS last_visit_from_history';
        }
        $query  .= ' FROM ' . Tables::users() . ' AS u INNER JOIN ' . Tables::userInfos() . ' AS ui ON u.' . Config::userFields()['id'] . ' = ui.user_id LEFT JOIN ' . Tables::userGroup() . ' AS ug ON u.' . Config::userFields()['id'] . ' = ug.user_id WHERE ' . implode(' AND ', $whereClauses) . ' ORDER BY ' . $orderStr;
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        if ($perPage !== 0 || $params['display'] !== []) {
            $query .= ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
        }
        $users      = [];
        $usersConn  = $this->conn;
        $usersRows  = $usersConn->executeQuery($query)->fetchAllAssociative();
        $totalCount = 0;
        if (isset($params['display']['total_count'])) {
            $found      = $usersConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
            $totalCount = is_numeric($found) ? (int) $found : 0;
        }
        foreach ($usersRows as $row) {
            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;
            if (isset($params['display']['groups'])) {
                $row['groups'] = [];
            }
            $users[$row['id']] = $row;
        }
        if (count($users) > 0) {
            if (array_key_exists('groups', $params['display'])) {
                $conn = $this->conn;
                $qb   = $conn->createQueryBuilder()->select('user_id', 'group_id')->from(Tables::userGroup());
                $qb->where($qb->expr()->in('user_id', ':ids'))->setParameter('ids', array_keys($users), ArrayParameterType::INTEGER);
                foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                    $grpUid = is_numeric($row['user_id']) ? (int) $row['user_id'] : 0;
                    if (!isset($users[$grpUid]['groups']) || !is_array($users[$grpUid]['groups'])) {
                        $users[$grpUid]['groups'] = [];
                    }
                    $users[$grpUid]['groups'][] = is_numeric($row['group_id']) ? (int) $row['group_id'] : 0;
                }
            }
            foreach ($users as $curUser) {
                /** @var array<string, mixed> $curUser */
                $curUid = is_numeric($curUser['id'] ?? null) ? (int) $curUser['id'] : 0;
                if (isset($params['display']['registration_date_string'])) {
                    $regDate = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_string'] = $this->dateService->formatDate($regDate, ['day', 'month', 'year']);
                }
                if (isset($params['display']['registration_date_since'])) {
                    $regDate2 = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_since'] = $this->dateService->timeSince($regDate2, 'month');
                }
                if (isset($params['display']['last_visit'])) {
                    $lastVisit = $curUser['last_visit'] ?? null;
                    $users[$curUid]['last_visit'] = $lastVisit;
                    if (!BoolUtil::fromMixed($curUser['last_visit_from_history'] ?? null) && ($lastVisit === null || $lastVisit === '')) {
                        $lastVisit                    = $this->userService->getUserLastVisitFromHistory($curUid, true);
                        $users[$curUid]['last_visit'] = $lastVisit;
                    }
                    if (isset($params['display']['last_visit_string'])) {
                        $lvStr = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_string'] = $this->dateService->formatDate($lvStr, ['day', 'month', 'year']);
                    }
                    if (isset($params['display']['last_visit_since'])) {
                        $lvSince = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_since'] = $this->dateService->timeSince($lvSince, 'day');
                    }
                }
            }
        }
        $usersEvent = new WsUsersGetList($users);
        $this->dispatcher->dispatch($usersEvent);
        /** @var array<int|string, array<string, mixed>> $users */
        $users = $usersEvent->users;
        if ($perPage === 0 && $params['display'] === []) {
            $methodResult = array_column(array_values($users), 'id');
        } else {
            $methodResult = ['paging' => new PwgNamedStruct(['page' => $page, 'per_page' => $perPage, 'count' => count($users), 'total_count' => $totalCount]), 'users' => new PwgNamedArray(array_values($users), 'user')];
        }
        if (isset($params['display']['total_count'])) {
            $methodResult['total_count'] = $totalCount;
        }
        return $methodResult;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Registers a new user.', tags: ['users'])]
    public function add(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (strlen(str_replace(' ', '', is_string($params['username']) ? $params['username'] : '')) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        if (Config::doublePasswordTypeInAdmin() && $params['password'] !== $params['password_confirm']) {
            return new PwgError(WsError::InvalidParam->value, Lang::t('The passwords do not match'));
        }
        if ($params['auto_password']) {
            $params['password'] = StringUtil::generateKey(random_int(15, 20));
        }
        $errors = [];
        $passwordRaw = $params['password'] ?? null;
        $userId = $this->userService->registerUser(is_string($params['username']) ? $params['username'] : '', is_string($passwordRaw) ? $passwordRaw : '', is_string($params['email']) ? $params['email'] : null, false, $errors, false);
        if ($userId === false || $userId === 0) {
            return new PwgError(WsError::InvalidParam->value, $errors[0]);
        }
        return $service->invoke('pwg.users.getList', ['user_id' => $userId]);
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Get a new authentication key for a user. Only works for normal/generic users (not admins).', tags: ['users'])]
    public function getAuthKey(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $authkey = $this->authService->createUserAuthKey(is_numeric($params['user_id']) ? (int) $params['user_id'] : 0);
        if ($authkey === false) {
            return new PwgError(WsError::InvalidParam->value, 'invalid user_id');
        }
        return $authkey;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Deletes one or more users. Photos owned by this user are not deleted.', tags: ['users'])]
    public function delete(array $params, PwgServer &$service): PwgError|string
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $currentUser = CurrentUser::get();
        $protectedUsers = [$currentUser->id, Config::guestId(), Config::defaultUserId(), Config::webmasterId()];
        if ($currentUser->status === 'admin') {
            $protectedUsers = array_merge($protectedUsers, array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userInfos() . " WHERE status IN ('webmaster', 'admin');")->fetchAllAssociative(), 'user_id'));
        }
        $userIdArr = is_array($params['user_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['user_id']) : [];
        $userIdArr = array_diff($userIdArr, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $protectedUsers));
        $counter   = 0;
        foreach ($userIdArr as $userId) {
            $this->userAdminService->deleteUser($userId);
            $counter++;
        }
        return Translator::get()->plural('%d user deleted', '%d users deleted', $counter);
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Updates a user. Leave a field blank to keep the current value. username, password and email are ignored if user_id is an array.', tags: ['users'])]
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $updatedUsers = $this->userService->checkAndSaveUserInfos($params);
        if (isset($updatedUsers['error'])) {
            $err = is_array($updatedUsers['error']) ? $updatedUsers['error'] : [];
            return new PwgError(is_int($err['code'] ?? null) ? $err['code'] : null, is_string($err['message'] ?? null) ? $err['message'] : '');
        }
        $infosVal = is_array($updatedUsers['infos'] ?? null) ? $updatedUsers['infos'] : [];
        return $service->invoke('pwg.users.getList', ['user_id' => $updatedUsers['user_id'], 'display' => 'basics,' . implode(',', array_keys($infosVal))]);
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Update the current user.', tags: ['users'])]
    public function setMyInfo(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->permissionService->isAGuest()) {
            return new PwgError(401, 'Access Denied');
        }
        $currentUser = CurrentUser::get();
        if (!Config::activateComments()) {
            unset($params['show_nb_comments']);
        }
        if (!Config::allowUserCustomization()) {
            unset($params['nb_image_page'], $params['theme'], $params['language'], $params['recent_period'], $params['expand'], $params['show_nb_comments'], $params['show_nb_hits']);
        }
        $specialUser = in_array($currentUser->id, [Config::guestId(), Config::defaultUserId()]);
        if ($specialUser) {
            unset($params['password'], $params['theme'], $params['language']);
        }
        if (!empty($params['password'])) {
            if ($params['new_password'] !== $params['conf_new_password']) {
                return new PwgError(403, Lang::t('The passwords do not match'));
            }
            $userFields      = Config::userFields();
            $currentPassword = $this->userRepository->findPasswordById($userFields['password'], $userFields['id'], Tables::users(), $currentUser->id);
            if (!password_verify(is_string($params['password']) ? $params['password'] : '', is_string($currentPassword) ? $currentPassword : '')) {
                return new PwgError(403, Lang::t('Current password is wrong'));
            }
            $params['password'] = $params['new_password'];
        }
        unset($params['new_password'], $params['conf_new_password'], $params['username'], $params['status'], $params['level'], $params['group_id'], $params['enabled_high']);
        $params['user_id'] = [$currentUser->id];
        $updatedUsers2 = $this->userService->checkAndSaveUserInfos($params);
        if (isset($updatedUsers2['error'])) {
            $err2 = is_array($updatedUsers2['error']) ? $updatedUsers2['error'] : [];
            return new PwgError(is_int($err2['code'] ?? null) ? $err2['code'] : null, is_string($err2['message'] ?? null) ? $err2['message'] : '');
        }
        return Lang::t('Your changes have been applied.');
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.', tags: ['users'])]
    public function preferencesSet(array $params, PwgServer &$service): mixed
    {
        $prefParam = is_string($params['param'] ?? null) ? $params['param'] : '';
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $prefParam)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid param name #' . $prefParam . '#');
        }
        $value = stripslashes(is_string($params['value'] ?? null) ? $params['value'] : '');
        if ($params['is_json']) {
            $value = json_decode($value, true);
        }
        $this->preferencesService->userprefsUpdateParam($prefParam, $value);
        return CurrentUser::get()->rawAttributes['preferences'] ?? null;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: "Adds the indicated image to the current user's favorite images.", tags: ['users'])]
    public function favoritesAdd(array $params, PwgServer &$service): PwgError|true
    {
        if ($this->permissionService->isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }
        $userId      = CurrentUser::get()->id;
        $favImageId  = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        if (!$this->imageRepository->existsById($favImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . Tables::favorites() . ' (image_id, user_id) VALUES (?, ?)',
            [$favImageId, $userId]
        );
        return true;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: "Removes the indicated image from the current user's favorite images.", tags: ['users'])]
    public function favoritesRemove(array $params, PwgServer &$service): PwgError|true
    {
        if ($this->permissionService->isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }
        $userId     = CurrentUser::get()->id;
        $remImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        if (!$this->imageRepository->existsById($remImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $this->userRepository->deleteFavorite($userId, $remImageId);
        return true;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|false
     */
    #[ApiMethod(summary: 'Returns the favorite images of the current user.', tags: ['users'])]
    public function favoritesGetList(array $params, PwgServer &$service): false|array
    {
        if ($this->permissionService->isAGuest()) {
            return false;
        }
        $userId = CurrentUser::get()->id;
        $this->userService->checkUserFavorites();
        $orderBy = $this->wsHelper->imageSqlOrder($params, 'i.');
        $orderBy = empty($orderBy) ? Config::orderBy() : 'ORDER BY ' . $orderBy;
        $query   = 'SELECT i.* FROM ' . Tables::favorites() . ' INNER JOIN ' . Tables::images() . ' i ON image_id = i.id WHERE user_id = ' . $userId . ' ' . $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], 'AND') . ' ' . $orderBy . ';';
        $images  = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $image = [];
            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k] ?? null;
            }
            $images[] = array_merge($image, $this->wsHelper->getUrls($row));
        }
        $favPerPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $favPage    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $count      = count($images);
        $images     = array_slice($images, $favPerPage * $favPage, $favPerPage);
        return ['paging' => new PwgNamedStruct(['page' => $favPage, 'per_page' => $favPerPage, 'count' => $count]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Return the reset password link. Only webmaster can perform this action for another webmaster.', tags: ['users'])]
    public function generatePasswordLink(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $targetUserId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        if ($this->userAdminService->getUsername($targetUserId) === false) {
            return new PwgError(WsError::InvalidParam->value, 'This user does not exist.');
        }
        $userLost = $this->userService->getuserdata($targetUserId);
        if ($userLost === false) {
            return new PwgError(404, 'User not found');
        }
        $userLostStatus = is_string($userLost['status'] ?? null) ? $userLost['status'] : '';
        if ($this->permissionService->isAGuest($userLostStatus) || $this->permissionService->isGeneric($userLostStatus)) {
            return new PwgError(403, 'Password reset is not allowed for this user');
        }
        if (CurrentUser::get()->status === 'admin' && $userLostStatus === 'webmaster') {
            return new PwgError(403, 'You cannot perform this action');
        }
        $firstLogin     = $this->userService->hasAlreadyLoggedIn($targetUserId);
        $sendByMailResp = null;
        $userLostLanguage = is_string($userLost['language'] ?? null) ? $userLost['language'] : '';
        $langToUse      = $firstLogin ? $this->userService->getDefaultLanguage() : $userLostLanguage;
        $this->mailService->switchLangTo($langToUse);
        $generateLink = $this->authService->generatePasswordLink($targetUserId, $firstLogin);
        $userLostEmailRaw    = $userLost['email'] ?? null;
        $userLostUsernameRaw = $userLost['username'] ?? null;
        $genPasswordLinkRaw  = $generateLink['password_link'] ?? null;
        $genTimeValidationRaw = $generateLink['time_validation'] ?? null;
        $userLostEmail    = is_string($userLostEmailRaw) ? $userLostEmailRaw : '';
        $userLostUsername = is_string($userLostUsernameRaw) ? $userLostUsernameRaw : '';
        $genPasswordLink  = is_string($genPasswordLinkRaw) ? $genPasswordLinkRaw : '';
        $genTimeValidation = is_string($genTimeValidationRaw) ? $genTimeValidationRaw : '';
        if ($params['send_by_mail'] && $userLostEmail !== '') {
            $emailParams = $firstLogin ? $this->mailService->pwgGenerateSetPasswordMail($userLostUsername, $genPasswordLink, Config::galleryTitle(), $genTimeValidation) : $this->mailService->pwgGenerateResetPasswordMail($userLostUsername, $genPasswordLink, Config::galleryTitle(), $genTimeValidation);
            $sendByMailResp = $this->mailService->pwgMail($userLostEmail, $emailParams) ? 'Mail sent at : ' . $userLostEmail : false;
        }
        $this->mailService->switchLangBack();
        return ['generated_link' => $genPasswordLink, 'send_by_mail' => $sendByMailResp, 'time_validation' => $genTimeValidation];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Update the main user (owner). The user must have status "webmaster"; only a webmaster can call this.', tags: ['users'])]
    public function setMainUser(array $params, PwgServer &$service): PwgError|string
    {
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(403, 'You cannot perform this action');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $mainUserId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        if ($this->userAdminService->getUsername($mainUserId) === false) {
            return new PwgError(WsError::InvalidParam->value, 'This user does not exist.');
        }
        $newMainUser = $this->userService->getuserdata($mainUserId);
        if ($newMainUser === false) {
            return new PwgError(404, 'User not found');
        }
        if ($newMainUser['status'] !== 'webmaster') {
            return new PwgError(403, 'This user cannot become a main user because he is not a webmaster.');
        }
        $this->configService->confUpdateParam('webmaster_id', $mainUserId);
        return 'The main user has been changed.';
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Create a new api key for the user in the current session.', tags: ['users'])]
    public function createApiKey(array $params, PwgServer &$service): PwgError|array
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($params['duration'] < 1 || $params['duration'] > 999999) {
            return new PwgError(400, 'Invalid duration max days is 999999');
        }
        $apiKeyNameRaw = is_string($params['key_name'] ?? null) ? $params['key_name'] : '';
        if (strlen($apiKeyNameRaw) > 100) {
            return new PwgError(400, 'Key name is too long');
        }
        $duration = is_numeric($params['duration']) ? (0 == (int) $params['duration'] ? 1 : (int) $params['duration']) : 1;
        $secret   = $this->userService->createApiKey($userId, $duration, $apiKeyNameRaw);
        $logger->info('[api_key][user_id=' . $userId . '][action=create][key_name=' . $apiKeyNameRaw . ']');
        return $secret;
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Revoke an api key for the user in the current session.', tags: ['users'])]
    public function revokeApiKey(array $params, PwgServer &$service): mixed
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $revokePkid = is_string($params['pkid'] ?? null) ? $params['pkid'] : '';
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $revokePkid)) {
            return new PwgError(403, Lang::t('Invalid pkid format'));
        }
        $revokedKey = $this->userService->revokeApiKey($userId, $revokePkid);
        if (true !== $revokedKey) {
            return new PwgError(403, is_string($revokedKey) ? $revokedKey : '');
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=revoke][pkid=' . $revokePkid . ']');
        return Lang::t('API Key has been successfully revoked.');
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Edit an api key for the user in the current session.', tags: ['users'])]
    public function editApiKey(array $params, PwgServer &$service): mixed
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $editPkid = is_string($params['pkid'] ?? null) ? $params['pkid'] : '';
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $editPkid)) {
            return new PwgError(403, Lang::t('Invalid pkid format'));
        }
        $keyName  = is_string($params['key_name'] ?? null) ? $params['key_name'] : '';
        $editedKey = $this->userService->editApiKey($userId, $editPkid, $keyName);
        if (true !== $editedKey) {
            return new PwgError(403, $editedKey);
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=edit][pkid=' . $editPkid . '][new_name=' . $keyName . ']');
        return Lang::t('API Key has been successfully edited.');
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Get all api keys for the user in the current session.', tags: ['users'])]
    public function getApiKey(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $apiKeys = $this->userService->getApiKey((string) CurrentUser::get()->id);
        return ($apiKeys !== false && count($apiKeys) > 0) ? $apiKeys : new PwgError(404, Lang::t('No API key found'));
    }
}
