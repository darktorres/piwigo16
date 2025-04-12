<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\ws_functions;

use DateMalformedStringException;
use Exception;
use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_user;
use Piwigo\inc\PwgError;
use Piwigo\inc\PwgNamedArray;
use Piwigo\inc\PwgNamedStruct;
use Piwigo\inc\PwgServer;
use Piwigo\inc\ws_functions;
use Random\RandomException;

final class pwg_users
{
    /**
     * API method
     * Returns a list of users
     * @param array{
     *     user_id?: array<int>,
     *     username?: string,
     *     status?: array<string>,
     *     min_level?: int,
     *     max_level?: int,
     *     group_id?: array<int>,
     *     per_page: int,
     *     page: int,
     *     order: string,
     *     display: array|string,
     *     filter: string,
     *     exclude?: array<int>,
     *     min_register: string,
     *     max_register: string,
     * } $params
     * @throws DateMalformedStringException
     */
    public static function ws_users_getList(
        array $params,
        PwgServer &$service
    ): PwgError|array {
        global $conf;

        if (! preg_match(PATTERN_ORDER, $params['order'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter order');
        }

        $where_clauses = ['1 = 1'];

        if (! empty($params['user_id'])) {
            $user_ids = implode(', ', $params['user_id']);
            $where_clauses[] = "u.{$conf->user_fields['id']} IN ({$user_ids})";
        }

        if (! empty($params['username'])) {
            $escaped_username = functions_mysqli::pwg_db_real_escape_string($params['username']);
            $where_clauses[] = "u.{$conf->user_fields['username']} LIKE '{$escaped_username}'"; // TODO: why no wildcard? this is the same as comparing with =
        }

        $filtered_groups = [];

        if (! empty($params['filter'])) {
            $filter_query = "SELECT id FROM `groups` WHERE name LIKE '%{$params['filter']}%';";
            $filtered_groups_res = functions_mysqli::pwg_query($filter_query);

            while ($row = functions_mysqli::pwg_db_fetch_assoc($filtered_groups_res)) {
                $filtered_groups[] = $row['id'];
            }

            $escaped_filter = functions_mysqli::pwg_db_real_escape_string($params['filter']);
            $filter_where_clause = "(u.{$conf->user_fields['username']} LIKE '%{$escaped_filter}%' OR u.{$conf->user_fields['email']} LIKE '%{$escaped_filter}%'";

            if (! empty($filtered_groups)) {
                $filter_where_clause .= 'OR ug.group_id IN (' . implode(', ', $filtered_groups) . ')';
            }

            $where_clauses[] = $filter_where_clause . ')';
        }

        if (! empty($params['min_register'])) {
            $date_tokens = explode('-', $params['min_register']);
            $min_register_year = $date_tokens[0];
            $min_register_month = $date_tokens[1] ?? 1;
            $min_register_day = $date_tokens[2] ?? 1;
            $min_date = sprintf('%u-%02u-%02u', $min_register_year, $min_register_month, $min_register_day);
            $where_clauses[] = 'ui.registration_date >= \'' . $min_date . ' 00:00:00\'';
        }

        if (! empty($params['max_register'])) {
            $max_date_tokens = explode('-', $params['max_register']);
            $max_register_year = $max_date_tokens[0];
            $max_register_month = $max_date_tokens[1] ?? 12;
            $max_register_day = $max_date_tokens[2] ?? date('t', strtotime($max_register_year . '-' . $max_register_month . '-1'));
            $max_date = sprintf('%u-%02u-%02u', $max_register_year, $max_register_month, $max_register_day);
            $where_clauses[] = 'ui.registration_date <= \'' . $max_date . ' 23:59:59\'';
        }

        if (! empty($params['status'])) {
            $params['status'] = array_intersect($params['status'], functions_mysqli::get_enums('user_infos', 'status'));

            if (count($params['status']) > 0) {
                $where_clauses[] = 'ui.status IN ("' . implode('", "', $params['status']) . '")';
            }
        }

        if (! empty($params['min_level'])) {
            if (! in_array($params['min_level'], $conf->available_permission_levels)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
            }

            $where_clauses[] = 'ui.level >= ' . $params['min_level'];
        }

        if (! empty($params['max_level'])) {
            if (! in_array($params['max_level'], $conf->available_permission_levels)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
            }

            $where_clauses[] = 'ui.level <= ' . $params['max_level'];
        }

        if (! empty($params['group_id'])) {
            $where_clauses[] = 'ug.group_id IN (' . implode(', ', $params['group_id']) . ')';
        }

        if (! empty($params['exclude'])) {
            $where_clauses[] = 'u.' . $conf->user_fields['id'] . ' NOT IN (' . implode(', ', $params['exclude']) . ')';
        }

        $display = [
            'u.' . $conf->user_fields['id'] => 'id',
        ];

        if ($params['display'] != 'none') {
            $params['display'] = array_map(trim(...), explode(',', $params['display']));

            if (in_array('all', $params['display'])) {
                $params['display'] = [
                    'username', 'email', 'status', 'level', 'groups', 'language', 'theme',
                    'nb_image_page', 'recent_period', 'expand', 'show_nb_comments', 'show_nb_hits',
                    'enabled_high', 'registration_date', 'registration_date_string',
                    'registration_date_since', 'last_visit', 'last_visit_string',
                    'last_visit_since', 'total_count',
                ];
            } elseif (in_array('basics', $params['display'])) {
                $params['display'] = array_merge($params['display'], [
                    'username', 'email', 'status', 'level', 'groups',
                ]);
            } elseif (in_array('only_id', $params['display'])) {
                $params['display'] = [];
            }

            $params['display'] = array_flip($params['display']);

            // if registration_date_string or registration_date_since is requested,
            // then registration_date is automatically added
            if (isset($params['display']['registration_date_string']) ||
                isset($params['display']['registration_date_since'])
            ) {
                $params['display']['registration_date'] = true;
            }

            // if last_visit_string or last_visit_since is requested, then
            // last_visit is automatically added
            if (isset($params['display']['last_visit_string']) ||
                isset($params['display']['last_visit_since'])
            ) {
                $params['display']['last_visit'] = true;
            }

            if (isset($params['display']['username'])) {
                $display['u.' . $conf->user_fields['username']] = 'username';
            }

            if (isset($params['display']['email'])) {
                $display['u.' . $conf->user_fields['email']] = 'email';
            }

            $ui_fields = [
                'status', 'level', 'language', 'theme', 'nb_image_page', 'recent_period', 'expand',
                'show_nb_comments', 'show_nb_hits', 'enabled_high', 'registration_date',
                'last_visit',
            ];

            foreach ($ui_fields as $field) {
                if (isset($params['display'][$field])) {
                    $display['ui.' . $field] = $field;
                }
            }
        } else {
            $params['display'] = [];
        }

        $query = <<<SQL
            SELECT DISTINCT

            SQL;

        // ADD SQL_CALC_FOUND_ROWS if display total_count is requested
        if (isset($params['display']['total_count'])) {
            $query .= 'SQL_CALC_FOUND_ROWS ';
        }

        $first = true;

        foreach ($display as $field => $name) {
            if (! $first) {
                $query .= ', ';
            } else {
                $first = false;
            }

            $query .= "{$field} AS {$name}";
        }

        if (isset($display['ui.last_visit'])) {
            if (! $first) {
                $query .= ', ';
            }

            $query .= "ui.last_visit_from_history AS last_visit_from_history\n";
        }

        $whereClause = implode(' AND ', $where_clauses);

        $query .= <<<SQL

            FROM users AS u
            INNER JOIN user_infos AS ui ON u.{$conf->user_fields['id']} = ui.user_id
            LEFT JOIN user_group AS ug ON u.{$conf->user_fields['id']} = ug.user_id
            WHERE {$whereClause}
            ORDER BY {$params['order']}

            SQL;

        if ($params['per_page'] != 0 ||
            isset($params['display']) && $params['display'] !== []
        ) {
            $offset = $params['per_page'] * $params['page'];
            $query .= <<<SQL
                LIMIT {$params['per_page']} OFFSET {$offset}

                SQL;
        }

        $query = trim($query) . ';';
        $result = functions_mysqli::pwg_query($query);
        $users = [];

        /* GET THE RESULT OF SQL_CALC_FOUND_ROWS if display total_count is requested*/
        if (isset($params['display']['total_count'])) {
            $total_count_query_result = functions_mysqli::pwg_query('SELECT FOUND_ROWS();');
            list($total_count) = functions_mysqli::pwg_db_fetch_row($total_count_query_result);
        }

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $row['id'] = intval($row['id']);

            if (isset($params['display']['groups'])) {
                $row['groups'] = []; // will be filled later
            }

            $users[$row['id']] = $row;
        }

        $users_id_arr = [];

        if (count($users) > 0) {
            if (isset($params['display']['groups'])) {
                $user_ids = implode(', ', array_keys($users));
                $query = <<<SQL
                    SELECT user_id, group_id
                    FROM user_group
                    WHERE user_id IN ({$user_ids});
                    SQL;
                $result = functions_mysqli::pwg_query($query);

                while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
                    $users[$row['user_id']]['groups'][] = intval($row['group_id']);
                }
            }

            foreach ($users as $cur_user) {
                $users_id_arr[] = $cur_user['id'];
                if (isset($params['display']['registration_date_string'])) {
                    $users[$cur_user['id']]['registration_date_string'] = functions::format_date($cur_user['registration_date'], ['day', 'month', 'year']);
                }

                if (isset($params['display']['registration_date_since'])) {
                    $users[$cur_user['id']]['registration_date_since'] = functions::time_since($cur_user['registration_date'], 'month');
                }

                if (isset($params['display']['last_visit'])) {
                    $last_visit = $cur_user['last_visit'];
                    $users[$cur_user['id']]['last_visit'] = $last_visit;

                    if (! functions_mysqli::get_boolean($cur_user['last_visit_from_history']) &&
                        empty($last_visit)
                    ) {
                        $last_visit = functions_user::get_user_last_visit_from_history($cur_user['id'], true);
                        $users[$cur_user['id']]['last_visit'] = $last_visit;
                    }

                    if (isset($params['display']['last_visit_string'])) {
                        $users[$cur_user['id']]['last_visit_string'] = functions::format_date($last_visit, ['day', 'month', 'year']);
                    }

                    if (isset($params['display']['last_visit_since'])) {
                        $users[$cur_user['id']]['last_visit_since'] = functions::time_since($last_visit, 'day');
                    }
                }
            }

            /* Removed for optimization above, dont go through the $users array for evert display
                }
              }*/
        }

        $users = functions_plugins::trigger_change('ws_users_getList', $users);

        if ($params['per_page'] == 0 &&
            empty($params['display'])
        ) {
            $method_result = $users_id_arr;
        } else {
            $method_result = [
                'paging' => new PwgNamedStruct(
                    [
                        'page' => $params['page'],
                        'per_page' => $params['per_page'],
                        'count' => count($users),
                    ]
                ),
                'users' => new PwgNamedArray(array_values($users), 'user'),
            ];
        }

        if (isset($params['display']['total_count'])) {
            $method_result['total_count'] = $total_count;
        }

        return $method_result;
    }

