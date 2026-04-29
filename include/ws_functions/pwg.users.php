<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

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
 * Returns a list of users
 * @param mixed[] $params
 *    @option int[] user_id (optional)
 *    @option string username (optional)
 *    @option string[] status (optional)
 *    @option int min_level (optional)
 *    @option int max_level (optional)
 *    @option int[] group_id (optional)
 *    @option int per_page
 *    @option int page
 *    @option string order
 *    @option string display
 *    @option string filter
 *    @option int[] exclude (optional)
 *    @option string min_register
 *    @option string max_register
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_getList(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $order_str = is_scalar($params['order']) ? (string) $params['order'] : '';
    if (!preg_match(PATTERN_ORDER, $order_str)) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter order');
    }

    // Insensitive case sort order
    if (isset($params['order'])) {
        if (str_contains($order_str, 'username')) {
            $order_str = str_ireplace('username', 'LOWER(username)', $order_str);
        }
    }

    $where_clauses = ['1=1'];

    if (!empty($params['user_id'])) {
        $user_id_arr = is_array($params['user_id']) ? $params['user_id'] : [];
        $where_clauses[] = 'u.'.\Piwigo\Core\Config::userFields()['id'].' IN('. implode(',', array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $user_id_arr)) .')';
    }

    if (!empty($params['username'])) {
        $where_clauses[] = 'u.'.\Piwigo\Core\Config::userFields()['username'].' LIKE \''.pwg_db_real_escape_string(is_scalar($params['username']) ? (string) $params['username'] : '').'\'';
    }

    $filtered_groups = [];
    if (!empty($params['filter'])) {
        $filter_str = is_scalar($params['filter']) ? (string) $params['filter'] : '';
        $filter_query = 'SELECT id FROM `'. GROUPS_TABLE .'` WHERE name LIKE \'%'. pwg_db_real_escape_string($filter_str) . '%\';';
        $filtered_groups_res = pwg_query($filter_query);
        while ($row = pwg_db_fetch_assoc($filtered_groups_res)) {
            $filtered_groups[] = $row['id'];
        }
        $filter_where_clause = '('.'u.'.\Piwigo\Core\Config::userFields()['username'].' LIKE \'%'.
        pwg_db_real_escape_string($filter_str).'%\' OR '
        .'u.'.\Piwigo\Core\Config::userFields()['email'].' LIKE \'%'.
        pwg_db_real_escape_string($filter_str).'%\'';

        if (!empty($filtered_groups)) {
            $filter_where_clause .= 'OR ug.group_id IN ('. implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $filtered_groups)).')';
        }
        $where_clauses[] =  $filter_where_clause.')';
    }

    if (!empty($params['min_register'])) {
        $min_register_str = is_scalar($params['min_register']) ? (string) $params['min_register'] : '';
        if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $min_register_str)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter min_register');
        }

        $date_tokens = explode('-', $min_register_str);
        $min_register_year = $date_tokens[0];
        $min_register_month = $date_tokens[1] ?? 1;
        $min_register_day =  $date_tokens[2] ?? 1;
        $min_date = sprintf('%u-%02u-%02u', $min_register_year, $min_register_month, $min_register_day);
        $where_clauses[] = 'ui.registration_date >= \''.$min_date.' 00:00:00\'';
    }

    if (!empty($params['max_register'])) {
        $max_register_str = is_scalar($params['max_register']) ? (string) $params['max_register'] : '';
        if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $max_register_str)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter max_register');
        }

        $max_date_tokens = explode('-', $max_register_str);
        $max_register_year = $max_date_tokens[0];
        $max_register_month = $max_date_tokens[1] ?? '12';
        $max_register_day = $max_date_tokens[2] ?? date('t', strtotime($max_register_year.'-'.$max_register_month.'-1') ?: null);
        $max_date = sprintf('%u-%02u-%02u', $max_register_year, $max_register_month, $max_register_day);
        $where_clauses[] = 'ui.registration_date <= \''.$max_date.' 23:59:59\'';
    }

    if (!empty($params['status'])) {
        $status_arr = is_array($params['status']) ? $params['status'] : [];
        $status_arr = array_intersect($status_arr, get_enums(USER_INFOS_TABLE, 'status'));
        if (count($status_arr) > 0) {
            $where_clauses[] = 'ui.status IN("'. implode('","', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $status_arr)) .'")';
        }
    }

    if (!empty($params['min_level'])) {
        if (!in_array($params['min_level'], \Piwigo\Core\Config::availablePermissionLevels())) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
        }
        $where_clauses[] = 'ui.level >= '.(is_numeric($params['min_level']) ? (int) $params['min_level'] : 0);
    }

    if (!empty($params['max_level'])) {
        if (!in_array($params['max_level'], \Piwigo\Core\Config::availablePermissionLevels())) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
        }
        $where_clauses[] = 'ui.level <= '.(is_numeric($params['max_level']) ? (int) $params['max_level'] : 0);
    }

    if (!empty($params['group_id'])) {
        $group_id_arr = is_array($params['group_id']) ? $params['group_id'] : [];
        $where_clauses[] = 'ug.group_id IN('. implode(',', array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $group_id_arr)) .')';
    }

    if (!empty($params['exclude'])) {
        $exclude_arr = is_array($params['exclude']) ? $params['exclude'] : [];
        $where_clauses[] = 'u.'.\Piwigo\Core\Config::userFields()['id'].' NOT IN('. implode(',', array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $exclude_arr)) .')';
    }

    $display = ['u.'.\Piwigo\Core\Config::userFields()['id'] => 'id'];

    $display_param = is_scalar($params['display']) ? (string) $params['display'] : 'none';
    if ($display_param != 'none') {
        $params['display'] = array_map(trim(...), explode(',', $display_param));

        if (in_array('all', $params['display'])) {
            $params['display'] = [
              'username','email','status','level','groups','language','theme',
              'nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits',
              'enabled_high','registration_date','registration_date_string',
              'registration_date_since', 'last_visit', 'last_visit_string',
              'last_visit_since', 'total_count',
              ];
        } elseif (in_array('basics', $params['display'])) {
            $params['display'] = array_merge($params['display'], [
              'username','email','status','level','groups',
              ]);
        } elseif (in_array('only_id', $params['display'])) {
            $params['display'] = [];
        }
        $params['display'] = array_flip($params['display']);

        // if registration_date_string or registration_date_since is requested,
        // then registration_date is automatically added
        if (isset($params['display']['registration_date_string']) or isset($params['display']['registration_date_since'])) {
            $params['display']['registration_date'] = true;
        }

        // if last_visit_string or last_visit_since is requested, then
        // last_visit is automatically added
        if (isset($params['display']['last_visit_string']) or isset($params['display']['last_visit_since'])) {
            $params['display']['last_visit'] = true;
        }

        if (isset($params['display']['username'])) {
            $display['u.'.\Piwigo\Core\Config::userFields()['username']] = 'username';
        }
        if (isset($params['display']['email'])) {
            $display['u.'.\Piwigo\Core\Config::userFields()['email']] = 'email';
        }

        $ui_fields = [
          'status','level','language','theme','nb_image_page','recent_period','expand',
          'show_nb_comments','show_nb_hits','enabled_high','registration_date',
          'last_visit',
          ];
        foreach ($ui_fields as $field) {
            if (isset($params['display'][$field])) {
                $display['ui.'.$field] = $field;
            }
        }
    } else {
        $params['display'] = [];
    }

    $query = '
