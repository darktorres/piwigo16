<?php

declare(strict_types=1);

use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns the list of groups
 * @param mixed[] $params
 *    @option int[] group_id (optional)
 *    @option string name (optional)
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 */
function ws_groups_getList(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $order_str = is_scalar($params['order']) ? (string) $params['order'] : '';
    if (!preg_match(PATTERN_ORDER, $order_str)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter order');
    }

    $where_clauses = ['1=1'];

    if (!empty($params['name'])) {
        $where_clauses[] = 'LOWER(name) LIKE \''. pwg_db_real_escape_string(is_scalar($params['name']) ? (string) $params['name'] : '') .'\'';
    }

    if (!empty($params['group_id'])) {
        $group_id_arr = is_array($params['group_id']) ? $params['group_id'] : [];
        $where_clauses[] = 'id IN('. implode(',', array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $group_id_arr)) .')';
    }

    $per_page = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
    $page = is_numeric($params['page']) ? (int) $params['page'] : 0;

    $query = '
SELECT
    g.*, COUNT(user_id) AS nb_users
  FROM `'. GROUPS_TABLE .'` AS g
    LEFT JOIN '. USER_GROUP_TABLE .' AS ug
    ON ug.group_id = g.id
  WHERE '. implode(' AND ', $where_clauses) .'
  GROUP BY id
  ORDER BY '. $order_str .'
  LIMIT '. $per_page .'
  OFFSET '. ($per_page * $page) .'
;';

    $groups = query2array($query);

    return [
      'paging' => new PwgNamedStruct([
        'page' => $params['page'],
        'per_page' => $params['per_page'],
        'count' => count($groups),
        ]),
      'groups' => new PwgNamedArray($groups, 'group'),
      ];
}

/**
 * API method
 * Adds a group
 * @param mixed[] $params
 *    @option string name
 *    @option bool is_default
 */
/** @param array<mixed> $params */
function ws_groups_add(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    $params['name'] = strip_tags(stripslashes(is_scalar($params['name']) ? (string) $params['name'] : ''));

    // is the name not already used ?
    $groupRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class);
    if ($groupRepo->countByName($params['name']) != 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already used by another group.');
    }

    if (strlen(str_replace(' ', '', $params['name'])) == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
    }

    // creating the group
    $is_default_raw = $params['is_default'];
    $is_default_val = is_bool($is_default_raw) ? $is_default_raw : (is_string($is_default_raw) ? $is_default_raw : '');
    single_insert(
        GROUPS_TABLE,
        [
        'name' => $params['name'],
        'is_default' => \Piwigo\Core\BoolUtil::toString($is_default_val),
        ]
    );
    $inserted_id = pwg_db_insert_id();

    pwg_activity('group', $inserted_id, 'add');

    return $service->invoke('pwg.groups.getList', ['group_id' => $inserted_id]);
}

/**
 * API method
 * Deletes a group
 * @param mixed[] $params
 *    @option int[] group_id
 *    @option string pwg_token
 */
/** @param array<mixed> $params */
function ws_groups_delete(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|PwgNamedArray
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    $group_id_int = is_numeric($params['group_id']) ? (int) $params['group_id'] : (is_array($params['group_id']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $params['group_id']) : 0);
    $groupnames = array_values(delete_groups($group_id_int) ?: []);

    invalidate_user_cache();

    return new PwgNamedArray($groupnames, 'group_deleted');
}

/**
 * API method
 * Updates a group
 * @param mixed[] $params
 *    @option int group_id
 *    @option string name (optional)
 *    @option bool is_default (optional)
 */
/** @param array<mixed> $params */
function ws_groups_setInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $setinfo_name = is_scalar($params['name']) ? (string) $params['name'] : '';
    if (isset($params['name']) && strlen(str_replace(' ', '', $setinfo_name)) == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
    }

    $updates = [];

    $setinfo_group_id = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;

    // does the group exist ?
    $groupRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class);
    if (!$groupRepo->existsById($setinfo_group_id)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
    }

    if (!empty($params['name'])) {
        $params['name'] = strip_tags(stripslashes(is_scalar($params['name']) ? (string) $params['name'] : ''));

        // is the name not already used ?
        if ($groupRepo->countByNameExcludingId($params['name'], $setinfo_group_id) != 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already used by another group.');
        }

        $updates['name'] = $params['name'];
    }

    if (!empty($params['is_default']) or ($params['is_default'] ?? null) === false) {
        $is_default_upd = $params['is_default'];
        $updates['is_default'] = \Piwigo\Core\BoolUtil::toString(is_bool($is_default_upd) ? $is_default_upd : (is_string($is_default_upd) ? $is_default_upd : ''));
    }

    single_update(
        GROUPS_TABLE,
        $updates,
        ['id' => $setinfo_group_id]
    );

    pwg_activity('group', $setinfo_group_id, 'edit');

    return $service->invoke('pwg.groups.getList', ['group_id' => $setinfo_group_id]);
}

/**
 * API method
 * Adds user(s) to a group
 * @param mixed[] $params
 *    @option int group_id
 *    @option int[] user_id
 */
