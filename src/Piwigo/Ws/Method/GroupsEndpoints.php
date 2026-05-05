<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Piwigo\Core\BoolUtil;
use Piwigo\Core\ServiceLocator;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

final class GroupsEndpoints
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
        $whereClauses = ['1=1'];
        if (!empty($params['name'])) {
            $whereClauses[] = 'LOWER(name) LIKE ' . get_dbal_connection()->quote(is_scalar($params['name']) ? (string) $params['name'] : '');
        }
        if (!empty($params['group_id'])) {
            $groupIdArr     = is_array($params['group_id']) ? $params['group_id'] : [];
            $whereClauses[] = 'id IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groupIdArr)) . ')';
        }
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $query   = 'SELECT g.*, COUNT(user_id) AS nb_users FROM `' . GROUPS_TABLE . '` AS g LEFT JOIN ' . USER_GROUP_TABLE . ' AS ug ON ug.group_id = g.id WHERE ' . implode(' AND ', $whereClauses) . ' GROUP BY id ORDER BY ' . $orderStr . ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
        $groups  = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($groups)]), 'groups' => new PwgNamedArray($groups, 'group')];
    }

    /** @param array<mixed> $params */
    public function add(array $params, PwgServer &$service): mixed
    {
        $params['name'] = strip_tags(stripslashes(is_scalar($params['name']) ? (string) $params['name'] : ''));
        $groupRepo      = ServiceLocator::get(GroupRepository::class);
        if ($groupRepo->countByName($params['name']) !== 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already used by another group.');
        }
        if (strlen(str_replace(' ', '', $params['name'])) === 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
        }
        $isDefaultRaw = $params['is_default'];
        $isDefaultVal = is_bool($isDefaultRaw) ? $isDefaultRaw : (is_string($isDefaultRaw) ? $isDefaultRaw : '');
        single_insert(GROUPS_TABLE, ['name' => $params['name'], 'is_default' => BoolUtil::toString($isDefaultVal)]);
        $insertedId = (int) get_dbal_connection()->lastInsertId();
        pwg_activity('group', $insertedId, 'add');
        return $service->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }

    /** @param array<mixed> $params */
    public function delete(array $params, PwgServer &$service): PwgError|PwgNamedArray
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $groupIdInt = is_numeric($params['group_id']) ? (int) $params['group_id'] : (is_array($params['group_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['group_id']) : 0);
        $groupnames = array_values(delete_groups($groupIdInt) ?: []);
        invalidate_user_cache();
        return new PwgNamedArray($groupnames, 'group_deleted');
    }

    /** @param array<mixed> $params */
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $setinfoName = is_scalar($params['name']) ? (string) $params['name'] : '';
        if (isset($params['name']) && strlen(str_replace(' ', '', $setinfoName)) === 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
        }
        $updates         = [];
        $setinfoGroupId  = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $groupRepo       = ServiceLocator::get(GroupRepository::class);
        if (!$groupRepo->existsById($setinfoGroupId)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
        }
        if (!empty($params['name'])) {
            $params['name'] = strip_tags(stripslashes(is_scalar($params['name']) ? (string) $params['name'] : ''));
            if ($groupRepo->countByNameExcludingId($params['name'], $setinfoGroupId) !== 0) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already used by another group.');
            }
            $updates['name'] = $params['name'];
        }
        if (!empty($params['is_default']) || ($params['is_default'] ?? null) === false) {
            $isDefaultUpd          = $params['is_default'];
            $updates['is_default'] = BoolUtil::toString(is_bool($isDefaultUpd) ? $isDefaultUpd : (is_string($isDefaultUpd) ? $isDefaultUpd : ''));
        }
        single_update(GROUPS_TABLE, $updates, ['id' => $setinfoGroupId]);
        pwg_activity('group', $setinfoGroupId, 'edit');
        return $service->invoke('pwg.groups.getList', ['group_id' => $setinfoGroupId]);
    }

    /** @param array<mixed> $params */
    public function addUser(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $adduserGroupId = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        if (!ServiceLocator::get(GroupRepository::class)->existsById($adduserGroupId)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
        }
        $userIds = is_array($params['user_id']) ? $params['user_id'] : [];
        $inserts = [];
        foreach ($userIds as $userId) {
            $inserts[] = ['group_id' => $adduserGroupId, 'user_id' => $userId];
        }
        mass_inserts(USER_GROUP_TABLE, ['group_id', 'user_id'], $inserts, ['ignore' => true]);
        invalidate_user_cache();
        pwg_activity('group', $adduserGroupId, 'edit');
        pwg_activity('user', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIds), 'edit');
        return $service->invoke('pwg.groups.getList', ['group_id' => $adduserGroupId]);
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function merge(array $params, PwgServer &$service): PwgError|array
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $destGroupId   = is_numeric($params['destination_group_id']) ? (int) $params['destination_group_id'] : 0;
        $mergeGroupIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['merge_group_id']) ? $params['merge_group_id'] : []);
        $allGroups     = array_unique(array_merge($mergeGroupIds, [$destGroupId]));
        $mergeGroup    = array_diff($mergeGroupIds, [$destGroupId]);
        $mergeGroupObj = $service->invoke('pwg.groups.getList', ['group_id' => $mergeGroupIds]);
        $groupRepo     = ServiceLocator::get(GroupRepository::class);
        if ($groupRepo->countByIds($allGroups) !== count($allGroups)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'All groups does not exist.');
        }
        $userInMergeGroups = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(user_id) FROM `' . USER_GROUP_TABLE . '` WHERE group_id IN (' . implode(',', $mergeGroup) . ');')->fetchAllAssociative(), 'user_id');
        $userInDest        = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM `' . USER_GROUP_TABLE . '` WHERE group_id = ' . $destGroupId . ';')->fetchAllAssociative(), 'user_id');
        $userToAdd         = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $userInMergeGroups), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $userInDest));
        $inserts           = [];
        foreach ($userToAdd as $user) {
            $inserts[] = ['group_id' => $destGroupId, 'user_id' => $user];
        }
        mass_inserts(USER_GROUP_TABLE, ['group_id', 'user_id'], $inserts, ['ignore' => true]);
        invalidate_user_cache();
        pwg_activity('group', $destGroupId, 'edit');
        foreach ($userToAdd as $userId) {
            $userIdInt = is_numeric($userId) ? (int) $userId : (string) $userId;
            pwg_activity('user', $userIdInt, 'edit', ['associated' => $destGroupId]);
        }
        delete_groups($mergeGroup);
        return ['destination_group' => $service->invoke('pwg.groups.getList', ['group_id' => $destGroupId]), 'deleted_group' => $mergeGroupObj];
    }

    /** @param array<mixed> $params */
    public function duplicate(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $dupGroupId  = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $copyNameStr = is_scalar($params['copy_name']) ? (string) $params['copy_name'] : '';
        $groupRepo   = ServiceLocator::get(GroupRepository::class);
        if ($groupRepo->countByName($copyNameStr) !== 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already used by another group.');
        }
        if (!$groupRepo->existsById($dupGroupId)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
        }
        $isDefault = $groupRepo->findIsDefault($dupGroupId);
        single_insert(GROUPS_TABLE, ['name' => $copyNameStr, 'is_default' => BoolUtil::toString(is_string($isDefault) ? $isDefault : '')]);
        $insertedId = (int) get_dbal_connection()->lastInsertId();
        pwg_activity('group', $insertedId, 'add');
        $users   = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM `' . USER_GROUP_TABLE . '` WHERE group_id = ' . $dupGroupId . ';')->fetchAllAssociative(), 'user_id');
        $inserts = [];
        foreach ($users as $user) {
            $inserts[] = ['group_id' => $insertedId, 'user_id' => $user];
        }
        mass_inserts(USER_GROUP_TABLE, ['group_id', 'user_id'], $inserts, ['ignore' => true]);
        invalidate_user_cache();
        foreach ($users as $userId) {
            $uid = is_numeric($userId) ? (int) $userId : (is_scalar($userId) ? (string) $userId : 0);
            pwg_activity('user', $uid, 'edit', ['associated' => $dupGroupId]);
        }
        return $service->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }

    /** @param array<mixed> $params */
    public function deleteUser(array $params, PwgServer &$service): mixed
    {
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $deluserGroupId = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $deluserUserIds = is_array($params['user_id']) ? $params['user_id'] : [];
        $groupRepo      = ServiceLocator::get(GroupRepository::class);
        if (!$groupRepo->existsById($deluserGroupId)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
        }
        $groupRepo->deleteUserGroupMembers($deluserGroupId, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $deluserUserIds));
        invalidate_user_cache();
        pwg_activity('group', $deluserGroupId, 'edit');
        pwg_activity('user', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $deluserUserIds), 'edit');
        return $service->invoke('pwg.groups.getList', ['group_id' => $deluserGroupId]);
    }
}
