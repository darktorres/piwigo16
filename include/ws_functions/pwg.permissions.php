<?php

declare(strict_types=1);

use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns permissions
 * @param mixed[] $params
 *    @option int[] cat_id (optional)
 *    @option int[] group_id (optional)
 *    @option int[] user_id (optional)
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 */
function ws_permissions_getList(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $my_params = array_intersect(array_keys($params), ['cat_id','group_id','user_id']);
    if (count($my_params) > 1) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Too many parameters, provide cat_id OR user_id OR group_id');
    }

    $cat_filter = '';
    if (!empty($params['cat_id'])) {
        $cat_id_arr = is_array($params['cat_id']) ? $params['cat_id'] : [];
        $cat_id_arr_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $cat_id_arr);
        $cat_filter = 'WHERE cat_id IN('. implode(',', $cat_id_arr_str) .')';
    }

    $permRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Permission\PermissionRepository::class);
    $catIdsFilter = !empty($params['cat_id'])
        ? array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['cat_id']) ? $params['cat_id'] : [])
        : null;

    $perms = [];

    // direct users
    foreach ($permRepo->findUserCategoryAccess($catIdsFilter) as $row) {
        $cat_id = is_numeric($row['cat_id']) ? (int)$row['cat_id'] : 0;
        if (!isset($perms[ $cat_id ])) {
            $perms[ $cat_id ]['id'] = $cat_id;
        }
        $perms[ $cat_id ]['users'][] = is_numeric($row['user_id']) ? (int)$row['user_id'] : 0;
    }

    // indirect users
    foreach ($permRepo->findGroupUserCategoryAccess($catIdsFilter) as $row) {
        $cat_id = is_numeric($row['cat_id']) ? (int)$row['cat_id'] : 0;
        if (!isset($perms[ $cat_id ])) {
            $perms[ $cat_id ]['id'] = $cat_id;
        }
        $perms[ $cat_id ]['users_indirect'][] = is_numeric($row['user_id']) ? (int)$row['user_id'] : 0;
    }

    // groups
    foreach ($permRepo->findGroupCategoryAccess($catIdsFilter) as $row) {
        $cat_id = is_numeric($row['cat_id']) ? (int)$row['cat_id'] : 0;
        if (!isset($perms[ $cat_id ])) {
            $perms[ $cat_id ]['id'] = $cat_id;
        }
        $perms[ $cat_id ]['groups'][] = is_numeric($row['group_id']) ? (int)$row['group_id'] : 0;
    }

    // filter by group and user
    foreach ($perms as $cat_id => &$cat) {
        if (isset($params['group_id'])) {
            $group_id_arr = is_array($params['group_id']) ? $params['group_id'] : [];
            $group_id_arr_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $group_id_arr);
            $cat_groups_str = array_map(fn ($v) => (string) $v, $cat['groups'] ?? []);
            if (empty($cat['groups']) or count(array_intersect($cat_groups_str, $group_id_arr_str)) == 0) {
                unset($perms[$cat_id]);
                continue;
            }
        }
        if (isset($params['user_id'])) {
            $user_id_arr = is_array($params['user_id']) ? $params['user_id'] : [];
            $user_id_arr_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $user_id_arr);
            $cat_users_indirect_str = array_map(fn ($v) => (string) $v, $cat['users_indirect'] ?? []);
            $cat_users_str = array_map(fn ($v) => (string) $v, $cat['users'] ?? []);
            if (
                (empty($cat['users_indirect']) or count(array_intersect($cat_users_indirect_str, $user_id_arr_str)) == 0)
                and (empty($cat['users']) or count(array_intersect($cat_users_str, $user_id_arr_str)) == 0)
            ) {
                unset($perms[$cat_id]);
                continue;
            }
        }

        $cat['groups'] = !empty($cat['groups']) ? array_values(array_unique($cat['groups'])) : [];
        $cat['users'] = !empty($cat['users']) ? array_values(array_unique($cat['users'])) : [];
        $cat['users_indirect'] = !empty($cat['users_indirect']) ? array_values(array_unique($cat['users_indirect'])) : [];
    }
    unset($cat);

    return [
      'categories' => new PwgNamedArray(
          array_values($perms),
          'category',
          ['id']
      ),
      ];
}

/**
 * API method
 * Add permissions
 * @param mixed[] $params
 *    @option int[] cat_id
 *    @option int[] group_id (optional)
 *    @option int[] user_id (optional)
 *    @option bool recursive
 */
/** @param array<mixed> $params */
function ws_permissions_add(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (!empty($params['group_id'])) {
        $cat_id_param = is_array($params['cat_id']) ? $params['cat_id'] : [];
        $cat_id_param_int = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $cat_id_param);
        $cat_ids = get_uppercat_ids($cat_id_param_int);
        if ($params['recursive']) {
            $cat_ids = array_merge($cat_ids, get_subcat_ids($cat_id_param_int));
        }
        $cat_ids_str = array_map(fn ($v) => (string) $v, $cat_ids);

        $query = '
SELECT id
  FROM '. CATEGORIES_TABLE .'
  WHERE id IN ('. implode(',', $cat_ids_str) .')
    AND status = \'private\'
;';
        $private_cats = query2array($query, null, 'id');

        $inserts = [];
        $group_id_param = is_array($params['group_id']) ? $params['group_id'] : [];
        foreach ($private_cats as $cat_id) {
            foreach ($group_id_param as $group_id) {
                $inserts[] = [
                  'group_id' => $group_id,
                  'cat_id' => $cat_id,
                  ];
            }
        }

        mass_inserts(
            GROUP_ACCESS_TABLE,
            ['group_id','cat_id'],
            $inserts,
            ['ignore' => true]
        );
    }

    if (!empty($params['user_id'])) {
        if ($params['recursive']) {
            $_POST['apply_on_sub'] = true;
        }
        $cat_id_param2 = is_array($params['cat_id']) ? $params['cat_id'] : [];
        $cat_id_param2_int = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $cat_id_param2);
        $user_id_param = is_array($params['user_id']) ? $params['user_id'] : [];
        $user_id_param_int = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $user_id_param);
        add_permission_on_category($cat_id_param2_int, $user_id_param_int);
    }

    return $service->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
}

/**
 * API method
 * Removes permissions
 * @param mixed[] $params
 *    @option int[] cat_id
 *    @option int[] group_id (optional)
 *    @option int[] user_id (optional)
 */
/** @param array<mixed> $params */
function ws_permissions_remove(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $cat_id_param3 = is_array($params['cat_id']) ? $params['cat_id'] : [];
    $cat_id_param3_int = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $cat_id_param3);
    $cat_ids = get_subcat_ids($cat_id_param3_int);
    $cat_ids_str = array_map(fn ($v) => (string) $v, $cat_ids);

    $permRepo2 = \Piwigo\Core\ServiceLocator::get(\Piwigo\Permission\PermissionRepository::class);
    $cat_ids_int = array_map(fn(string $v): int => (int) $v, $cat_ids_str);

    if (!empty($params['group_id'])) {
        $group_id_rem = is_array($params['group_id']) ? $params['group_id'] : [];
        $permRepo2->deleteGroupAccess(
            array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, $group_id_rem),
            $cat_ids_int
        );
    }

    if (!empty($params['user_id'])) {
        $user_id_rem = is_array($params['user_id']) ? $params['user_id'] : [];
        $permRepo2->deleteUserAccess(
            array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, $user_id_rem),
            $cat_ids_int
        );
    }

    return $service->invoke('pwg.permissions.getList', ['cat_id' => $params['cat_id']]);
}