/** @param array<mixed> $params */
function ws_groups_addUser(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $adduser_group_id = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;

    // does the group exist ?
    if (!\Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class)->existsById($adduser_group_id)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
    }

    $user_ids = is_array($params['user_id']) ? $params['user_id'] : [];
    $inserts = [];
    foreach ($user_ids as $user_id) {
        $inserts[] = [
          'group_id' => $adduser_group_id,
          'user_id' => $user_id,
          ];
    }

    mass_inserts(
        USER_GROUP_TABLE,
        ['group_id', 'user_id'],
        $inserts,
        ['ignore' => true]
    );

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    invalidate_user_cache();

    pwg_activity('group', $adduser_group_id, 'edit');
    pwg_activity('user', array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $user_ids), 'edit');

    return $service->invoke('pwg.groups.getList', ['group_id' => $adduser_group_id]);
}

/**
 * API method
 * Merge groups in one other group
 * @param mixed[] $params
 *    @option int destination_group_id
 *    @option int[] merge_group_id
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 */
function ws_groups_merge(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $dest_group_id = is_numeric($params['destination_group_id']) ? (int) $params['destination_group_id'] : 0;
    $merge_group_ids_raw = is_array($params['merge_group_id']) ? $params['merge_group_id'] : [];
    $merge_group_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $merge_group_ids_raw);

    $all_groups = $merge_group_ids;
    $all_groups[] = $dest_group_id;
    $all_groups = array_unique($all_groups);
    $merge_group = array_diff($merge_group_ids, [$dest_group_id]);
    $merge_group_object = $service->invoke('pwg.groups.getList', ['group_id' => $merge_group_ids]);

    $groupRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class);
    if ($groupRepo->countByIds($all_groups) != count($all_groups)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'All groups does not exist.');
    }

    $query = '
SELECT DISTINCT(user_id)
  FROM `'. USER_GROUP_TABLE .'`
  WHERE
    group_id IN ('.implode(',', $merge_group) .')
;';
    $user_in_merge_groups = query2array($query, null, 'user_id');

    $query = '
SELECT user_id
  FROM `'. USER_GROUP_TABLE .'`
  WHERE group_id = '.$dest_group_id.'
;';

    $user_in_dest = query2array($query, null, 'user_id');

    $user_to_add = array_diff($user_in_merge_groups, $user_in_dest);

    $inserts = [];
    foreach ($user_to_add as $user) {
        $inserts[] = [
          'group_id' => $dest_group_id,
          'user_id' => $user,
          ];
    }

    mass_inserts(
        USER_GROUP_TABLE,
        ['group_id', 'user_id'],
        $inserts,
        ['ignore' => true]
    );

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    invalidate_user_cache();

    pwg_activity('group', $dest_group_id, 'edit');
    foreach ($user_to_add as $user_id) {
        $user_id_int = is_numeric($user_id) ? (int) $user_id : (is_scalar($user_id) ? (string) $user_id : 0);
        pwg_activity('user', $user_id_int, 'edit', ['associated' => $dest_group_id]);
    }

    delete_groups($merge_group);

    return [
      'destination_group' => $service->invoke('pwg.groups.getList', ['group_id' => $dest_group_id]),
      'deleted_group' => $merge_group_object,
    ];
}

/**
 * API method
 * Create a copy of a group
 * @param mixed[] $params
 *    @option int group_id
 *    @option string copy_name
 */
/** @param array<mixed> $params */
function ws_groups_duplicate(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $dup_group_id = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
    $copy_name_str = is_scalar($params['copy_name']) ? (string) $params['copy_name'] : '';

    $groupRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class);
    if ($groupRepo->countByName($copy_name_str) != 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This name is already used by another group.');
    }

    if (!$groupRepo->existsById($dup_group_id)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
    }

    $is_default = $groupRepo->findIsDefault($dup_group_id);

    // creating the group
    single_insert(
        GROUPS_TABLE,
        [
        'name' => $copy_name_str,
        'is_default' => \Piwigo\Core\BoolUtil::toString(is_string($is_default) ? $is_default : ''),
        ]
    );
    $inserted_id = pwg_db_insert_id();

    pwg_activity('group', $inserted_id, 'add');

    $query = '
  SELECT user_id
    FROM `'. USER_GROUP_TABLE .'`
    WHERE group_id = '.$dup_group_id.'
  ;';

    $users = query2array($query, null, 'user_id');

    $inserts = [];
    foreach ($users as $user) {
        $inserts[] = [
          'group_id' => $inserted_id,
          'user_id' => $user,
          ];
    }

    mass_inserts(
        USER_GROUP_TABLE,
        ['group_id', 'user_id'],
        $inserts,
        ['ignore' => true]
    );

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    invalidate_user_cache();

    foreach ($users as $user_id) {
        $uid = is_numeric($user_id) ? (int) $user_id : (is_scalar($user_id) ? (string) $user_id : 0);
        pwg_activity('user', $uid, 'edit', ['associated' => $dup_group_id]);
    }

    return $service->invoke('pwg.groups.getList', ['group_id' => $inserted_id]);
}

/**
 * API method
 * Removes user(s) from a group
 * @param mixed[] $params
 *    @option int group_id
 *    @option int[] user_id
 */
/** @param array<mixed> $params */
function ws_groups_deleteUser(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $deluser_group_id = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
    $deluser_user_ids = is_array($params['user_id']) ? $params['user_id'] : [];

    // does the group exist ?
    $groupRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class);
    if (!$groupRepo->existsById($deluser_group_id)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
    }

    $groupRepo->deleteUserGroupMembers(
        $deluser_group_id,
        array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $deluser_user_ids)
    );

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    invalidate_user_cache();

    pwg_activity('group', $deluser_group_id, 'edit');
    pwg_activity('user', array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $deluser_user_ids), 'edit');

    return $service->invoke('pwg.groups.getList', ['group_id' => $deluser_group_id]);
}