SELECT DISTINCT ';

    // ADD SQL_CALC_FOUND_ROWS if display total_count is requested
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
        $query .= $field .' AS '. $name;
    }

    if (isset($display['ui.last_visit'])) {
        $query .= ', ui.last_visit_from_history AS last_visit_from_history';
    }
    $query .= '
  FROM '. USERS_TABLE .' AS u
    INNER JOIN '. USER_INFOS_TABLE .' AS ui
      ON u.'. \Piwigo\Core\Config::userFields()['id'] .' = ui.user_id
    LEFT JOIN '. USER_GROUP_TABLE .' AS ug
      ON u.'. \Piwigo\Core\Config::userFields()['id'] .' = ug.user_id
  WHERE
    '. implode(' AND ', $where_clauses) .'
  ORDER BY '. $order_str;
    $per_page = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
    $page = is_numeric($params['page']) ? (int) $params['page'] : 0;
    if ($per_page != 0 || !empty($params['display'])) {
        $query .= '
    LIMIT '. $per_page.'
    OFFSET '. ($per_page * $page) .';
    ;';
    }
    $users = [];
    $result = pwg_query($query);
    $total_count = 0;

    /* GET THE RESULT OF SQL_CALC_FOUND_ROWS if display total_count is requested*/
    if (isset($params['display']['total_count'])) {
        $total_count_query_result = pwg_query('SELECT FOUND_ROWS();');
        [$total_count] = pwg_db_fetch_row($total_count_query_result) ?? [null];
        $total_count = (int)$total_count;
    }
    while ($row = pwg_db_fetch_assoc($result)) {
        $row['id'] = intval($row['id']);
        if (isset($params['display']['groups'])) {
            $row['groups'] = []; // will be filled later
        }
        $users[ $row['id'] ] = $row;
    }

    $users_id_arr = [];
    if (count($users) > 0) {
        if (isset($params['display']['groups'])) {
            $query = '
  SELECT user_id, group_id
  FROM '. USER_GROUP_TABLE .'
  WHERE user_id IN ('. implode(',', array_keys($users)) .')
;';
            $result = pwg_query($query);
            while ($row = pwg_db_fetch_assoc($result)) {
                $grp_uid = is_numeric($row['user_id']) ? (int) $row['user_id'] : 0;
                /** @var array<string, mixed> $users[$grp_uid] */
                if (!isset($users[$grp_uid]['groups']) || !is_array($users[$grp_uid]['groups'])) {
                    $users[$grp_uid]['groups'] = [];
                }
                $users[$grp_uid]['groups'][] = intval($row['group_id']);
            }
        }
        foreach ($users as $cur_user) {
            /** @var array<string, mixed> $cur_user */
            $cur_uid = is_numeric($cur_user['id'] ?? null) ? (int) $cur_user['id'] : 0;
            $users_id_arr[] = $cur_uid;
            if (isset($params['display']['registration_date_string'])) {
                $reg_date = is_scalar($cur_user['registration_date'] ?? null) ? $cur_user['registration_date'] : null;
                $users[$cur_uid]['registration_date_string'] = format_date($reg_date, ['day', 'month', 'year']);
            }
            if (isset($params['display']['registration_date_since'])) {
                $reg_date2 = is_scalar($cur_user['registration_date'] ?? null) ? $cur_user['registration_date'] : null;
                $users[$cur_uid]['registration_date_since'] = time_since($reg_date2, 'month');
            }
            if (isset($params['display']['last_visit'])) {
                $last_visit = $cur_user['last_visit'] ?? null;
                $users[$cur_uid]['last_visit'] = $last_visit;

                if (!get_boolean($cur_user['last_visit_from_history'] ?? null) and empty($last_visit)) {
                    $last_visit = get_user_last_visit_from_history($cur_uid, true);
                    $users[$cur_uid]['last_visit'] = $last_visit;
                }

                if (isset($params['display']['last_visit_string'])) {
                    $lv_str = is_scalar($last_visit) ? $last_visit : null;
                    $users[$cur_uid]['last_visit_string'] = format_date($lv_str, ['day', 'month', 'year']);
                }

                if (isset($params['display']['last_visit_since'])) {
                    $lv_since = is_scalar($last_visit) ? $last_visit : null;
                    $users[$cur_uid]['last_visit_since'] = time_since($lv_since, 'day');
                }
            }
        }

        /* Removed for optimization above, dont go through the $users array for evert display
        if (isset($params['display']['registration_date_string']))
        {
          foreach ($users as $cur_user)
          {
            $users[$cur_user['id']]['registration_date_string'] = format_date($cur_user['registration_date'], array('day', 'month', 'year'));
          }
        }

        if (isset($params['display']['registration_date_since']))
        {
          foreach ($users as $cur_user)
          {
            $users[ $cur_user['id'] ]['registration_date_since'] = time_since($cur_user['registration_date'], 'month');
          }
        }

        if (isset($params['display']['last_visit']))
        {
          foreach ($users as $cur_user)
          {
            $last_visit = $cur_user['last_visit'];
            $users[ $cur_user['id'] ]['last_visit'] = $last_visit;

            if (!get_boolean($cur_user['last_visit_from_history']) and empty($last_visit))
            {
              $last_visit = get_user_last_visit_from_history($cur_user['id'], true);
              $users[ $cur_user['id'] ]['last_visit'] = $last_visit;
            }

            if (isset($params['display']['last_visit_string']))
            {
              $users[ $cur_user['id'] ]['last_visit_string'] = format_date($last_visit, array('day', 'month', 'year'));
            }

            if (isset($params['display']['last_visit_since']))
            {
              $users[ $cur_user['id'] ]['last_visit_since'] = time_since($last_visit, 'day');
            }
          }*/
    }
    $users_changed = trigger_change('ws_users_getList', $users);
    /** @var array<mixed> $users */
    $users = is_array($users_changed) ? $users_changed : $users;
    if ($per_page == 0 && empty($params['display'])) {
        $method_result = $users_id_arr;
    } else {
        $method_result = [
          'paging' => new PwgNamedStruct(
              [
              'page' => $page,
              'per_page' => $per_page,
              'count' => count($users),
              'total_count' => $total_count,
              ]
          ),
          'users' => new PwgNamedArray(array_values($users), 'user'),
        ];
    }
    // deprecated: kept for retrocompatibility
    if (isset($params['display']['total_count'])) {
        $method_result['total_count'] = $total_count;
    }
    return $method_result;
}