    /**
     * API method
     * Adds a user
     * @param array{
     *     username: string,
     *     password?: string,
     *     email?: string,
     *     password_confirm: string,
     *     pwg_token: string,
     *     send_password_by_mail: bool,
     * } $params
     * @throws Exception
     */
    public static function ws_users_add(
        array $params,
        PwgServer &$service
    ): array|bool|PwgError|string|null {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (strlen(str_replace(' ', '', $params['username'])) == 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
        }

        global $conf;

        if ($conf->double_password_type_in_admin) {
            if ($params['password'] != $params['password_confirm']) {
                return new PwgError(WS_ERR_INVALID_PARAM, functions::l10n('The passwords do not match'));
            }
        }

        $user_id = functions_user::register_user(
            $params['username'],
            $params['password'],
            $params['email'],
            false, // notify admin
            $errors,
            $params['send_password_by_mail']
        );

        if (! $user_id) {
            return new PwgError(WS_ERR_INVALID_PARAM, $errors[0]);
        }

        return $service->invoke('pwg.users.getList', [
            'user_id' => $user_id,
        ]);
    }

    /**
     * API method
     * Get a new authentication key for a user.
     * @param array{
     *     user_id: int,
     *     pwg_token: string,
     * } $params
     * @throws RandomException
     */
    public static function ws_users_getAuthKey(
        array $params,
        PwgServer &$service
    ): PwgError|array {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $authkey = functions_user::create_user_auth_key($params['user_id']);

        if ($authkey === false) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'invalid user_id');
        }

        return $authkey;
    }

    /**
     * API method
     * Deletes users
     * @param array{
     *     user_id: array<int>,
     *     pwg_token: string,
     * } $params
     */
    public static function ws_users_delete(
        array $params,
        PwgServer &$service
    ): PwgError|string {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        global $conf, $user;

        $protected_users = [
            $user['id'],
            $conf->guest_id,
            $conf->default_user_id,
            $conf->webmaster_id,
        ];

        // an admin can't delete other admin/webmaster
        if ($user['status'] == 'admin') {
            $query = <<<SQL
                SELECT user_id
                FROM user_infos
                WHERE status IN ('webmaster', 'admin');
                SQL;
            $protected_users = array_merge($protected_users, functions_mysqli::query2array($query, null, 'user_id'));
        }

        // protect some users
        $params['user_id'] = array_diff($params['user_id'], $protected_users);

        $counter = 0;

        foreach ($params['user_id'] as $user_id) {
            functions_admin::delete_user($user_id);
            $counter++;
        }

        return functions::l10n_dec(
            '%d user deleted',
            '%d users deleted',
            $counter
        );
    }

    /**
     * API method
     * Updates users
     * @param array{
     *     user_id: array<int>,
     *     username?: string,
     *     password?: string,
     *     email?: string,
     *     status?: string,
     *     level?: int,
     *     language?: string,
     *     theme?: string,
     *     nb_image_page?: int,
     *     recent_period?: int,
     *     expand?: bool,
     *     show_nb_comments?: bool,
     *     show_nb_hits?: bool,
     *     enabled_high?: bool,
     *     pwg_token: string,
     *     user_id_for_status: array,
     *     group_id: array,
     * } $params
     */
    public static function ws_users_setInfo(
        array $params,
        PwgServer &$service
    ): array|bool|PwgError|string|null {
        if (functions::get_pwg_token() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (isset($params['username']) &&
            strlen(str_replace(' ', '', $params['username'])) == 0
        ) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
        }

        global $conf, $user;

        $updates = $updates_infos = [];
        $update_status = null;

        if (count($params['user_id']) == 1) {
            if (functions_admin::get_username($params['user_id'][0]) === false) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
            }

            if (! empty($params['username'])) {
                $user_id = functions_user::get_userid($params['username']);
                if ($user_id &&
                    $user_id != $params['user_id'][0]
                ) {
                    return new PwgError(WS_ERR_INVALID_PARAM, functions::l10n('this login is already used'));
                }

                if ($params['username'] != strip_tags($params['username'])) {
                    return new PwgError(WS_ERR_INVALID_PARAM, functions::l10n('html tags are not allowed in login'));
                }

                $updates[$conf->user_fields['username']] = $params['username'];
            }

            if (! empty($params['email'])) {
                $error = functions_user::validate_mail_address($params['user_id'][0], $params['email']);

                if ($error != '') {
                    return new PwgError(WS_ERR_INVALID_PARAM, $error);
                }

                $updates[$conf->user_fields['email']] = $params['email'];
            }

            if (! empty($params['password'])) {
                if (! functions_user::is_webmaster()) {
                    $password_protected_users = [$conf->guest_id];

                    $query = <<<SQL
                        SELECT user_id
                        FROM user_infos
                        WHERE status IN ('webmaster', 'admin');
                        SQL;
                    $admin_ids = functions_mysqli::query2array($query, null, 'user_id');

                    // we add all admin+webmaster users BUT the user herself
                    $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$user['id']]));

                    if (in_array($params['user_id'][0], $password_protected_users)) {
                        return new PwgError(403, 'Only webmasters can change password of other "webmaster/admin" users');
                    }
                }

                $updates[$conf->user_fields['password']] = ($conf->password_hash)($params['password']);
            }
        }

        if (! empty($params['status'])) {
            if (in_array($params['status'], ['webmaster', 'admin']) &&
                ! functions_user::is_webmaster()
            ) {
                return new PwgError(403, 'Only webmasters can grant "webmaster/admin" status');
            }

            if (! in_array($params['status'], ['guest', 'generic', 'normal', 'admin', 'webmaster'])) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid status');
            }

            $protected_users = [
                $user['id'],
                $conf->guest_id,
                $conf->webmaster_id,
            ];

            // an admin can't change status of other admin/webmaster
            if ($user['status'] == 'admin') {
                $query = <<<SQL
                    SELECT user_id
                    FROM user_infos
                    WHERE status IN ('webmaster', 'admin');
                    SQL;
                $protected_users = array_merge($protected_users, functions_mysqli::query2array($query, null, 'user_id'));
            }

            // status update query is separated from the rest as not applying to the same
            // set of users (current, guest and webmaster can't be changed)
            $params['user_id_for_status'] = array_diff($params['user_id'], $protected_users);

            $update_status = $params['status'];
        }

        if (! empty($params['level']) ||
            $params['level'] === 0
        ) {
            if (! in_array($params['level'], $conf->available_permission_levels)) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
            }

            $updates_infos['level'] = $params['level'];
        }

        if (! empty($params['language'])) {
            if (! in_array($params['language'], array_keys(functions::get_languages()))) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid language');
            }

            $updates_infos['language'] = $params['language'];
        }

        if (! empty($params['theme'])) {
            if (! in_array($params['theme'], array_keys(functions::get_pwg_themes()))) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid theme');
            }

            $updates_infos['theme'] = $params['theme'];
        }

        if (! empty($params['nb_image_page'])) {
            $updates_infos['nb_image_page'] = $params['nb_image_page'];
        }

        if (! empty($params['recent_period']) ||
            $params['recent_period'] === 0
        ) {
            $updates_infos['recent_period'] = $params['recent_period'];
        }

        if (! empty($params['expand']) ||
            $params['expand'] === false
        ) {
            $updates_infos['expand'] = functions_mysqli::boolean_to_string($params['expand']);
        }

        if (! empty($params['show_nb_comments']) ||
            $params['show_nb_comments'] === false
        ) {
            $updates_infos['show_nb_comments'] = functions_mysqli::boolean_to_string($params['show_nb_comments']);
        }

        if (! empty($params['show_nb_hits']) ||
            $params['show_nb_hits'] === false
        ) {
            $updates_infos['show_nb_hits'] = functions_mysqli::boolean_to_string($params['show_nb_hits']);
        }

        if (! empty($params['enabled_high']) ||
            $params['enabled_high'] === false
        ) {
            $updates_infos['enabled_high'] = functions_mysqli::boolean_to_string($params['enabled_high']);
        }

        // perform updates
        functions_mysqli::single_update(
            'users',
            $updates,
            [
                $conf->user_fields['id'] => $params['user_id'][0],
            ]
        );

        if (isset($updates[$conf->user_fields['password']])) {
            functions_user::deactivate_user_auth_keys($params['user_id'][0]);
        }

        if (isset($updates[$conf->user_fields['email']])) {
            functions_user::deactivate_password_reset_key($params['user_id'][0]);
        }

        if (isset($update_status) &&
            count($params['user_id_for_status']) > 0
        ) {
            $userIdsForStatus = implode(', ', $params['user_id_for_status']);
            $query = <<<SQL
                UPDATE user_infos
                SET status = "{$update_status}"
                WHERE user_id IN ({$userIdsForStatus});
                SQL;
            functions_mysqli::pwg_query($query);

            // we delete sessions, ie disconnect, for users if status becomes "guest".
            // It's like deactivating the user.
            if ($update_status == 'guest') {
                foreach ($params['user_id_for_status'] as $user_id_for_status) {
                    functions_session::delete_user_sessions($user_id_for_status);
                }
            }
        }

        if (count($updates_infos) > 0) {
            $updates = '';

            $first = true;

            foreach ($updates_infos as $field => $value) {
                if (! $first) {
                    $updates .= ', ';
                } else {
                    $first = false;
                }

                $updates .= "{$field} = '{$value}'";
            }

            $userIds = implode(', ', $params['user_id']);
            $query = <<<SQL
                UPDATE user_infos
                SET {$updates}
                WHERE user_id IN ({$userIds});
                SQL;
            functions_mysqli::pwg_query($query);
        }

        // manage association to groups
        if (! empty($params['group_id'])) {
            $userIds = implode(', ', $params['user_id']);
            $query = <<<SQL
                DELETE FROM user_group
                WHERE user_id IN ({$userIds});
                SQL;
            functions_mysqli::pwg_query($query);

            // we remove all provided groups that do not really exist
            $groupIds = implode(', ', $params['group_id']);
            $query = <<<SQL
                SELECT id
                FROM `groups`
                WHERE id IN ({$groupIds});
                SQL;
            $group_ids = functions_mysqli::query2array($query, null, 'id');

            // if only -1 (a group id that can't exist) is in the list, then no
            // group is associated

            if (count($group_ids) > 0) {
                $inserts = [];

                foreach ($group_ids as $group_id) {
                    foreach ($params['user_id'] as $user_id) {
                        $inserts[] = [
                            'user_id' => $user_id,
                            'group_id' => $group_id,
                        ];
                    }
                }

                functions_mysqli::mass_inserts('user_group', array_keys($inserts[0]), $inserts);
            }
        }

        functions_admin::invalidate_user_cache();

        functions::pwg_activity('user', $params['user_id'], 'edit');

        return $service->invoke('pwg.users.getList', [
            'user_id' => $params['user_id'],
            'display' => 'basics,' . implode(',', array_keys($updates_infos)),
        ]);
    }

    /**
     * API method
     * Set a preferences parameter to current user
     * @param array{
     *     param: string,
     *     value: mixed,
     *     is_json: bool,
     * } $params
     */
    public static function ws_users_preferences_set(
        array $params,
        PwgServer &$service
    ): array|PwgError {
        global $user;

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $params['param'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid param name #' . $params['param'] . '#');
        }

        $value = stripslashes($params['value']);

        if ($params['is_json']) {
            $value = json_decode($value, true);
        }

        functions_user::userprefs_update_param($params['param'], $value);

        return $user['preferences'];
    }

    /**
     * API method
     * Adds a favorite image for the current user
     * @param array{
     *     image_id: int,
     * } $params
     */
    public static function ws_users_favorites_add(
        array $params,
        PwgServer &$service
    ): PwgError|true {
        global $user;

        if (functions_user::is_a_guest()) {
            return new PwgError(403, 'User must be logged in.');
        }

        // does the image really exist?
        $query = <<<SQL
            SELECT COUNT(*)
            FROM images
            WHERE id = {$params['image_id']};
            SQL;
        list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }

        functions_mysqli::single_insert(
            'favorites',
            [
                'image_id' => $params['image_id'],
                'user_id' => $user['id'],
            ],
            [
                'ignore' => true,
            ]
        );

        return true;
    }

    /**
     * API method
     * Removes a favorite image for the current user
     * @param array{
     *     image_id: int,
     * } $params
     */
    public static function ws_users_favorites_remove(
        array $params,
        PwgServer &$service
    ): PwgError|true {
        global $user;

        if (functions_user::is_a_guest()) {
            return new PwgError(403, 'User must be logged in.');
        }

        // does the image really exist?
        $query = <<<SQL
            SELECT COUNT(*)
            FROM images
            WHERE id = {$params['image_id']};
            SQL;
        list($count) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }

        $query = <<<SQL
            DELETE FROM favorites
            WHERE user_id = {$user['id']}
                AND image_id = {$params['image_id']};
            SQL;

        functions_mysqli::pwg_query($query);

        return true;
    }

    /**
     * API method
     * Returns the favorite images of the current user
     * @param array{
     *     per_page: int,
     *     page: int,
     *     order: string,
     * } $params
     */
    public static function ws_users_favorites_getList(
        array $params,
        PwgServer &$service
    ): false|array {
        global $conf, $user;

        if (functions_user::is_a_guest()) {
            return false;
        }

        functions_user::check_user_favorites();

        $order_by = ws_functions::ws_std_image_sql_order($params, 'i.');
        $order_by = empty($order_by) ? $conf->order_by : 'ORDER BY ' . $order_by;

        $sql_condition = functions_user::get_sql_condition_FandF(
            [
                'visible_images' => 'id',
            ],
            'AND'
        );

        $query = <<<SQL
            SELECT i.*
            FROM favorites
            INNER JOIN images i ON image_id = i.id
            WHERE user_id = {$user['id']}
                {$sql_condition}
            {$order_by};
            SQL;
        $images = [];
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $image = [];

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = (int) $row[$k];
                }
            }

            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $images[] = array_merge($image, ws_functions::ws_std_get_urls($row));
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
                ws_functions::ws_std_get_image_xml_attributes()
            ),
        ];
    }
}
