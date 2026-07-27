<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthService;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;
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
        return new UserService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Bootstrap\PresentationAccessor::mailService(), \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(), \Piwigo\Bootstrap\PresentationAccessor::htmlService(), DbConnection::build());
    }

    /**
     * Constructed repeatedly across getList()/getAuthKey()/
     * generatePasswordLink() -- including once inside getList()'s
     * per-user foreach loop. Unlike Ws\PwgTags::activityService(Connection $conn) /
     * Bootstrap\RequestBootstrap::activityService(Connection $conn), this
     * method takes no $conn parameter -- it simply delegates to
     * Bootstrap\CoreDomainAccessor::authService() on every call.
     */
    private static function authService(): AuthService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::authService();
    }

    /**
     * Constructed identically 8 times across createApiKey()/
     * revokeApiKey()/editApiKey()/getApiKey() -- none inside a loop, so
     * (unlike authService() above) this builds its own connection per
     * call, same shape as PwgComments::commentService()/
     * PwgGroups::groupService().
     */
    private static function apiKeyService(): ApiKeyService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::apiKeyService();
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
     * Genuinely dynamic response shape: which per-user fields are present
     * depends on the client-controlled 'display' param (a comma-separated
     * field list), not a single fixed row shape.
     * @return PwgError|array<int|string, mixed>
     */
    public static function getList(array $params, PwgServer &$service): PwgError|array
    {
        // Shared across every query in this method, including the
        // SELECT SQL_CALC_FOUND_ROWS.../SELECT FOUND_ROWS() pair below --
        // FOUND_ROWS() reflects the immediately-preceding query on the
        // SAME connection/session, so this must stay one connection for
        // the whole method, not DbConnection::build() per query.
        $conn = DbConnection::build();

        // \Piwigo\Config\CurrentConfig::userFields() maps generic field names to table-specific DB
        // column names (see Piwigo\Users\UserService for the same pattern);
        // extracted once here since this function reads id/username/
        // email repeatedly below.
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $user_field_id = $user_fields['id'];
        $user_field_username = $user_fields['username'];
        $user_field_email = $user_fields['email'];

        $available_permission_levels = \Piwigo\Config\CurrentConfig::availablePermissionLevels();

        if (! (bool) preg_match(ValidationPattern::ORDER, $params['order'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        // Insensitive case sort order
        if (str_contains($params['order'], 'username')) {
            $params['order'] = str_ireplace('username', 'LOWER(username)', $params['order']);
        }

        $where_clauses = ['1=1'];

        if (isset($params['user_id']) && $params['user_id'] !== []) {
            $where_clauses[] = 'u.' . $user_field_id . ' IN(' . implode(',', $params['user_id']) . ')';
        }

        if (isset($params['username']) && $params['username'] !== '') {
            $where_clauses[] = 'u.' . $user_field_username . ' LIKE ' . $conn->quote($params['username']);
        }

        $filtered_groups = [];
        if (isset($params['filter']) && $params['filter'] !== '') {
            $filter_like = $conn->quote('%' . $params['filter'] . '%');
            $filter_query = 'SELECT id FROM `' . Tables::groups() . '` WHERE name LIKE ' . $filter_like . ';';
            foreach ($conn->fetchAllAssociative($filter_query) as $row) {
                if (is_scalar($row['id'])) {
                    $filtered_groups[] = (string) $row['id'];
                }
            }
            $filter_where_clause = '(u.' . $user_field_username . ' LIKE ' . $filter_like . ' OR '
            . 'u.' . $user_field_email . ' LIKE ' . $filter_like;

            if ($filtered_groups !== []) {
                $filter_where_clause .= 'OR ug.group_id IN (' . implode(',', $filtered_groups) . ')';
            }
            $where_clauses[] = $filter_where_clause . ')';
        }

        if (isset($params['min_register']) && $params['min_register'] !== '') {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $params['min_register'])) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter min_register');
            }

            $date_tokens = explode('-', $params['min_register']);
            $min_register_year = $date_tokens[0];
            $min_register_month = $date_tokens[1] ?? 1;
            $min_register_day = $date_tokens[2] ?? 1;
            $min_date = sprintf('%u-%02u-%02u', (int) $min_register_year, (int) $min_register_month, (int) $min_register_day);
            $where_clauses[] = 'ui.registration_date >= \'' . $min_date . ' 00:00:00\'';
        }

        if (isset($params['max_register']) && $params['max_register'] !== '') {
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
            $max_date = sprintf('%u-%02u-%02u', (int) $max_register_year, (int) $max_register_month, (int) $max_register_day);
            $where_clauses[] = 'ui.registration_date <= \'' . $max_date . ' 23:59:59\'';
        }

        if (isset($params['status']) && $params['status'] !== []) {
            $params['status'] = array_intersect($params['status'], new DbInfo($conn)->getEnums(Tables::userInfos(), 'status'));
            if (count($params['status']) > 0) {
                $where_clauses[] = 'ui.status IN("' . implode('","', $params['status']) . '")';
            }
        }

        if ($params['min_level'] !== 0) {
            if (! in_array($params['min_level'], $available_permission_levels, true)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid level');
            }
            $where_clauses[] = 'ui.level >= ' . $params['min_level'];
        }

        if (! in_array($params['max_level'] ?? null, [null, false, 0, '0', '', []], true)) {
            if (! in_array(is_numeric($params['max_level']) ? (int) $params['max_level'] : null, $available_permission_levels, true)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid level');
            }
            // 'max_level' is not a registered ws.php param (see this function's
            // @param docblock) -- reachable only via the shape's open tail, so
            // it's genuinely `mixed` here, unlike 'min_level'.
            $max_level = is_numeric($params['max_level']) ? (int) $params['max_level'] : 0;
            $where_clauses[] = 'ui.level <= ' . $max_level;
        }

        if (isset($params['group_id']) && $params['group_id'] !== []) {
            $where_clauses[] = 'ug.group_id IN(' . implode(',', $params['group_id']) . ')';
        }

        if (isset($params['exclude']) && $params['exclude'] !== []) {
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
        if ($params['display'] !== 'none') {
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
        if ($params['per_page'] !== 0 || $display_flags !== []) {
            $query .= '
    LIMIT ' . $params['per_page'] . '
    OFFSET ' . ($params['per_page'] * $params['page']) . ';
    ;';
        }
        $users = [];
        $rows = $conn->fetchAllAssociative($query);
        $total_count = 0;

        /* GET THE RESULT OF SQL_CALC_FOUND_ROWS if display total_count is requested */
        if (isset($display_flags['total_count'])) {
            $total_count_result = $conn->fetchOne('SELECT FOUND_ROWS();');
            $total_count = is_numeric($total_count_result) ? (int) $total_count_result : 0;
        }
        // Extracted once (instead of re-checking isset($display_flags['groups'])
        // both inside this loop and again below it) because PHPStan's loop-body
        // type narrowing otherwise mis-infers the offset as unconditionally
        // present after the loop.
        $want_groups = isset($display_flags['groups']);
        foreach ($rows as $row) {
            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;
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
                // a dedicated $group_row (instead of reusing $row from the loop
                // above, which iterates a differently-shaped result set) keeps
                // PHPStan's per-loop type inference precise.
                foreach ($conn->fetchAllAssociative($query) as $group_row) {
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

                    if (! SqlDialect::getBoolean($cur_user['last_visit_from_history']) and in_array($last_visit, [null, ''], true)) {
                        $last_visit = self::authService()->getUserLastVisitFromHistory($cur_user_id, true);
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
        $users_after_trigger = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('ws_users_getList', $users);
        $users = is_array($users_after_trigger) ? $users_after_trigger : $users;
        if ($params['per_page'] === 0 && $display_flags === []) {
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
     *
     * Return type genuinely can't be narrower than mixed: the success path
     * forwards $service->invoke('pwg.users.getList', ...)'s own result,
     * and PwgServer::invoke() is itself a generic by-name dispatcher across
     * every registered WS method, each with its own distinct return shape.
     */
    public static function add(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (strlen(str_replace(' ', '', $params['username'])) === 0) {
            return new PwgError(WsError::INVALID_PARAM, 'Name field must not be empty');
        }

        if (\Piwigo\Config\CurrentConfig::doublePasswordTypeInAdmin()) {
            if (($params['password'] ?? '') !== ($params['password_confirm'] ?? '')) {
                return new PwgError(WsError::INVALID_PARAM, Lang::t('The passwords do not match'));
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
            return new PwgError(WsError::INVALID_PARAM, Lang::t('Please, enter a password'));
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
                \Piwigo\Bootstrap\PresentationAccessor::urlService(),
                false, // notify admin
                false // $params['send_password_by_mail']
            );

        $errors = $result['errors'];
        if ($result['duplicateUsername']) {
            array_unshift($errors, Lang::t('this login is already used'));
        }

        $user_id = $result['userId'] ?? false;

        if (! (bool) $user_id) {
            return new PwgError(WsError::INVALID_PARAM, $errors[0] ?? '');
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
     *
     * @return PwgError|array{auth_key: string, user_id: int, created_on: string, duration: int, expired_on: string, key_type: string, auth_key_id: string}
     */
    public static function getAuthKey(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $authkey = self::authService()->createUserAuthKey($params['user_id']);

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
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $currentUser = \Piwigo\Users\CurrentUser::get();

        $protected_users = [
            $currentUser->id->value,
            \Piwigo\Config\CurrentConfig::guestId(),
            \Piwigo\Config\CurrentConfig::defaultUserId(),
            \Piwigo\Config\CurrentConfig::webmasterId(),
        ];

        // an admin can't delete other admin/webmaster
        if ($currentUser->status === \Piwigo\Users\UserStatus::Admin) {
            $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
            $protected_users = array_merge($protected_users, array_column(DbConnection::build()->fetchAllAssociative($query), 'user_id'));
        }

        // protect some users
        // array_diff() requires every element to be string-castable;
        // array_column()'s list<mixed> return can contain null for a NULL
        // user_id column, and $protected_users' $conf-sourced entries are
        // still `mixed` even after typing $conf above, so filter down to
        // scalars (int/string) before diffing.
        $protected_users = array_filter($protected_users, is_scalar(...));
        $params['user_id'] = array_diff($params['user_id'], $protected_users);

        $counter = 0;

        $user_service = self::userService();
        foreach ($params['user_id'] as $user_id) {
            $user_service->deleteUser(\Piwigo\Common\ValueObject\UserId::from($user_id));
            $counter++;
        }

        return Translator::get()->plural(
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
     *
     * Return type genuinely can't be narrower than mixed -- same
     * invoke()-forwarding rationale as add() above.
     */
    public static function setInfo(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $updated_users = self::userService()->checkAndSaveUserInfos($params);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
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
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (AccessControl::isAGuest()) {
            return new PwgError(401, 'Access Denied');
        }

        $currentUser = \Piwigo\Users\CurrentUser::get();

        // ACTIVATE_COMMENTS
        if (! \Piwigo\Config\CurrentConfig::activateComments()) {
            unset($params['show_nb_comments']);
        }

        // ALLOW_USER_CUSTOMIZATION
        if (! \Piwigo\Config\CurrentConfig::allowUserCustomization()) {
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
        $special_user = in_array($currentUser->id->value, [\Piwigo\Config\CurrentConfig::guestId(), \Piwigo\Config\CurrentConfig::defaultUserId()], true);
        if ($special_user) {
            unset(
                $params['password'],
                $params['theme'],
                $params['language']
            );
        }

        if (isset($params['password']) && $params['password'] !== '') {
            if (($params['new_password'] ?? '') !== ($params['conf_new_password'] ?? '')) {
                return new PwgError(403, Lang::t('The passwords do not match'));
            }

            // \Piwigo\Config\CurrentConfig::userFields() maps generic field names to table-specific
            // DB column names (see Piwigo\Users\UserService for the same
            // pattern).
            $user_fields = \Piwigo\Config\CurrentConfig::userFields();
            $user_field_password = $user_fields['password'];
            $user_field_id = $user_fields['id'];
            $current_user_id = (string) $currentUser->id->value;

            $query = '
SELECT ' . $user_field_password . ' AS password
  FROM ' . Tables::users() . '
  WHERE ' . $user_field_id . ' = \'' . $current_user_id . '\'
;';
            $current_password = DbConnection::build()->fetchOne($query);
            $current_password = is_string($current_password) ? $current_password : '';

            // $params['password'] is declared string via this function's own
            // @param docblock, but the conditional unset($params['password'])
            // above (SPECIAL_USER branch) makes PHPStan lose that offset's
            // precise type after the merge, so it's read back as mixed here.
            $params_password = is_string($params['password']) ? $params['password'] : '';

            if (! \Piwigo\Bootstrap\CoreDomainAccessor::passwordService()->verify($params_password, $current_password)) {
                return new PwgError(403, Lang::t('Current password is wrong'));
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

        $params['user_id'] = [$currentUser->id->value];
        $updated_users = self::userService()->checkAndSaveUserInfos($params);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new PwgError($error_code, $error_message);
        }

        return Lang::t('Your changes have been applied.');
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
     *
     * @return PwgError|array<string, mixed> matches
     *   Users\User::$preferences' own by-design arbitrary per-user
     *   key-value shape (User.php's own $preferences docblock)
     */
    public static function preferencesSet(array $params, PwgServer &$service): PwgError|array
    {
        if (! (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $params['param'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid param name #' . $params['param'] . '#');
        }

        $value = stripslashes($params['value'] ?? '');
        if ($params['is_json']) {
            $value = json_decode($value, true);
        }

        \Piwigo\Bootstrap\CoreDomainAccessor::preferencesService()->updateParam($params['param'], $value);

        return \Piwigo\Users\CurrentUser::get()->preferences;
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
        if (AccessControl::isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }

        // does the image really exist?
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $conn = DbConnection::build();
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count === 0) {
            return new PwgError(404, 'image_id not found');
        }

        new BatchWriter($conn)
            ->singleInsert(
                Tables::favorites(),
                [
                    'image_id' => $params['image_id'],
                    'user_id' => \Piwigo\Users\CurrentUser::get()->id->value,
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
        if (AccessControl::isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }

        // does the image really exist?
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $conn = DbConnection::build();
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count === 0) {
            return new PwgError(404, 'image_id not found');
        }

        $current_user_id = (string) \Piwigo\Users\CurrentUser::get()->id->value;
        $query = '
DELETE
  FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $current_user_id . '
    AND image_id = ' . $params['image_id'] . '
;';

        $conn->executeStatement($query);

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

        if (AccessControl::isAGuest()) {
            return false;
        }

        self::userService()->checkUserFavorites();

        $order_by = WsHelper::stdImageSqlOrder($params, 'i.');
        $order_by = $order_by === '' ? \Piwigo\Config\CurrentConfig::orderBy() : 'ORDER BY ' . $order_by;
        $current_user_id = (string) \Piwigo\Users\CurrentUser::get()->id->value;

        $query = '
SELECT
    i.*
  FROM ' . Tables::favorites() . '
    INNER JOIN ' . Tables::images() . ' i ON image_id = i.id
  WHERE user_id = ' . $current_user_id . '
' . new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())), \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Category\CategoryEntity::class))->getSqlConditionFandF([
            'visible_images' => 'id',
        ], 'AND') . '
    ' . $order_by . '
;';
        $images = [];
        foreach (DbConnection::build()->fetchAllAssociative($query) as $row) {
            $image = [];

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                }
            }

            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k] ?? null;
            }

            $images[] = array_merge($image, WsHelper::stdGetUrls($row, \Piwigo\Bootstrap\PresentationAccessor::urlService()));
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
     * @return PwgError|array{generated_link: string, send_by_mail: string|false|null, time_validation: string}
     */
    public static function generatePasswordLink(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $lost_user_id = \Piwigo\Common\ValueObject\UserId::from($params['user_id']);

        // check if user exist
        if (self::userService()->getUsername($lost_user_id) === false) {
            return new PwgError(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        // UserService::getUserData() is declared to return
        // array<string, mixed> (its own @return docblock); narrow the
        // specific fields this function consumes to their real column types.
        $user_lost = self::userService()->getUserData($lost_user_id);
        $user_lost_status = is_string($user_lost['status']) ? $user_lost['status'] : '';

        // Cannot perform this action for a guest or generic user
        if (AccessControl::isAGuest($user_lost_status) or AccessControl::isGeneric($user_lost_status)) {
            return new PwgError(403, 'Password reset is not allowed for this user');
        }

        // Only webmaster can perform this action for another webmaster
        if (\Piwigo\Users\CurrentUser::get()->status === \Piwigo\Users\UserStatus::Admin && $user_lost_status === 'webmaster') {
            return new PwgError(403, 'You cannot perform this action');
        }

        $conn = DbConnection::build();
        $first_login = self::authService()->hasAlreadyLoggedIn($params['user_id']);
        $send_by_mail_response = null;
        $user_lost_language = is_string($user_lost['language']) ? $user_lost['language'] : self::userService()->getDefaultLanguage();
        $lang_to_use = $first_login ? self::userService()->getDefaultLanguage() : $user_lost_language;

        \Piwigo\Bootstrap\PresentationAccessor::mailService()
            ->switchLangTo($lang_to_use);
        $generate_link = self::authService()->generatePasswordLink($params['user_id'], \Piwigo\Bootstrap\PresentationAccessor::urlService(), $first_login);

        $user_lost_email = is_string($user_lost['email']) ? $user_lost['email'] : null;

        // \Piwigo\Config\CurrentConfig::galleryTitle() is a raw config string; pwg_generate_set/
        // reset_password_mail() both require a real string for their 3rd
        // parameter.
        $gallery_title = \Piwigo\Config\CurrentConfig::galleryTitle();

        if ($params['send_by_mail'] and ! in_array($user_lost_email, [null, ''], true)) {
            $user_lost_username = is_string($user_lost['username']) ? $user_lost['username'] : '';
            if ($first_login) {
                $email_params = \Piwigo\Bootstrap\PresentationAccessor::mailService()
                    ->generateSetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            } else {
                $email_params = \Piwigo\Bootstrap\PresentationAccessor::mailService()
                    ->generateResetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            }
            // Here we remove the display of errors because they prevent the response from being parsed
            if (@\Piwigo\Bootstrap\PresentationAccessor::mailService()->mail($user_lost_email, $email_params)) {
                $send_by_mail_response = 'Mail sent at : ' . $user_lost_email;
            } else {
                $send_by_mail_response = false;
            }
        }
        \Piwigo\Bootstrap\PresentationAccessor::mailService()
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
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $new_main_user_id = \Piwigo\Common\ValueObject\UserId::from($params['user_id']);

        // checl if user exist
        if (self::userService()->getUsername($new_main_user_id) === false) {
            return new PwgError(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        $new_main_user = self::userService()->getUserData($new_main_user_id);

        // check if the user to set as main user is not webmaster
        if ($new_main_user['status'] !== 'webmaster') {
            return new PwgError(403, 'This user cannot become a main user because he is not a webmaster.');
        }

        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('webmaster_id', $params['user_id']);
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
     * @return PwgError|array{auth_key: string, apikey_secret: string, apikey_name: string, user_id: int, created_on: string, duration: int, key_type: string, expired_on: string}
     */
    public static function createApiKey(array $params, PwgServer &$service): PwgError|array
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        if (AccessControl::isAGuest() or ! self::apiKeyService()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if ($params['duration'] < 1 or $params['duration'] > 999999) {
            return new PwgError(400, 'Invalid duration max days is 999999');
        }

        if (strlen($params['key_name']) > 100) {
            return new PwgError(400, 'Key name is too long');
        }

        // realEscapeString() dropped: ApiKeyRepository::insert() parameterizes
        // apikey_name instead of interpolating it, same "dead pre-escaping"
        // rationale as Ws\PwgTags::rename() (Phase 1f step 3).
        $key_name = $params['key_name'];
        // the guard above already rejects any duration outside [1, 999999], so
        // it can never be 0 here.
        $duration = $params['duration'];

        $user_id = \Piwigo\Users\CurrentUser::get()->id->value;

        $secret = self::apiKeyService()->create($user_id, $duration, $key_name);

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
        $logger = \Piwigo\Core\CurrentLogger::get();

        if (AccessControl::isAGuest() or ! self::apiKeyService()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['pkid'])) {
            return new PwgError(403, Lang::t('Invalid pkid format'));
        }

        $user_id = \Piwigo\Users\CurrentUser::get()->id->value;

        $revoked_key = self::apiKeyService()->revoke($user_id, $params['pkid']);

        if ($revoked_key !== true) {
            return new PwgError(403, $revoked_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=revoke][pkid=' . $params['pkid'] . ']');

        return Lang::t('API Key has been successfully revoked.');
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
        $logger = \Piwigo\Core\CurrentLogger::get();

        if (AccessControl::isAGuest()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (! self::apiKeyService()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['pkid'])) {
            return new PwgError(403, Lang::t('Invalid pkid format'));
        }

        // realEscapeString() dropped: ApiKeyRepository::updateName()
        // parameterizes apikey_name instead of interpolating it, same
        // "dead pre-escaping" rationale as createApiKey() above.
        $key_name = $params['key_name'];
        $user_id = \Piwigo\Users\CurrentUser::get()->id->value;
        $edited_key = self::apiKeyService()->edit($user_id, $params['pkid'], $key_name);

        if ($edited_key !== true) {
            return new PwgError(403, $edited_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=edit][pkid=' . $params['pkid'] . '][new_name=' . $key_name . ']');

        return Lang::t('API Key has been successfully edited.');
    }

    /**
     * API method
     * Get all api key for the current user
     * @since 15
     *
     * @param array{pwg_token: string, ...} $params no 'default' key --
     *   mandatory, always present, no 'type' flag.
     * @return PwgError|string|list<array{auth_key: string, apikey_secret: string, apikey_name: string, created_on: string, duration: ?int, expired_on: string, revoked_on: ?string, last_used_on: ?string, last_notified_on: ?string, created_on_format: string, expired_on_format: string, last_used_on_since: string, is_expired: bool, expiration: string, expired_on_since: string, revoked_on_since: ?string, revoked_on_message: ?string}>
     */
    public static function getApiKey(array $params, PwgServer &$service): PwgError|array|string
    {
        if (AccessControl::isAGuest()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (! self::apiKeyService()->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // P23 batch 8e-6: fixes task #473, a real bug predating this
        // migration -- ApiKeyService::get() takes a native int $userId (see
        // its own signature), but this call previously cast $user_id to a
        // *string* (matching a stale docblock claim that get_api_key()
        // "requires a string $user_id", which was itself just describing
        // the bug). Every other ApiKeyService method here (create/revoke/
        // edit) already passes a plain int for the same user id value.
        $user_id = \Piwigo\Users\CurrentUser::get()->id->value;
        $api_keys = self::apiKeyService()->get($user_id);

        return ((bool) $api_keys) ? $api_keys : Lang::t('No API key found');
    }
}