/**
 * API method
 * Adds a user
 * @param mixed[] $params
 *    @option string username
 *    @option string password (optional)
 *    @option string email (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_add(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (strlen(str_replace(' ', '', $params['username'])) == 0) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
    }

    if (\Piwigo\Core\Config::doublePasswordTypeInAdmin()) {
        if ($params['password'] != $params['password_confirm']) {
            return new PwgError(WS_ERR_INVALID_PARAM, l10n('The passwords do not match'));
        }
    }

    if ($params['auto_password']) {
        $params['password'] = generate_key(random_int(15, 20));
    }

    $errors = [];
    $user_id = register_user(
        $params['username'],
        $params['password'],
        $params['email'],
        false, // notify admin
        $errors,
        false // $params['send_password_by_mail']
    );

    if (!$user_id) {
        return new PwgError(WS_ERR_INVALID_PARAM, $errors[0]);
    }

    return $service->invoke('pwg.users.getList', ['user_id' => $user_id]);
}

/**
 * API method
 * Get a new authentication key for a user.
 * @param mixed[] $params
 *    @option int[] user_id
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_getAuthKey(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $authkey = create_user_auth_key($params['user_id']);

    if ($authkey === false) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'invalid user_id');
    }

    return $authkey;
}

/**
 * API method
 * Deletes users
 * @param mixed[] $params
 *    @option int[] user_id
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_delete(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|string
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    global $user;

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $protected_users = [
      $user['id'],
      \Piwigo\Core\Config::guestId(),
      \Piwigo\Core\Config::defaultUserId(),
      \Piwigo\Core\Config::webmasterId(),
      ];

    // an admin can't delete other admin/webmaster
    if ('admin' == $user['status']) {
        $query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE status IN (\'webmaster\', \'admin\')
;';
        $protected_users = array_merge($protected_users, query2array($query, null, 'user_id'));
    }

    // protect some users
    $params['user_id'] = array_diff($params['user_id'], $protected_users);

    $counter = 0;

    foreach ($params['user_id'] as $user_id) {
        delete_user($user_id);
        $counter++;
    }

    return l10n_dec(
        '%d user deleted',
        '%d users deleted',
        $counter
    );
}

/**
 * API method
 * Updates users
 * @param mixed[] $params
 *    @option int[] user_id
 *    @option string username (optional)
 *    @option string password (optional)
 *    @option string email (optional)
 *    @option string status (optional)
 *    @option int level (optional)
 *    @option string language (optional)
 *    @option string theme (optional)
 *    @option int nb_image_page (optional)
 *    @option int recent_period (optional)
 *    @option bool expand (optional)
 *    @option bool show_nb_comments (optional)
 *    @option bool show_nb_hits (optional)
 *    @option bool enabled_high (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_setInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $updated_users = check_and_save_user_infos($params);

    if (isset($updated_users['error'])) {
        return new PwgError($updated_users[ 'error' ][ 'code' ], $updated_users[ 'error' ][ 'message' ]);
    }

    return $service->invoke('pwg.users.getList', [
      'user_id' => $updated_users['user_id'],
      'display' => 'basics,'.implode(',', array_keys($updated_users['infos'])),
    ]);
}

/**
 * API method
 * Update user
 * @since 16
 * @param mixed[] $params
 *    @option string email (optional)
 *    @option int nb_image_page (optional)
 *    @option string theme (optional)
 *    @option string language (optional)
 *    @option int recent_period (optional)
 *    @option bool expand (optional)
 *    @option bool show_nb_comments (optional)
 *    @option bool show_nb_hits (optional)
 *    @option string password (optional)
 *    @option string new_password (optional)
 *    @option string conf_new_password (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_setMyInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (is_a_guest()) {
        return new PwgError(401, 'Access Denied');
    }

    global $user;

    // ACTIVATE_COMMENTS
    if (!\Piwigo\Core\Config::activateComments()) {
        unset($params['show_nb_comments']);
    }

    // ALLOW_USER_CUSTOMIZATION
    if (!\Piwigo\Core\Config::allowUserCustomization()) {
        unset(
            $params['nb_image_page'],
            $params['theme'],
            $params['language'],
            $params['recent_period'],
            $params['expand'],
            $params['show_nb_comments'],
            $params['show_nb_hits']
        );
    }

    // SPECIAL_USER
    $special_user = in_array($user['id'], [\Piwigo\Core\Config::guestId(), \Piwigo\Core\Config::defaultUserId()]);
    if ($special_user) {
        unset(
            $params['password'],
            $params['theme'],
            $params['language']
        );
    }

    if (!empty($params['password'])) {
        if ($params['new_password'] != $params['conf_new_password']) {
            return new PwgError(403, l10n('The passwords do not match'));
        }

        $query = '
SELECT '.\Piwigo\Core\Config::userFields()['password'].' AS password
  FROM '.USERS_TABLE.'
  WHERE '.\Piwigo\Core\Config::userFields()['id'].' = \''.$user['id'].'\'
;';
        [$current_password] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

        if (!\Piwigo\Core\Config::passwordVerify()($params['password'], $current_password)) {
            return new PwgError(403, l10n('Current password is wrong'));
        }

        $params['password'] = $params['new_password'];
    }


    // Unset admin field also new and conf password
    unset(
        $params['new_password'],
        $params['conf_new_password'],
        $params['username'],
        $params['status'],
        $params['level'],
        $params['group_id'],
        $params['enabled_high']
    );

    $params['user_id'] = [$user['id']];
    $updated_users = check_and_save_user_infos($params);

    if (isset($updated_users['error'])) {
        return new PwgError($updated_users[ 'error' ][ 'code' ], $updated_users[ 'error' ][ 'message' ]);
    }

    return l10n('Your changes have been applied.');
}

/**
 * API method
 * Set a preferences parameter to current user
 * @since 13
 * @param mixed[] $params
 *    @option string param
 *    @option string|mixed value
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_preferences_set(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    global $user;

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $params['param'])) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid param name #'.$params['param'].'#');
    }

    $value = stripslashes((string) $params['value']);
    if ($params['is_json']) {
        $value = json_decode($value, true);
    }

    userprefs_update_param($params['param'], $value);

    return $user['preferences'];
}

/**
 * API method
 * Adds a favorite image for the current user
 * @param mixed[] $params
 *    @option int image_id
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_favorites_add(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|true
{
    global $user;

    if (is_a_guest()) {
        return new PwgError(403, 'User must be logged in.');
    }

    // does the image really exist?
    $query = '
SELECT COUNT(*)
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $params['image_id'] .'
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count == 0) {
        return new PwgError(404, 'image_id not found');
    }

    single_insert(
        FAVORITES_TABLE,
        [
        'image_id' => $params['image_id'],
        'user_id' => $user['id'],
    ],
        ['ignore' => true]
    );

    return true;
}

/**
 * API method
 * Removes a favorite image for the current user
 * @param mixed[] $params
 *    @option int image_id
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_favorites_remove(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|true
{
    global $user;

    if (is_a_guest()) {
        return new PwgError(403, 'User must be logged in.');
    }

    // does the image really exist?
    $query = '
SELECT COUNT(*)
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $params['image_id'] .'
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if ($count == 0) {
        return new PwgError(404, 'image_id not found');
    }

    $query = '
DELETE
  FROM '.FAVORITES_TABLE.'
  WHERE user_id = '.$user['id'].'
    AND image_id = '.$params['image_id'].'
;';

    pwg_query($query);

    return true;
}

/**
 * API method
 * Returns the favorite images of the current user
 * @param mixed[] $params
 *    @option int per_page
 *    @option int page
 *    @option string order
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>|false
 */
