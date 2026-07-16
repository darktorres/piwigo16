<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\Tables;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;

/**
 * API method
 * Returns permissions
 *
 * @param array{cat_id?: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, ...} $params
 *   all three keys: WS_PARAM_OPTIONAL with no 'default' key -- may be
 *   entirely absent; FORCE_ARRAY always coerces to a list of positive
 *   ints when present.
 * @return PwgError|array{categories: PwgNamedArray}
 */
function ws_permissions_getList(array $params, PwgServer &$service): PwgError|array
{
    $my_params = array_filter(
        ['cat_id', 'group_id', 'user_id'],
        static fn (string $key): bool => array_key_exists($key, $params)
    );
    if (count($my_params) > 1) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Too many parameters, provide cat_id OR user_id OR group_id');
    }

    $cat_filter = '';
    if (! empty($params['cat_id'])) {
        $cat_filter = 'WHERE cat_id IN(' . implode(',', $params['cat_id']) . ')';
    }

    $perms = [];

    // direct users
    $query = '
SELECT user_id, cat_id
  FROM ' . Tables::userAccess() . '
  ' . $cat_filter . '
;';
    $result = pwg_query($query);

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if (! isset($row['cat_id']) || ! is_numeric($row['cat_id'])) {
            continue;
        }
        $cat_id = (int) $row['cat_id'];
        if (! isset($perms[$cat_id])) {
            $perms[$cat_id]['id'] = $cat_id;
        }
        $perms[$cat_id]['users'][] = intval($row['user_id']);
    }

    // indirect users
    $query = '
SELECT ug.user_id, ga.cat_id
  FROM ' . Tables::userGroup() . ' AS ug
    INNER JOIN ' . Tables::groupAccess() . ' AS ga
    ON ug.group_id = ga.group_id
  ' . $cat_filter . '
;';
    $result = pwg_query($query);

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if (! isset($row['cat_id']) || ! is_numeric($row['cat_id'])) {
            continue;
        }
        $cat_id = (int) $row['cat_id'];
        if (! isset($perms[$cat_id])) {
            $perms[$cat_id]['id'] = $cat_id;
        }
        $perms[$cat_id]['users_indirect'][] = intval($row['user_id']);
    }

    // groups
    $query = '
SELECT group_id, cat_id
  FROM ' . Tables::groupAccess() . '
  ' . $cat_filter . '
;';
    $result = pwg_query($query);

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if (! isset($row['cat_id']) || ! is_numeric($row['cat_id'])) {
            continue;
        }
        $cat_id = (int) $row['cat_id'];
        if (! isset($perms[$cat_id])) {
            $perms[$cat_id]['id'] = $cat_id;
        }
        $perms[$cat_id]['groups'][] = intval($row['group_id']);
    }

    // filter by group and user
    foreach ($perms as $cat_id => &$cat) {
        if (isset($params['group_id'])) {
            if (empty($cat['groups']) or count(array_intersect($cat['groups'], $params['group_id'])) == 0) {
                unset($perms[$cat_id]);
                continue;
            }
        }
        if (isset($params['user_id'])) {
            if (
                (empty($cat['users_indirect']) or count(array_intersect($cat['users_indirect'], $params['user_id'])) == 0)
                and (empty($cat['users']) or count(array_intersect($cat['users'], $params['user_id'])) == 0)
            ) {
                unset($perms[$cat_id]);
                continue;
            }
        }

        $cat['groups'] = ! empty($cat['groups']) ? array_values(array_unique($cat['groups'])) : [];
        $cat['users'] = ! empty($cat['users']) ? array_values(array_unique($cat['users'])) : [];
        $cat['users_indirect'] = ! empty($cat['users_indirect']) ? array_values(array_unique($cat['users_indirect'])) : [];
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
 *
 * @param array{cat_id: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, recursive: bool, pwg_token: string, ...} $params
 *   cat_id: no 'default' key -- mandatory, always present, FORCE_ARRAY
 *   always coerces to a list of positive ints. group_id/user_id:
 *   WS_PARAM_OPTIONAL with no 'default' key -- may be entirely absent,
 *   same FORCE_ARRAY coercion when present. recursive: non-null bool
 *   default, WS_TYPE_BOOL -- always present. pwg_token: no 'default'
 *   key -- mandatory, always present.
 * @return mixed PwgError, or the result of the pwg.permissions.getList invocation
 */
function ws_permissions_add(array $params, PwgServer &$service): mixed
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if (! empty($params['group_id'])) {
        $cat_ids = get_uppercat_ids($params['cat_id']);
        if ($params['recursive']) {
            $cat_ids = array_merge($cat_ids, get_subcat_ids($params['cat_id']));
        }

        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $cat_ids) . ')
    AND status = \'private\'
;';
        $private_cats = query2array($query, null, 'id');

        $inserts = [];
        foreach ($private_cats as $cat_id) {
            foreach ($params['group_id'] as $group_id) {
                $inserts[] = [
                    'group_id' => $group_id,
                    'cat_id' => $cat_id,
                ];
            }
        }

        mass_inserts(
            Tables::groupAccess(),
            ['group_id', 'cat_id'],
            $inserts,
            [
                'ignore' => true,
            ]
        );
    }

    if (! empty($params['user_id'])) {
        if ($params['recursive']) {
            $_POST['apply_on_sub'] = true;
        }
        add_permission_on_category($params['cat_id'], $params['user_id']);
    }

    return $service->invoke('pwg.permissions.getList', [
        'cat_id' => $params['cat_id'],
    ]);
}

/**
 * API method
 * Removes permissions
 *
 * @param array{cat_id: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, pwg_token: string, ...} $params
 *   cat_id/pwg_token: no 'default' key -- mandatory, always present,
 *   FORCE_ARRAY always coerces cat_id to a list of positive ints.
 *   group_id/user_id: WS_PARAM_OPTIONAL with no 'default' key -- may be
 *   entirely absent, same FORCE_ARRAY coercion when present.
 * @return mixed PwgError, or the result of the pwg.permissions.getList invocation
 */
function ws_permissions_remove(array $params, PwgServer &$service): mixed
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $cat_ids = get_subcat_ids($params['cat_id']);

    if (! empty($params['group_id'])) {
        $query = '
DELETE
  FROM ' . Tables::groupAccess() . '
  WHERE group_id IN (' . implode(',', $params['group_id']) . ')
    AND cat_id IN (' . implode(',', $cat_ids) . ')
;';
        pwg_query($query);
    }

    if (! empty($params['user_id'])) {
        $query = '
DELETE
  FROM ' . Tables::userAccess() . '
  WHERE user_id IN (' . implode(',', $params['user_id']) . ')
    AND cat_id IN (' . implode(',', $cat_ids) . ')
;';
        pwg_query($query);
    }

    return $service->invoke('pwg.permissions.getList', [
        'cat_id' => $params['cat_id'],
    ]);
}
