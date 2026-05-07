<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\SchemaHelper;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsHelper;

final class UsersEndpoints
{
    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $orderStr = is_scalar($params['order']) ? (string) $params['order'] : '';
        if (!preg_match(PATTERN_ORDER, $orderStr)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter order');
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
            $whereClauses[] = 'u.' . Config::userFields()['username'] . ' LIKE ' . get_dbal_connection()->quote(is_scalar($params['username']) ? (string) $params['username'] : '');
        }
        $filteredGroups = [];
        if (!empty($params['filter'])) {
            $filterStr      = is_scalar($params['filter']) ? (string) $params['filter'] : '';
            $filteredGroups = ServiceLocator::get(GroupRepository::class)->findIdsByNameLike($filterStr);
            $filterQuoted   = get_dbal_connection()->quote('%' . $filterStr . '%');
            $filterWhere    = '(u.' . Config::userFields()['username'] . ' LIKE ' . $filterQuoted . ' OR u.' . Config::userFields()['email'] . ' LIKE ' . $filterQuoted;
            if (!empty($filteredGroups)) {
                $filterWhere .= ' OR ug.group_id IN (' . implode(',', array_map(fn (int $v): string => (string) $v, $filteredGroups)) . ')';
            }
            $whereClauses[] = $filterWhere . ')';
        }
        if (!empty($params['min_register'])) {
            $minRegisterStr = is_scalar($params['min_register']) ? (string) $params['min_register'] : '';
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $minRegisterStr)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter min_register');
            }
            $dateTokens     = explode('-', $minRegisterStr);
            $minDate        = sprintf('%u-%02u-%02u', $dateTokens[0], $dateTokens[1] ?? 1, $dateTokens[2] ?? 1);
            $whereClauses[] = "ui.registration_date >= '$minDate 00:00:00'";
        }
        if (!empty($params['max_register'])) {
            $maxRegisterStr = is_scalar($params['max_register']) ? (string) $params['max_register'] : '';
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $maxRegisterStr)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter max_register');
            }
            $maxDateTokens = explode('-', $maxRegisterStr);
            $maxDay        = $maxDateTokens[2] ?? date('t', strtotime($maxDateTokens[0] . '-' . ($maxDateTokens[1] ?? '12') . '-1') ?: null);
            $maxDate       = sprintf('%u-%02u-%02u', $maxDateTokens[0], $maxDateTokens[1] ?? '12', $maxDay);
            $whereClauses[] = "ui.registration_date <= '$maxDate 23:59:59'";
        }
        if (!empty($params['status'])) {
            $statusArr  = is_array($params['status']) ? $params['status'] : [];
            $statusArr  = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $statusArr);
            $statusArr  = array_intersect($statusArr, SchemaHelper::getEnums(USER_INFOS_TABLE, 'status'));
            if (count($statusArr) > 0) {
                $whereClauses[] = 'ui.status IN("' . implode('","', $statusArr) . '")';
            }
        }
        if (!empty($params['min_level'])) {
            if (!in_array($params['min_level'], Config::availablePermissionLevels())) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
            }
            $whereClauses[] = 'ui.level >= ' . (is_numeric($params['min_level']) ? (int) $params['min_level'] : 0);
        }
        if (!empty($params['max_level'])) {
            if (!in_array($params['max_level'], Config::availablePermissionLevels())) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
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
        $displayParam  = is_scalar($params['display']) ? (string) $params['display'] : 'none';
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
        $query  .= ' FROM ' . USERS_TABLE . ' AS u INNER JOIN ' . USER_INFOS_TABLE . ' AS ui ON u.' . Config::userFields()['id'] . ' = ui.user_id LEFT JOIN ' . USER_GROUP_TABLE . ' AS ug ON u.' . Config::userFields()['id'] . ' = ug.user_id WHERE ' . implode(' AND ', $whereClauses) . ' ORDER BY ' . $orderStr;
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        if ($perPage !== 0 || !empty($params['display'])) {
            $query .= ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
        }
        $users      = [];
        $usersConn  = ServiceLocator::get(Connection::class);
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
                $conn = ServiceLocator::get(Connection::class);
                $qb   = $conn->createQueryBuilder()->select('user_id', 'group_id')->from(USER_GROUP_TABLE);
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
                    $users[$curUid]['registration_date_string'] = format_date($regDate, ['day', 'month', 'year']);
                }
                if (isset($params['display']['registration_date_since'])) {
                    $regDate2 = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_since'] = time_since($regDate2, 'month');
                }
                if (isset($params['display']['last_visit'])) {
                    $lastVisit = $curUser['last_visit'] ?? null;
                    $users[$curUid]['last_visit'] = $lastVisit;
                    if (!BoolUtil::fromMixed($curUser['last_visit_from_history'] ?? null) && empty($lastVisit)) {
                        $lastVisit                    = UserService::get()->getUserLastVisitFromHistory($curUid, true);
                        $users[$curUid]['last_visit'] = $lastVisit;
                    }
                    if (isset($params['display']['last_visit_string'])) {
                        $lvStr = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_string'] = format_date($lvStr, ['day', 'month', 'year']);
                    }
                    if (isset($params['display']['last_visit_since'])) {
                        $lvSince = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_since'] = time_since($lvSince, 'day');
                    }
                }
            }
        }
        $users = trigger_change('ws_users_getList', $users);
        if ($perPage === 0 && empty($params['display'])) {
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
    public function add(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (strlen(str_replace(' ', '', is_scalar($params['username']) ? (string) $params['username'] : '')) === 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
        }
        if (Config::doublePasswordTypeInAdmin() && $params['password'] !== $params['password_confirm']) {
            return new PwgError(WS_ERR_INVALID_PARAM, l10n('The passwords do not match'));
        }
        if ($params['auto_password']) {
            $params['password'] = generate_key(random_int(15, 20));
        }
        $errors = [];
        $userId = UserService::get()->registerUser(is_string($params['username']) ? $params['username'] : '', is_string($params['password']) ? $params['password'] : '', is_string($params['email']) ? $params['email'] : null, false, $errors, false);
        if (!$userId) {
            return new PwgError(WS_ERR_INVALID_PARAM, $errors[0]);
        }
        return $service->invoke('pwg.users.getList', ['user_id' => $userId]);
    }

    /** @param array<mixed> $params */
    public function getAuthKey(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $authkey = AuthService::get()->createUserAuthKey(is_numeric($params['user_id']) ? (int) $params['user_id'] : 0);
        if ($authkey === false) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'invalid user_id');
        }
        return $authkey;
    }

    /** @param array<mixed> $params */
    public function delete(array $params, PwgServer &$service): PwgError|string
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $currentUser = CurrentUser::get();
        $protectedUsers = [$currentUser->id, Config::guestId(), Config::defaultUserId(), Config::webmasterId()];
        if ($currentUser->status === 'admin') {
            $protectedUsers = array_merge($protectedUsers, array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_INFOS_TABLE . " WHERE status IN ('webmaster', 'admin');")->fetchAllAssociative(), 'user_id'));
        }
        $userIdArr = is_array($params['user_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['user_id']) : [];
        $userIdArr = array_diff($userIdArr, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $protectedUsers));
        $counter   = 0;
        foreach ($userIdArr as $userId) {
            ServiceLocator::get(UserAdminService::class)->deleteUser($userId);
            $counter++;
        }
        return l10n_dec('%d user deleted', '%d users deleted', $counter);
    }

    /** @param array<mixed> $params */
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $updatedUsers = UserService::get()->checkAndSaveUserInfos($params);
        if (isset($updatedUsers['error'])) {
            $err = is_array($updatedUsers['error']) ? $updatedUsers['error'] : [];
            return new PwgError(is_int($err['code'] ?? null) ? $err['code'] : null, is_string($err['message'] ?? null) ? $err['message'] : '');
        }
        $infosVal = is_array($updatedUsers['infos'] ?? null) ? $updatedUsers['infos'] : [];
        return $service->invoke('pwg.users.getList', ['user_id' => $updatedUsers['user_id'], 'display' => 'basics,' . implode(',', array_keys($infosVal))]);
    }

    /** @param array<mixed> $params */
    public function setMyInfo(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (PermissionService::get()->isAGuest()) {
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
                return new PwgError(403, l10n('The passwords do not match'));
            }
            $userFields      = Config::userFields();
            $currentPassword = ServiceLocator::get(UserRepository::class)->findPasswordById($userFields['password'], $userFields['id'], USERS_TABLE, (int) $currentUser->id);
            if (!password_verify(is_scalar($params['password']) ? (string) $params['password'] : '', is_string($currentPassword) ? $currentPassword : '')) {
                return new PwgError(403, l10n('Current password is wrong'));
            }
            $params['password'] = $params['new_password'];
        }
        unset($params['new_password'], $params['conf_new_password'], $params['username'], $params['status'], $params['level'], $params['group_id'], $params['enabled_high']);
        $params['user_id'] = [$currentUser->id];
        $updatedUsers2 = UserService::get()->checkAndSaveUserInfos($params);
        if (isset($updatedUsers2['error'])) {
            $err2 = is_array($updatedUsers2['error']) ? $updatedUsers2['error'] : [];
            return new PwgError(is_int($err2['code'] ?? null) ? $err2['code'] : null, is_string($err2['message'] ?? null) ? $err2['message'] : '');
        }
        return l10n('Your changes have been applied.');
    }

    /** @param array<mixed> $params */
    public function preferencesSet(array $params, PwgServer &$service): mixed
    {
        $prefParam = is_scalar($params['param']) ? (string) $params['param'] : '';
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $prefParam)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid param name #' . $prefParam . '#');
        }
        $value = stripslashes(is_scalar($params['value']) ? (string) $params['value'] : '');
        if ($params['is_json']) {
            $value = json_decode($value, true);
        }
        PreferencesService::get()->userprefsUpdateParam($prefParam, $value);
        return CurrentUser::get()->rawAttributes['preferences'] ?? null;
    }

    /** @param array<mixed> $params */
    public function favoritesAdd(array $params, PwgServer &$service): PwgError|true
    {
        if (PermissionService::get()->isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }
        $userId      = CurrentUser::get()->id;
        $favImageId  = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        if (!ServiceLocator::get(ImageRepository::class)->existsById($favImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        single_insert(FAVORITES_TABLE, ['image_id' => $favImageId, 'user_id' => $userId], ['ignore' => true]);
        return true;
    }

    /** @param array<mixed> $params */
    public function favoritesRemove(array $params, PwgServer &$service): PwgError|true
    {
        if (PermissionService::get()->isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }
        $userId     = CurrentUser::get()->id;
        $remImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        if (!ServiceLocator::get(ImageRepository::class)->existsById($remImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        ServiceLocator::get(UserRepository::class)->deleteFavorite($userId, $remImageId);
        return true;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|false
     */
    public function favoritesGetList(array $params, PwgServer &$service): false|array
    {
        if (PermissionService::get()->isAGuest()) {
            return false;
        }
        $userId = CurrentUser::get()->id;
        UserService::get()->checkUserFavorites();
        $orderBy = ServiceLocator::get(WsHelper::class)->imageSqlOrder($params, 'i.');
        $orderBy = empty($orderBy) ? Config::orderBy() : 'ORDER BY ' . $orderBy;
        $query   = 'SELECT i.* FROM ' . FAVORITES_TABLE . ' INNER JOIN ' . IMAGES_TABLE . ' i ON image_id = i.id WHERE user_id = ' . $userId . ' ' . PermissionService::get()->getSqlConditionFandF(['visible_images' => 'id'], 'AND') . ' ' . $orderBy . ';';
        $images  = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative() as $row) {
            $image = [];
            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }
            $images[] = array_merge($image, ServiceLocator::get(WsHelper::class)->getUrls($row));
        }
        $favPerPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $favPage    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $count      = count($images);
        $images     = array_slice($images, $favPerPage * $favPage, $favPerPage);
        return ['paging' => new PwgNamedStruct(['page' => $favPage, 'per_page' => $favPerPage, 'count' => $count]), 'images' => new PwgNamedArray($images, 'image', ServiceLocator::get(WsHelper::class)->getImageXmlAttributes())];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function generatePasswordLink(array $params, PwgServer &$service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $targetUserId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        if (ServiceLocator::get(UserAdminService::class)->getUsername($targetUserId) === false) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
        }
        $userLost = UserService::get()->getuserdata($targetUserId);
        if ($userLost === false) {
            return new PwgError(404, 'User not found');
        }
        $userLostStatus = is_string($userLost['status'] ?? null) ? $userLost['status'] : '';
        if (PermissionService::get()->isAGuest($userLostStatus) || PermissionService::get()->isGeneric($userLostStatus)) {
            return new PwgError(403, 'Password reset is not allowed for this user');
        }
        if (CurrentUser::get()->status === 'admin' && $userLostStatus === 'webmaster') {
            return new PwgError(403, 'You cannot perform this action');
        }
        $firstLogin     = UserService::get()->hasAlreadyLoggedIn($targetUserId);
        $sendByMailResp = null;
        $userLostLanguage = is_string($userLost['language'] ?? null) ? $userLost['language'] : '';
        $langToUse      = $firstLogin ? UserService::get()->getDefaultLanguage() : $userLostLanguage;
        switch_lang_to($langToUse);
        $generateLink = AuthService::get()->generatePasswordLink($targetUserId, $firstLogin);
        $userLostEmail    = is_string($userLost['email'] ?? null) ? $userLost['email'] : '';
        $userLostUsername = is_string($userLost['username'] ?? null) ? $userLost['username'] : '';
        $genPasswordLink  = is_string($generateLink['password_link'] ?? null) ? $generateLink['password_link'] : '';
        $genTimeValidation = is_string($generateLink['time_validation'] ?? null) ? $generateLink['time_validation'] : '';
        if ($params['send_by_mail'] && !empty($userLostEmail)) {
            $emailParams = $firstLogin ? pwg_generate_set_password_mail($userLostUsername, $genPasswordLink, Config::galleryTitle(), $genTimeValidation) : pwg_generate_reset_password_mail($userLostUsername, $genPasswordLink, Config::galleryTitle(), $genTimeValidation);
            $sendByMailResp = pwg_mail($userLostEmail, $emailParams) ? 'Mail sent at : ' . $userLostEmail : false;
        }
        switch_lang_back();
        return ['generated_link' => $genPasswordLink, 'send_by_mail' => $sendByMailResp, 'time_validation' => $genTimeValidation];
    }

    /** @param array<mixed> $params */
    public function setMainUser(array $params, PwgServer &$service): PwgError|string
    {
        if (!PermissionService::get()->isWebmaster()) {
            return new PwgError(403, 'You cannot perform this action');
        }
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $mainUserId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        if (ServiceLocator::get(UserAdminService::class)->getUsername($mainUserId) === false) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
        }
        $newMainUser = UserService::get()->getuserdata($mainUserId);
        if ($newMainUser === false) {
            return new PwgError(404, 'User not found');
        }
        if ($newMainUser['status'] !== 'webmaster') {
            return new PwgError(403, 'This user cannot become a main user because he is not a webmaster.');
        }
        conf_update_param('webmaster_id', $params['user_id']);
        return 'The main user has been changed.';
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function createApiKey(array $params, PwgServer &$service): PwgError|array
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if (PermissionService::get()->isAGuest() || !AuthService::get()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($params['duration'] < 1 || $params['duration'] > 999999) {
            return new PwgError(400, 'Invalid duration max days is 999999');
        }
        $apiKeyNameRaw = is_scalar($params['key_name']) ? (string) $params['key_name'] : '';
        if (strlen($apiKeyNameRaw) > 100) {
            return new PwgError(400, 'Key name is too long');
        }
        $duration = is_numeric($params['duration']) ? (0 == (int) $params['duration'] ? 1 : (int) $params['duration']) : 1;
        $secret   = UserService::get()->createApiKey($userId, $duration, $apiKeyNameRaw);
        $logger->info('[api_key][user_id=' . $userId . '][action=create][key_name=' . $apiKeyNameRaw . ']');
        return $secret;
    }

    /** @param array<mixed> $params */
    public function revokeApiKey(array $params, PwgServer &$service): mixed
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if (PermissionService::get()->isAGuest() || !AuthService::get()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, l10n('Invalid security token'));
        }
        $revokePkid = is_scalar($params['pkid']) ? (string) $params['pkid'] : '';
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $revokePkid)) {
            return new PwgError(403, l10n('Invalid pkid format'));
        }
        $revokedKey = UserService::get()->revokeApiKey($userId, $revokePkid);
        if (true !== $revokedKey) {
            return new PwgError(403, is_string($revokedKey) ? $revokedKey : '');
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=revoke][pkid=' . $revokePkid . ']');
        return l10n('API Key has been successfully revoked.');
    }

    /** @param array<mixed> $params */
    public function editApiKey(array $params, PwgServer &$service): mixed
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if (PermissionService::get()->isAGuest() || !AuthService::get()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, l10n('Invalid security token'));
        }
        $editPkid = is_scalar($params['pkid']) ? (string) $params['pkid'] : '';
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $editPkid)) {
            return new PwgError(403, l10n('Invalid pkid format'));
        }
        $keyName  = is_scalar($params['key_name']) ? (string) $params['key_name'] : '';
        $editedKey = UserService::get()->editApiKey($userId, $editPkid, $keyName);
        if (true !== $editedKey) {
            return new PwgError(403, $editedKey);
        }
        $logger->info('[api_key][user_id=' . $userId . '][action=edit][pkid=' . $editPkid . '][new_name=' . $keyName . ']');
        return l10n('API Key has been successfully edited.');
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getApiKey(array $params, PwgServer &$service): PwgError|array
    {
        if (PermissionService::get()->isAGuest() || !AuthService::get()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $apiKeys = UserService::get()->getApiKey((string) CurrentUser::get()->id);
        return $apiKeys ?: new PwgError(404, l10n('No API key found'));
    }
}