function ws_users_favorites_getList(array $params, \Piwigo\Ws\PwgServer &$service): false|array
{
    global $user;

    if (is_a_guest()) {
        return false;
    }

    check_user_favorites();

    $order_by = ws_std_image_sql_order($params, 'i.');
    $order_by = empty($order_by) ? \Piwigo\Core\Config::orderBy() : 'ORDER BY '.$order_by;

    $query = '
SELECT
    i.*
  FROM '.FAVORITES_TABLE.'
    INNER JOIN '.IMAGES_TABLE.' i ON image_id = i.id
  WHERE user_id = '.$user['id'].'
'.get_sql_condition_FandF(
        [
          'visible_images' => 'id',
          ],
        'AND'
    ).'
    '.$order_by.'
;';
    $images = [];
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result)) {
        $image = [];

        foreach (['id', 'width', 'height', 'hit'] as $k) {
            if (isset($row[$k])) {
                $image[$k] = (int)$row[$k];
            }
        }

        foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
            $image[$k] = $row[$k];
        }

        $images[] = array_merge($image, ws_std_get_urls($row));
    }

    $count = count($images);
    $images = array_slice($images, $params['per_page'] * $params['page'], $params['per_page']);

    return [
      'paging' => new PwgNamedStruct(
          [
          'page' => $params['page'],
          'per_page' => $params['per_page'],
          'count' => $count,
      ]
      ),
      'images' => new PwgNamedArray(
          $images,
          'image',
          ws_std_get_image_xml_attributes()
      ),
     ];
}

