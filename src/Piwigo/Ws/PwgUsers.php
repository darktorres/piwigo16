<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Logger;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * P23 batch 8e-6: relocated from include/ws_functions/pwg.users.php.
 * `pwg.users.*` WS methods (16 registrations) -- registered via callable
 * arrays in include/ws_default_methods.inc.php.
 */
final class PwgUsers
{
    private static function userService(): UserService
    {
        return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()), new MailService(), new ActivityService(new ActivityRepository(DbConnection::build())));
    }

    /**
     * API method
     * Returns a list of users
     *
     * @param array{user_id?: array<int, int>, username?: string, status?: array<int, string>, min_level: int, group_id?: array<int, int>, per_page: int, page: int, order: string, exclude?: array<int, int>, display: string, filter?: string, min_register?: string, max_register?: string, ...} $params
     *   user_id/status/group_id/exclude: WsParamFlag::OPTIONAL with no 'default'
     *   key -- may be entirely absent; FORCE_ARRAY (user_id/group_id/exclude)
     *   always coerces to a list when present. username/filter/min_register/
     *   max_register: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent, no 'type' flag so plain string when present.
     *   min_level/per_page/page: non-null default, WsParamType::INT|WsParamType::POSITIVE
     *   -- always present, always int. order/display: non-null string
     *   defaults, no 'type' flag -- always present, always string.
     *   max_level: not a registered param at all (checked in the body via
     *   $params['max_level'] but absent from this method's ws.php signature)
     *   -- reachable only if a client sends an unregistered extra GET/POST
     *   key, covered by the shape's open tail, never explicitly typed.
     * @return PwgError|array<int|string, mixed>
     */
    public static function getList(array $params, PwgServer &$service): PwgError|array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // $conf['user_fields'] maps generic field names to table-specific DB
        // column names (see include/functions_user.inc.php for the same
        // pattern); extracted once here since this function reads id/username/
        // email repeatedly below.
        $user_fields = $conf['user_fields'];
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $user_field_id = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';
        $user_field_username = is_string($user_fields['username'] ?? null) ? $user_fields['username'] : 'username';
        $user_field_email = is_string($user_fields['email'] ?? null) ? $user_fields['email'] : 'email';

        $available_permission_levels = $conf['available_permission_levels'];
        $available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];

        if (! (bool) preg_match(ValidationPattern::ORDER, $params['order'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        // Insensitive case sort order
        if (str_contains($params['order'], 'username')) {
            $params['order'] = str_ireplace('username', 'LOWER(username)', $params['order']);
        }

        $where_clauses = ['1=1'];

        if (! empty($params['user_id'])) {
            $where_clauses[] = 'u.' . $user_field_id . ' IN(' . implode(',', $params['user_id']) . ')';
        }

        if (! empty($params['username'])) {
            $where_clauses[] = 'u.' . $user_field_username . ' LIKE \'' . pwg_db_real_escape_string($params['username']) . '\'';
        }

        $filtered_groups = [];
        if (! empty($params['filter'])) {
            $filter_query = 'SELECT id FROM `' . Tables::groups() . '` WHERE name LIKE \'%' . pwg_db_real_escape_string($params['filter']) . '%\';';
            $filtered_groups_res = pwg_query($filter_query);
            while ((bool) ($row = pwg_db_fetch_assoc($filtered_groups_res))) {
                $filtered_groups[] = $row['id'];
            }
            $filter_where_clause = '(u.' . $user_field_username . ' LIKE \'%' .
            pwg_db_real_escape_string($params['filter']) . '%\' OR '
            . 'u.' . $user_field_email . ' LIKE \'%' .
            pwg_db_real_escape_string($params['filter']) . '%\'';

            if (! empty($filtered_groups)) {
                $filter_where_clause .= 'OR ug.group_id IN (' . implode(',', $filtered_groups) . ')';
            }
            $where_clauses[] = $filter_where_clause . ')';
        }

        if (! empty($params['min_register'])) {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $params['min_register'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter min_register');
            }

            $date_tokens = explode('-', $params['min_register']);
            $min_register_year = $date_tokens[0];
            $min_register_month = $date_tokens[1] ?? 1;
            $min_register_day = $date_tokens[2] ?? 1;
            $min_date = sprintf('%u-%02u-%02u', $min_register_year, $min_register_month, $min_register_day);
            $where_clauses[] = 'ui.registration_date >= \'' . $min_date . ' 00:00:00\'';
        }

        if (! empty($params['max_register'])) {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $params['max_register'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter max_register');
            }

            $max_date_tokens = explode('-', $params['max_register']);
            $max_register_year = $max_date_tokens[0];
            $max_register_month = $max_date_tokens[1] ?? 12;
            if (isset($max_date_tokens[2])) {
                $max_register_day = $max_date_tokens[2];
            } else {
                // year/month were regex-validated above
                // (\d\d\d\d(-\d{1,2}){0,2}), so this is always a well-formed
                // date string
                $max_register_month_ts = strtotime($max_register_year . '-' . $max_register_month . '-1');
                assert($max_register_month_ts !== false);
                $max_register_day = date('t', $max_register_month_ts);
            }
            $max_date = sprintf('%u-%02u-%02u', $max_register_year, $max_register_month, $max_register_day);
            $where_clauses[] = 'ui.registration_date <= \'' . $max_date . ' 23:59:59\'';
        }

        if (! empty($params['status'])) {
            $params['status'] = array_intersect($params['status'], get_enums(Tables::userInfos(), 'status'));
            if (count($params['status']) > 0) {
                $where_clauses[] = 'ui.status IN("' . implode('","', $params['status']) . '")';
            }
        }

        if (! empty($params['min_level'])) {
            if (! in_array($params['min_level'], $available_permission_levels)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid level');
            }
            $where_clauses[] = 'ui.level >= ' . $params['min_level'];
        }

        if (! empty($params['max_level'])) {
            if (! in_array($params['max_level'], $available_permission_levels)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid level');
            }
            // 'max_level' is not a registered ws.php param (see this function's
            // @param docblock) -- reachable only via the shape's open tail, so
            // it's genuinely `mixed` here, unlike 'min_level'.
            $max_level = is_numeric($params['max_level']) ? (int) $params['max_level'] : 0;
            $where_clauses[] = 'ui.level <= ' . $max_level;
        }

        if (! empty($params['group_id'])) {
            $where_clauses[] = 'ug.group_id IN(' . implode(',', $params['group_id']) . ')';
        }

        if (! empty($params['exclude'])) {
            $where_clauses[] = 'u.' . $user_field_id . ' NOT IN(' . implode(',', $params['exclude']) . ')';
        }

        $display = [
            'u.' . $user_field_id => 'id',
        ];

        // $params['display'] is a comma-separated string per the WS contract
        // (see the @param docblock above); it's never reused/reassigned here as
        // a scratch variable of a different type -- $display_flags is a
        // dedicated, precisely-typed array<string, true> "set" of the
        // requested display options, built via array_fill_keys() (we only ever
        // isset() it, never read its values) so its type stays uniform across
        // every branch below instead of drifting per-branch like array_flip()
        // of a partially-literal list would.
        $display_flags = [];
        if ($params['display'] != 'none') {
            $requested_display = array_map(trim(...), explode(',', $params['display']));

            if (in_array('all', $requested_display, true)) {
                $requested_display = [
                    'username', 'email', 'status', 'level', 'groups', 'language', 'theme',
                    'nb_image_page', 'recent_period', 'expand', 'show_nb_comments', 'show_nb_hits',
                    'enabled_high', 'registration_date', 'registration_date_string',
                    'registration_date_since', 'last_visit', 'last_visit_string',
                    'last_visit_since', 'total_count',
                ];
            } elseif (in_array('basics', $requested_display, true)) {
                $requested_display = array_merge($requested_display, [
                    'username', 'email', 'status', 'level', 'groups',
                ]);
            } elseif (in_array('only_id', $requested_display, true)) {
                $requested_display = [];
            }
            $display_flags = array_fill_keys($requested_display, true);

            // if registration_date_string or registration_date_since is requested,
            // then registration_date is automatically added
            if (isset($display_flags['registration_date_string']) or isset($display_flags['registration_date_since'])) {
                $display_flags['registration_date'] = true;
            }

            // if last_visit_string or last_visit_since is requested, then
            // last_visit is automatically added
            if (isset($display_flags['last_visit_string']) or isset($display_flags['last_visit_since'])) {
                $display_flags['last_visit'] = true;
            }

            if (isset($display_flags['username'])) {
                $display['u.' . $user_field_username] = 'username';
            }
            if (isset($display_flags['email'])) {
                $display['u.' . $user_field_email] = 'email';
            }

            $ui_fields = [
                'status', 'level', 'language', 'theme', 'nb_image_page', 'recent_period', 'expand',
                'show_nb_comments', 'show_nb_hits', 'enabled_high', 'registration_date',
                'last_visit',
            ];
            foreach ($ui_fields as $field) {
                if (isset($display_flags[$field])) {
                    $display['ui.' . $field] = $field;
                }
            }
        }

        $query = '
SELECT DISTINCT ';

        // ADD SQL_CALC_FOUND_ROWS if display total_count is requested
        if (isset($display_flags['total_count'])) {
            $query .= 'SQL_CALC_FOUND_ROWS ';
        }
        $first = true;
        foreach ($display as $field => $name) {
            if (! $first) {
                $query .= ', ';
            } else {
                $first = false;
            }
            $query .= $field . ' AS ' . $name;
        }

        if (isset($display['ui.last_visit'])) {
            // $display always has at least the 'id' entry set above the foreach,
            // so $first is always false by the time we get here.
            $query .= ', ui.last_visit_from_history AS last_visit_from_history';
        }
        $query .= '
  FROM ' . Tables::users() . ' AS u
    INNER JOIN ' . Tables::userInfos() . ' AS ui
      ON u.' . $user_field_id . ' = ui.user_id
    LEFT JOIN ' . Tables::userGroup() . ' AS ug
      ON u.' . $user_field_id . ' = ug.user_id
  WHERE
    ' . implode(' AND ', $where_clauses) . '
  ORDER BY ' . $params['order'];
        if ($params['per_page'] != 0 || ! empty($display_flags)) {
            $query .= '
    LIMIT ' . $params['per_page'] . '
    OFFSET ' . ($params['per_page'] * $params['page']) . ';
    ;';
        }
        $users = [];
        $result = pwg_query($query);
        $total_count = 0;

        /* GET THE RESULT OF SQL_CALC_FOUND_ROWS if display total_count is requested */
        if (isset($display_flags['total_count'])) {
            $total_count_query_result = pwg_query('SELECT FOUND_ROWS();');
            $row = pwg_db_fetch_row($total_count_query_result);
            assert($row !== null);
            [$total_count] = $row;
            $total_count = (int) $total_count;
        }
        // Extracted once (instead of re-checking isset($display_flags['groups'])
        // both inside this loop and again below it) because PHPStan's loop-body
        // type narrowing otherwise mis-infers the offset as unconditionally
        // present after the loop.
        $want_groups = isset($display_flags['groups']);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $row['id'] = intval($row['id']);
            if ($want_groups) {
                $row['groups'] = []; // will be filled later
            }
            $users[$row['id']] = $row;
        }

        $users_id_arr = [];
        if (count($users) > 0) {
            if ($want_groups) {
                $query = '
  SELECT user_id, group_id
  FROM ' . Tables::userGroup() . '
  WHERE user_id IN (' . implode(',', array_keys($users)) . ')
;';
                $result = pwg_query($query);
                // a dedicated $group_row (instead of reusing $row from the loop
                // above, which iterates a differently-shaped result set) keeps
                // PHPStan's per-loop type inference precise.
                while ((bool) ($group_row = pwg_db_fetch_assoc($result))) {
                    $group_user_id = is_numeric($group_row['user_id']) ? (int) $group_row['user_id'] : null;
                    $group_id = is_numeric($group_row['group_id']) ? (int) $group_row['group_id'] : null;
                    if ($group_user_id === null || $group_id === null || ! isset($users[$group_user_id]) || ! is_array($users[$group_user_id]['groups'] ?? null)) {
                        continue;
                    }
                    $users[$group_user_id]['groups'][] = $group_id;
                }
            }
            foreach ($users as $cur_user) {
                // $cur_user['id'] was intval()'d above when $users was
                // populated, so it's already a real int here.
                $cur_user_id = $cur_user['id'];
                $users_id_arr[] = $cur_user_id;

                $cur_user_registration_date = is_string($cur_user['registration_date']) ? $cur_user['registration_date'] : null;

                if (isset($display_flags['registration_date_string'])) {
                    $users[$cur_user_id]['registration_date_string'] = DateHelper::formatDate($cur_user_registration_date ?? false, ['day', 'month', 'year']);
                }
                if (isset($display_flags['registration_date_since'])) {
                    $users[$cur_user_id]['registration_date_since'] = DateHelper::timeSince($cur_user_registration_date ?? '', 'month');
                }
                if (isset($display_flags['last_visit'])) {
                    $last_visit = is_string($cur_user['last_visit']) ? $cur_user['last_visit'] : null;
                    $users[$cur_user_id]['last_visit'] = $last_visit;

                    if (! get_boolean($cur_user['last_visit_from_history']) and empty($last_visit)) {
                        $last_visit = new AuthService(new AuthRepository(DbConnection::build()), new ActivityService(new ActivityRepository(DbConnection::build())))->getUserLastVisitFromHistory($cur_user_id, true);
                        $users[$cur_user_id]['last_visit'] = $last_visit;
                    }

                    if (isset($display_flags['last_visit_string'])) {
                        $users[$cur_user_id]['last_visit_string'] = DateHelper::formatDate($last_visit ?? false, ['day', 'month', 'year']);
                    }

                    if (isset($display_flags['last_visit_since'])) {
                        $users[$cur_user_id]['last_visit_since'] = DateHelper::timeSince($last_visit ?? '', 'day');
                    }
                }
            }
        }
        // trigger_change()'s return type is genuinely mixed (it dispatches to
        // whatever handler is registered for 'ws_users_getList'); narrow back
        // to the same array<int, array<string, mixed>> shape that was passed
        // in.
        $users_after_trigger = trigger_change('ws_users_getList', $users);
        $users = is_array($users_after_trigger) ? $users_after_trigger : $users;
        if ($params['per_page'] == 0 && empty($display_flags)) {
            $method_result = $users_id_arr;
        } else {
            $method_result = [
                'paging' => new PwgNamedStruct(
                    [
                        'page' => $params['page'],
                        'per_page' => $params['per_page'],
                        'count' => count($users),
                        'total_count' => $total_count,
                    ]
                ),
                'users' => new PwgNamedArray(array_values($users), 'user'),
            ];
        }
        // deprecated: kept for retrocompatibility
        if (isset($display_flags['total_count'])) {
            $method_result['total_count'] = $total_count;
        }
        return $method_result;
    }

    /**
     * API method
     * Adds a user
     *
     * @param array{username: string, auto_password: bool, password: string|null, password_confirm?: string, email: string|null, send_password_by_mail: bool, pwg_token: string, ...} $params
     *   username/pwg_token: no 'default' key -- mandatory, always present.
     *   auto_password/send_password_by_mail: non-null bool default,
     *   WsParamType::BOOL -- always present, always bool (auto_password's ws.php
     *   registration previously said 'flags' => WsParamType::BOOL, a typo that
     *   left it uncoerced -- fixed to 'type' => WsParamType::BOOL alongside this
     *   shape). password/email: null default, no 'type' flag -- always
     *   present, string|null. password_confirm: WsParamFlag::OPTIONAL with no
     *   'default' key -- may be entirely absent.
     */
    public static function add(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (strlen(str_replace(' ', '', $params['username'])) == 0) {
            return new PwgError(WsError::INVALID_PARAM, 'Name field must not be empty');
        }

        /** @var array<string, mixed> $conf */
        global $conf;

        if ((bool) $conf['double_password_type_in_admin']) {
            if ($params['password'] != ($params['password_confirm'] ?? null)) {
                return new PwgError(WsError::INVALID_PARAM, l10n('The passwords do not match'));
            }
        }

        if ($params['auto_password']) {
            $params['password'] = SessionService::get()->generateKey(mt_rand(15, 20));
        }

        // register_user() genuinely requires a string password; a client that
        // sends neither auto_password=true nor an explicit password would
        // otherwise reach it with null and crash inside pwg_password_hash() ->
        // password_hash() (a real string-typed native function).
        if ($params['password'] === null) {
            return new PwgError(WsError::INVALID_PARAM, l10n('Please, enter a password'));
        }

        // Preserves the pre-SEC-31 behavior for this real caller (admin-
        // authenticated ws.users.add legitimately needs the real "already
        // used" message to let an operator pick a different username) --
        // UserService::registerUser() itself never puts that message in
        // errors (it would let an attacker enumerate accounts through the
        // public self-registration form).
        $result = self::userService()
            ->registerUser(
                $params['username'],
                $params['password'],
                $params['email'],
                false, // notify admin
                false // $params['send_password_by_mail']
            );

        $errors = $result['errors'];
        if ($result['duplicateUsername']) {
            array_unshift($errors, l10n('this login is already used'));
        }

        $user_id = $result['userId'] ?? false;

        if (! (bool) $user_id) {
            return new PwgError(WsError::INVALID_PARAM, $errors[0]);
        }

        return $service->invoke('pwg.users.getList', [
            'user_id' => $user_id,
        ]);
    }

    /**
     * API method
     * Get a new authentication key for a user.
     *
     * @param array{user_id: int, pwg_token: string, ...} $params neither has a
     *   'default' key -- both mandatory, always present. user_id: WsParamType::ID,
     *   not FORCE_ARRAY here -- a plain int.
     */
    public static function getAuthKey(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $authkey = new AuthService(new AuthRepository(DbConnection::build()), new ActivityService(new ActivityRepository(DbConnection::build())))->createUserAuthKey($params['user_id']);

        if ($authkey === false) {
            return new PwgError(WsError::INVALID_PARAM, 'invalid user_id');
        }

        return $authkey;
    }

    /**
     * API method
     * Deletes users
     *
     * @param array{user_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces user_id to a list of positive ints.
     */
    public static function delete(array $params, PwgServer &$service): PwgError|string
    {
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        $protected_users = [
            $user['id'],
            $conf['guest_id'],
            $conf['default_user_id'],
            $conf['webmaster_id'],
        ];

        // an admin can't delete other admin/webmaster
        if ($user['status'] == 'admin') {
            $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
            $protected_users = array_merge($protected_users, query2array($query, null, 'user_id'));
        }

        // protect some users
        // array_diff() requires every element to be string-castable;
        // query2array()'s list<string|null> return (key_name=null,
        // value_name='user_id') can contain null for a NULL user_id column, and
        // $protected_users' own entries are still `mixed` even after typing
        // $conf/$user above, so filter down to scalars (int/string) before
        // diffing.
        $protected_users = array_filter($protected_users, is_scalar(...));
        $params['user_id'] = array_diff($params['user_id'], $protected_users);

        $counter = 0;

        $user_service = self::userService();
        foreach ($params['user_id'] as $user_id) {
            $user_service->deleteUser($user_id);
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
     *
     * @param array{user_id: array<int, int>, username?: string, password?: string, email?: string, status?: string, level?: int, language?: string, theme?: string, group_id?: array<int, int>, nb_image_page?: int, recent_period?: int, expand?: bool, show_nb_comments?: bool, show_nb_hits?: bool, enabled_high?: bool, pwg_token: string, ...} $params
     *   user_id/pwg_token: no 'default' key -- mandatory, always present;
     *   FORCE_ARRAY always coerces user_id to a list of positive ints. every
     *   other key: WsParamFlag::OPTIONAL with no 'default' key -- may be entirely
     *   absent; group_id: WsParamType::INT only (no POSITIVE) since -1 is a valid
     *   value ("dissociate from all groups").
     */
    public static function setInfo(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $updated_users = self::userService()->checkAndSaveUserInfos($params);

        if (isset($updated_users['error'])) {
            // check_and_save_user_infos() is declared to return plain `array`
            // (include/functions_user.inc.php); its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new PwgError($error_code, $error_message);
        }

        $updated_infos = is_array($updated_users['infos'] ?? null) ? $updated_users['infos'] : [];

        return $service->invoke('pwg.users.getList', [
            'user_id' => $updated_users['user_id'],
            'display' => 'basics,' . implode(',', array_keys($updated_infos)),
        ]);
    }

    /**
     * API method
     * Update user
     * @since 16
     *
     * @param array{email?: string, nb_image_page?: int, theme?: string, language?: string, recent_period?: int, expand?: bool, show_nb_comments?: bool, show_nb_hits?: bool, password?: string, new_password?: string, conf_new_password?: string, pwg_token: string, ...} $params
     *   pwg_token: no 'default' key -- mandatory, always present. every other
     *   key: WsParamFlag::OPTIONAL with no 'default' key -- may be entirely
     *   absent. (the body's unset() calls for 'username'/'status'/'level'/
     *   'group_id'/'enabled_high' target keys not in this method's own
     *   ws.php registration at all -- harmless no-ops, not part of the real
     *   shape.)
     */
    public static function setMyInfo(array $params, PwgServer &$service): PwgError|string
    {
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (AccessControl::isAGuest()) {
            return new PwgError(401, 'Access Denied');
        }

        /**
         * @var array<string, mixed> $user
         * @var array<string, mixed> $conf
         */
        global $user, $conf;

        // ACTIVATE_COMMENTS
        if (! (bool) $conf['activate_comments']) {
            unset($params['show_nb_comments']);
        }

        // ALLOW_USER_CUSTOMIZATION
        if (! (bool) $conf['allow_user_customization']) {
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
        $special_user = in_array($user['id'], [$conf['guest_id'], $conf['default_user_id']]);
        if ($special_user) {
            unset(
                $params['password'],
                $params['theme'],
                $params['language']
            );
        }

        if (! empty($params['password'])) {
            if (($params['new_password'] ?? null) != ($params['conf_new_password'] ?? null)) {
                return new PwgError(403, l10n('The passwords do not match'));
            }

            // $conf['user_fields'] maps generic field names to table-specific
            // DB column names (see include/functions_user.inc.php for the same
            // pattern).
            $user_fields = $conf['user_fields'];
            $user_fields = is_array($user_fields) ? $user_fields : [];
            $user_field_password = is_string($user_fields['password'] ?? null) ? $user_fields['password'] : 'password';
            $user_field_id = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';
            $current_user_id = is_scalar($user['id']) ? (string) $user['id'] : '';

            $query = '
SELECT ' . $user_field_password . ' AS password
  FROM ' . Tables::users() . '
  WHERE ' . $user_field_id . ' = \'' . $current_user_id . '\'
;';
            $row = pwg_db_fetch_row(pwg_query($query));
            assert($row !== null);
            [$current_password] = $row;
            $current_password = is_string($current_password) ? $current_password : '';

            // $params['password'] is declared string via this function's own
            // @param docblock, but the conditional unset($params['password'])
            // above (SPECIAL_USER branch) makes PHPStan lose that offset's
            // precise type after the merge, so it's read back as mixed here.
            $params_password = is_string($params['password']) ? $params['password'] : '';

            if (! new PasswordService(new PasswordRepository(DbConnection::build()))->verify($params_password, $current_password)) {
                return new PwgError(403, l10n('Current password is wrong'));
            }

            $params['password'] = $params['new_password'] ?? null;
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
        $updated_users = self::userService()->checkAndSaveUserInfos($params);

        if (isset($updated_users['error'])) {
            // check_and_save_user_infos() is declared to return plain `array`
            // (include/functions_user.inc.php); its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new PwgError($error_code, $error_message);
        }

        return l10n('Your changes have been applied.');
    }

    /**
     * API method
     * Set a preferences parameter to current user
     * @since 13
     *
     * @param array{param: string, value?: string, is_json: bool, ...} $params
     *   param: no 'default' key -- mandatory, always present. value:
     *   WsParamFlag::OPTIONAL with no 'default' key -- may be entirely absent.
     *   is_json: non-null bool default, WsParamType::BOOL -- always present.
     */
    public static function preferencesSet(array $params, PwgServer &$service): mixed
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (! (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $params['param'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid param name #' . $params['param'] . '#');
        }

        $value = stripslashes($params['value'] ?? '');
        if ($params['is_json']) {
            $value = json_decode($value, true);
        }

        new PreferencesService(new UserRepository(DbConnection::build()))->updateParam($params['param'], $value);

        return $user['preferences'];
    }

    /**
     * API method
     * Adds a favorite image for the current user
     *
     * @param array{image_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     */
    public static function favoritesAdd(array $params, PwgServer &$service): PwgError|true
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (AccessControl::isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }

        // does the image really exist?
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$count] = $row;
        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }

        single_insert(
            Tables::favorites(),
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
     *
     * @param array{image_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     */
    public static function favoritesRemove(array $params, PwgServer &$service): PwgError|true
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (AccessControl::isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }

        // does the image really exist?
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$count] = $row;
        if ($count == 0) {
            return new PwgError(404, 'image_id not found');
        }

        $current_user_id = is_scalar($user['id']) ? (string) $user['id'] : '';
        $query = '
DELETE
  FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $current_user_id . '
    AND image_id = ' . $params['image_id'] . '
;';

        pwg_query($query);

        return true;
    }

    /**
     * API method
     * Returns the favorite images of the current user
     *
     * @param array{per_page: int, page: int, order: string|null, ...} $params
     *   per_page/page: non-null int default, WsParamType::INT|WsParamType::POSITIVE --
     *   always present. order: null default, no 'type' flag -- always
     *   present, string|null.
     * @return false|array{paging: PwgNamedStruct, images: PwgNamedArray}
     */
    public static function favoritesGetList(array $params, PwgServer &$service): false|array
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        if (AccessControl::isAGuest()) {
            return false;
        }

        self::userService()->checkUserFavorites();

        $order_by = WsHelper::stdImageSqlOrder($params, 'i.');
        $conf_order_by = is_string($conf['order_by'] ?? null) ? $conf['order_by'] : '';
        $order_by = empty($order_by) ? $conf_order_by : 'ORDER BY ' . $order_by;
        $current_user_id = is_scalar($user['id']) ? (string) $user['id'] : '';

        $query = '
SELECT
    i.*
  FROM ' . Tables::favorites() . '
    INNER JOIN ' . Tables::images() . ' i ON image_id = i.id
  WHERE user_id = ' . $current_user_id . '
' . new PermissionService(new PermissionRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))->getSqlConditionFandF([
            'visible_images' => 'id',
        ], 'AND') . '
    ' . $order_by . '
;';
        $images = [];
        $result = pwg_query($query);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $image = [];

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = (int) $row[$k];
                }
            }

            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $images[] = array_merge($image, WsHelper::stdGetUrls($row));
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
                WsHelper::stdGetImageXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Returns the reset password link of the current user
     * @since 15
     *
     * @param array{user_id: int, pwg_token: string, send_by_mail: bool, ...} $params
     *   user_id/pwg_token: no 'default' key -- mandatory, always present,
     *   WsParamType::ID guarantees a plain int for user_id. send_by_mail: non-null
     *   bool default, WsParamType::BOOL -- always present.
     * @return PwgError|array{generated_link: mixed, send_by_mail: string|false|null, time_validation: mixed}
     */
    public static function generatePasswordLink(array $params, PwgServer &$service): PwgError|array
    {
        /**
         * @var array<string, mixed> $user
         * @var array<string, mixed> $conf
         */
        global $user, $conf;

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // check if user exist
        if (self::userService()->getUsername($params['user_id']) === false) {
            return new PwgError(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        // getuserdata() is declared to return array<string, mixed> (its own
        // @return docblock, include/functions_user.inc.php); narrow the
        // specific fields this function consumes to their real column types.
        $user_lost = self::userService()->getUserData($params['user_id']);
        $user_lost_status = is_string($user_lost['status']) ? $user_lost['status'] : '';

        // Cannot perform this action for a guest or generic user
        if (AccessControl::isAGuest($user_lost_status) or AccessControl::isGeneric($user_lost_status)) {
            return new PwgError(403, 'Password reset is not allowed for this user');
        }

        // Only webmaster can perform this action for another webmaster
        if ($user['status'] === 'admin' && $user_lost_status === 'webmaster') {
            return new PwgError(403, 'You cannot perform this action');
        }

        $first_login = new AuthService(new AuthRepository(DbConnection::build()), new ActivityService(new ActivityRepository(DbConnection::build())))->hasAlreadyLoggedIn($params['user_id']);
        $send_by_mail_response = null;
        $user_lost_language = is_string($user_lost['language']) ? $user_lost['language'] : self::userService()->getDefaultLanguage();
        $lang_to_use = $first_login ? self::userService()->getDefaultLanguage() : $user_lost_language;

        new MailService()
            ->switchLangTo($lang_to_use);
        $generate_link = new AuthService(new AuthRepository(DbConnection::build()), new ActivityService(new ActivityRepository(DbConnection::build())))->generatePasswordLink($params['user_id'], $first_login);

        $user_lost_email = is_string($user_lost['email']) ? $user_lost['email'] : null;

        // $conf['gallery_title'] is a raw config string; pwg_generate_set/
        // reset_password_mail() both require a real string for their 3rd
        // parameter.
        $gallery_title = is_string($conf['gallery_title'] ?? null) ? $conf['gallery_title'] : '';

        if ($params['send_by_mail'] and ! empty($user_lost_email)) {
            $user_lost_username = is_string($user_lost['username']) ? $user_lost['username'] : '';
            if ($first_login) {
                $email_params = new MailService()
                    ->generateSetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            } else {
                $email_params = new MailService()
                    ->generateResetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            }
            // Here we remove the display of errors because they prevent the response from being parsed
            if (@new MailService()->mail($user_lost_email, $email_params)) {
                $send_by_mail_response = 'Mail sent at : ' . $user_lost_email;
            } else {
                $send_by_mail_response = false;
            }
        }
        new MailService()
            ->switchLangBack();

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
     *
     * @param array{user_id: int, pwg_token: string, ...} $params neither has a
     *   'default' key -- both mandatory, always present, WsParamType::ID guarantees
     *   a plain int for user_id.
     */
    public static function setMainUser(array $params, PwgServer &$service): PwgError|string
    {
        // check if not webmaster
        if (! AccessControl::isWebmaster()) {
            return new PwgError(403, 'You cannot perform this action');
        }

        // check pwg_token
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // checl if user exist
        if (self::userService()->getUsername($params['user_id']) === false) {
            return new PwgError(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        $new_main_user = self::userService()->getUserData($params['user_id']);

        // check if the user to set as main user is not webmaster
        if ($new_main_user['status'] !== 'webmaster') {
            return new PwgError(403, 'This user cannot become a main user because he is not a webmaster.');
        }

        conf_update_param('webmaster_id', $params['user_id']);
        return 'The main user has been changed.';
    }

    /**
     * API method
     * Create a new api key for the current user
     * @since 15
     *
     * @param array{key_name: string, duration: int, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present; duration:
     *   WsParamType::INT|WsParamType::POSITIVE guarantees a plain int.
     * @return PwgError|array<string, mixed>
     */
    public static function createApiKey(array $params, PwgServer &$service): PwgError|array
    {
        /**
         * @var array<string, mixed> $user
         * @var Logger $logger
         */
        global $user, $logger;

        if (AccessControl::isAGuest() or ! new ApiKeyService(new MailService())->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if ($params['duration'] < 1 or $params['duration'] > 999999) {
            return new PwgError(400, 'Invalid duration max days is 999999');
        }

        if (strlen($params['key_name']) > 100) {
            return new PwgError(400, 'Key name is too long');
        }

        $key_name = pwg_db_real_escape_string($params['key_name']);
        assert($key_name !== null);
        // the guard above already rejects any duration outside [1, 999999], so
        // it can never be 0 here.
        $duration = $params['duration'];

        // create_api_key() requires a real int $user_id (its own @param
        // docblock, include/functions_user.inc.php); a logged-in, non-guest
        // session's $user['id'] is always the numeric users.id primary key.
        $user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

        $secret = new ApiKeyService(new MailService())
            ->create($user_id, $duration, $key_name);

        $logger->info('[api_key][user_id=' . $user_id . '][action=create][key_name=' . $params['key_name'] . ']');

        return $secret;
    }

    /**
     * API method
     * Revoke a api key for the current user
     * @since 15
     *
     * @param array{pkid: string, pwg_token: string, ...} $params neither has a
     *   'default' key -- both mandatory, always present, no 'type' flag.
     */
    public static function revokeApiKey(array $params, PwgServer &$service): PwgError|string
    {
        /**
         * @var array<string, mixed> $user
         * @var Logger $logger
         */
        global $user, $logger;

        if (AccessControl::isAGuest() or ! new ApiKeyService(new MailService())->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, l10n('Invalid security token'));
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['pkid'])) {
            return new PwgError(403, l10n('Invalid pkid format'));
        }

        // revoke_api_key() requires a real int $user_id (its own @param
        // docblock, include/functions_user.inc.php); a logged-in, non-guest
        // session's $user['id'] is always the numeric users.id primary key.
        $user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

        $revoked_key = new ApiKeyService(new MailService())
            ->revoke($user_id, $params['pkid']);

        if ($revoked_key !== true) {
            return new PwgError(403, $revoked_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=revoke][pkid=' . $params['pkid'] . ']');

        return l10n('API Key has been successfully revoked.');
    }

    /**
     * API method
     * Edit a api key for the current user
     * @since 15
     *
     * @param array{key_name: string, pkid: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag.
     */
    public static function editApiKey(array $params, PwgServer &$service): PwgError|string
    {
        /**
         * @var array<string, mixed> $user
         * @var Logger $logger
         */
        global $user, $logger;

        if (AccessControl::isAGuest()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (! new ApiKeyService(new MailService())->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, l10n('Invalid security token'));
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['pkid'])) {
            return new PwgError(403, l10n('Invalid pkid format'));
        }

        $key_name = pwg_db_real_escape_string($params['key_name']);
        // edit_api_key() requires a real int $user_id (its own @param
        // docblock, include/functions_user.inc.php); a logged-in, non-guest
        // session's $user['id'] is always the numeric users.id primary key.
        $user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $edited_key = new ApiKeyService(new MailService())
            ->edit($user_id, $params['pkid'], $key_name);

        if ($edited_key !== true) {
            return new PwgError(403, $edited_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=edit][pkid=' . $params['pkid'] . '][new_name=' . $key_name . ']');

        return l10n('API Key has been successfully edited.');
    }

    /**
     * API method
     * Get all api key for the current user
     * @since 15
     *
     * @param array{pwg_token: string, ...} $params no 'default' key --
     *   mandatory, always present, no 'type' flag.
     * @return PwgError|array<int, mixed>|string
     */
    public static function getApiKey(array $params, PwgServer &$service): PwgError|array|string
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (AccessControl::isAGuest()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (! new ApiKeyService(new MailService())->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // P23 batch 8e-6: fixes task #473, a real bug predating this
        // migration -- ApiKeyService::get() takes a native int $userId (see
        // its own signature), but this call previously cast $user_id to a
        // *string* (matching a stale docblock claim that get_api_key()
        // "requires a string $user_id", which was itself just describing
        // the bug). Every other ApiKeyService method here (create/revoke/
        // edit) already passes a plain int for the same $user['id'] value.
        $user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $api_keys = new ApiKeyService(new MailService())
            ->get($user_id);

        return ((bool) $api_keys) ? $api_keys : l10n('No API key found');
    }
}