/**
 * API method
 * Returns the reset password link of the current user
 * @since 15
 * @param mixed[] $params
 *    @option int user_id
 *    @option string pwg_token
 *    @option boolean send_by_mail
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_users_generate_password_link(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    global $user;
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    // check if user exist
    if (get_username($params['user_id']) === false) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
    }

    $user_lost = getuserdata($params['user_id']);
    if ($user_lost === false) {
        return new PwgError(404, 'User not found');
    }

    // Cannot perform this action for a guest or generic user
    if (is_a_guest($user_lost['status']) or is_generic($user_lost['status'])) {
        return new PwgError(403, 'Password reset is not allowed for this user');
    }

    // Only webmaster can perform this action for another webmaster
    if ('admin' === $user['status'] && 'webmaster' === $user_lost['status']) {
        return new PwgError(403, 'You cannot perform this action');
    }

    $first_login = has_already_logged_in($params['user_id']);
    $send_by_mail_response = null;
    $lang_to_use = $first_login ? get_default_language() : $user_lost['language'];

    switch_lang_to($lang_to_use);
    $generate_link = generate_password_link($params['user_id'], $first_login);

    if ($params['send_by_mail'] and !empty($user_lost['email'])) {
        if ($first_login) {
            $email_params = pwg_generate_set_password_mail($user_lost['username'], $generate_link['password_link'], \Piwigo\Core\Config::galleryTitle(), $generate_link['time_validation']);
        } else {
            $email_params = pwg_generate_reset_password_mail($user_lost['username'], $generate_link['password_link'], \Piwigo\Core\Config::galleryTitle(), $generate_link['time_validation']);
        }
        // Here we remove the display of errors because they prevent the response from being parsed
        if (@pwg_mail($user_lost['email'], $email_params)) {
            $send_by_mail_response = 'Mail sent at : ' . $user_lost['email'];
        } else {
            $send_by_mail_response = false;
        }
    }
    switch_lang_back();

    return [
      'generated_link' => $generate_link['password_link'],
      'send_by_mail' => $send_by_mail_response,
      'time_validation' => $generate_link['time_validation'],
    ];
}

/**
 * API method
 * Set a user as the main user
 * @since 15
 * @param mixed[] $params
 *    @option int user_id
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_set_main_user(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|string
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    // check if not webmaster
    if (!is_webmaster()) {
        return new PwgError(403, 'You cannot perform this action');
    }

    //check pwg_token
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    // checl if user exist
    if (get_username($params['user_id']) === false) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
    }

    $new_main_user = getuserdata($params['user_id']);
    if ($new_main_user === false) {
        return new PwgError(404, 'User not found');
    }

    // check if the user to set as main user is not webmaster
    if ('webmaster' !== $new_main_user['status']) {
        return new PwgError(403, 'This user cannot become a main user because he is not a webmaster.');
    }

    conf_update_param('webmaster_id', $params['user_id']);
    return 'The main user has been changed.';
}

/**
 * API method
 * Create a new api key for the current user
 * @since 15
 * @param mixed[] $params
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_create_api_key(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    global $user, $logger;

    if (is_a_guest() or !connected_with_pwg_ui()) {
        return new PwgError(401, 'Acces Denied');
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if ($params['duration'] < 1 or $params['duration'] > 999999) {
        return new PwgError(400, 'Invalid duration max days is 999999');
    }

    if (strlen((string) $params['key_name']) > 100) {
        return new PwgError(400, 'Key name is too long');
    }

    $key_name = pwg_db_real_escape_string($params['key_name']);
    $duration = 0 == $params['duration'] ? 1 : $params['duration'];

    $secret = create_api_key($user['id'], $duration, $key_name);

    $logger->info('[api_key][user_id='.$user['id'].'][action=create][key_name='.$params['key_name'].']');

    return $secret;
}

/**
 * API method
 * Revoke a api key for the current user
 * @since 15
 * @param mixed[] $params
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_revoke_api_key(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    global $user, $logger;

    if (is_a_guest() or !connected_with_pwg_ui()) {
        return new PwgError(401, 'Acces Denied');
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, l10n('Invalid security token'));
    }

    if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', (string) $params['pkid'])) {
        return new PwgError(403, l10n('Invalid pkid format'));
    }

    $revoked_key = revoke_api_key($user['id'], $params['pkid']);

    if (true !== $revoked_key) {
        return new PwgError(403, is_string($revoked_key) ? $revoked_key : '');
    }

    $logger->info('[api_key][user_id='.$user['id'].'][action=revoke][pkid='.$params['pkid'].']');

    return l10n('API Key has been successfully revoked.');
}

/**
 * API method
 * Edit a api key for the current user
 * @since 15
 * @param mixed[] $params
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_edit_api_key(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    global $user, $logger;

    if (is_a_guest()) {
        return new PwgError(401, 'Acces Denied');
    }

    if (!connected_with_pwg_ui()) {
        return new PwgError(401, 'Acces Denied');
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, l10n('Invalid security token'));
    }

    if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', (string) $params['pkid'])) {
        return new PwgError(403, l10n('Invalid pkid format'));
    }

    $key_name = pwg_db_real_escape_string($params['key_name']);
    $edited_key = edit_api_key($user['id'], $params['pkid'], $key_name);

    if (true !== $edited_key) {
        return new PwgError(403, $edited_key);
    }

    $logger->info('[api_key][user_id='.$user['id'].'][action=edit][pkid='.$params['pkid'].'][new_name='.$key_name.']');

    return l10n('API Key has been successfully edited.');
}

/**
 * API method
 * Get all api key for the current user
 * @since 15
 * @param mixed[] $params
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_get_api_key(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    global $user;

    if (is_a_guest()) {
        return new PwgError(401, 'Acces Denied');
    }

    if (!connected_with_pwg_ui()) {
        return new PwgError(401, 'Acces Denied');
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $api_keys = get_api_key($user['id']);

    return $api_keys ?: new PwgError(404, l10n('No API key found'));
}
