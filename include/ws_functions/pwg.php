<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns a list of missing derivatives (not generated yet)
 * @param array{types: array<int, string>, ids: array<int, int>, max_urls: int, prev_page: int|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
 *    types/ids: WS_PARAM_FORCE_ARRAY, null default -- never null
 *    (makeArrayParam() converts to []). max_urls: WS_TYPE_INT|POSITIVE,
 *    default 200 (non-null) -- always int. prev_page: WS_TYPE_INT|
 *    POSITIVE, null default -- int|null. f_* (see
 *    ws_std_image_sql_filter()'s docblock): shared filter set merged in
 *    via ws.php's $f_params.
 * @return \PwgError|array{next_page?: int|string, urls?: string[]}
 */
function ws_getMissingDerivatives(array $params, PwgServer &$service): \PwgError|array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (empty($params['types'])) {
        $types = array_keys(ImageStdParams::get_defined_type_map());
    } else {
        $types = array_intersect(array_keys(ImageStdParams::get_defined_type_map()), $params['types']);
        if (count($types) == 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid types');
        }
    }

    $max_urls = $params['max_urls'];
    $query = 'SELECT MAX(id)+1, COUNT(*) FROM ' . IMAGES_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$max_id, $image_count] = $row;
    // COUNT(*) is always numeric; MAX(id)+1 is numeric whenever rows
    // exist, which the $image_count == 0 early return below guarantees
    // for every later use of $max_id.
    $image_count = is_numeric($image_count) ? (int) $image_count : 0;
    $max_id = is_numeric($max_id) ? (int) $max_id : 0;

    if ($image_count == 0) {
        return [];
    }

    $start_id = $params['prev_page'];
    if ($start_id <= 0) {
        $start_id = $max_id;
    }

    $uid = '&b=' . time();

    $conf['question_mark_in_urls'] = $conf['php_extension_in_urls'] = true;
    $conf['derivative_url_style'] = 2; // script

    $qlimit = min(5000, ceil(max($image_count / 500, $max_urls / count($types))));
    $where_clauses = ws_std_image_sql_filter($params, '');
    $where_clauses[] = 'id<start_id';

    if (! empty($params['ids'])) {
        $where_clauses[] = 'id IN (' . implode(',', $params['ids']) . ')';
    }

    $query_model = '
SELECT id, path, representative_ext, width, height, rotation
  FROM ' . IMAGES_TABLE . '
  WHERE ' . implode(' AND ', $where_clauses) . '
  ORDER BY id DESC
  LIMIT ' . $qlimit . '
;';

    $urls = [];
    do {
        $result = pwg_query(str_replace('start_id', (string) $start_id, $query_model));
        $is_last = pwg_db_num_rows($result) < $qlimit;

        while ($row = pwg_db_fetch_assoc($result)) {
            $start_id = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $src_image = new SrcImage($row);
            if ($src_image->is_mimetype()) {
                continue;
            }

            foreach ($types as $type) {
                $derivative = new DerivativeImage($type, $src_image);
                if ($type != $derivative->get_type()) {
                    continue;
                }
                if (@filemtime($derivative->get_path()) === false) {
                    $urls[] = $derivative->get_url() . $uid;
                }
            }

            if (count($urls) >= $max_urls and ! $is_last) {
                break;
            }
        }
        if ($is_last) {
            $start_id = 0;
        }
    } while (count($urls) < $max_urls and $start_id);

    $ret = [];
    if ($start_id) {
        $ret['next_page'] = $start_id;
    }
    $ret['urls'] = $urls;
    return $ret;
}

/**
 * API method
 * Returns Piwigo version
 * @param mixed[] $params
 */
function ws_getVersion($params, PwgServer &$service): string
{
    return PHPWG_VERSION;
}

/**
 * API method
 * Returns general informations about the installation
 * @param mixed[] $params
 * @return array{infos: PwgNamedArray}
 */
function ws_getInfos($params, PwgServer &$service): array
{
    $infos['version'] = PHPWG_VERSION;

    $query = 'SELECT COUNT(*) FROM ' . IMAGES_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_elements']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . CATEGORIES_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_categories']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . CATEGORIES_TABLE . ' WHERE dir IS NULL;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_virtual']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . CATEGORIES_TABLE . ' WHERE dir IS NOT NULL;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_physical']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . IMAGE_CATEGORY_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_image_category']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . TAGS_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_tags']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_image_tag']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . USERS_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_users']] = $row;

    $query = 'SELECT COUNT(*) FROM `' . GROUPS_TABLE . '`;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_groups']] = $row;

    $query = 'SELECT COUNT(*) FROM ' . COMMENTS_TABLE . ';';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$infos['nb_comments']] = $row;

    // first element
    if ($infos['nb_elements'] > 0) {
        $query = 'SELECT MIN(date_available) FROM ' . IMAGES_TABLE . ';';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$infos['first_date']] = $row;
    }

    // unvalidated comments
    if ($infos['nb_comments'] > 0) {
        $query = 'SELECT COUNT(*) FROM ' . COMMENTS_TABLE . ' WHERE validated=\'false\';';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$infos['nb_unvalidated_comments']] = $row;
    }

    // Cache size
    // TODO for real later
    $infos['cache_size'] = 4242;

    foreach ($infos as $name => $value) {
        $output[] = [
            'name' => $name,
            'value' => $value,
        ];
    }
    return [
        'infos' => new PwgNamedArray($output, 'item'),
    ];
}

/**
 * API method
 * Calculates and returns the size of the cache
 *
 * @since 12
 * @param mixed[] $params
 * @return array{infos: PwgNamedArray}
 */
function ws_getCacheSize($params, PwgServer &$service): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $data_location = $conf['data_location'];
    $data_location = is_string($data_location) ? $data_location : '';

    // Cache size
    $path_cache = $data_location;
    $infos['cache_size'] = null;
    if (function_exists('exec')) {
        @exec('du -sk ' . $path_cache, $return_array_cache);
        if (
            ! empty($return_array_cache[0])
            and preg_match('/^(\d+)\s/', $return_array_cache[0], $matches_cache)
        ) {
            $infos['cache_size'] = $matches_cache[1] * 1024;
        }
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    // Multiples sizes size
    $path_msizes = $data_location . 'i';
    $msizes = get_cache_size_derivatives($path_msizes);

    $infos['msizes'] = array_fill_keys(array_keys(ImageStdParams::get_defined_type_map()), 0);
    $infos['msizes']['custom'] = 0;
    $all = 0;

    foreach (array_keys($infos['msizes']) as $size_type) {
        $current_size = $infos['msizes'][$size_type];

        // get_cache_size_derivatives()'s array<string, int> return type
        // doesn't capture that it's a sparse map -- it only contains keys
        // for derivative sizes that actually have files on disk (see its
        // real implementation, admin/include/functions.php), so a given
        // $size_type is genuinely, verifiably absent at runtime when no
        // such file exists. treatPhpDocTypesAsCertain makes PHPStan
        // (wrongly) prove this offset always exists and is always int;
        // @ suppresses the resulting real undefined-key warning, and the
        // two guards below are the actual runtime safety net, not dead code.
        $added_size = @$msizes[derivative_to_url($size_type)];
        // @phpstan-ignore function.alreadyNarrowedType
        $added_size = is_int($added_size) ? $added_size : 0;

        $infos['msizes'][$size_type] = $current_size + $added_size;
        $all += $infos['msizes'][$size_type];
    }
    $infos['msizes']['all'] = $all;

    // Compiled templates size
    $path_template_c = $data_location . 'templates_c';
    $infos['tsizes'] = null;
    if (function_exists('exec')) {
        @exec('du -sk ' . $path_template_c, $return_array_template_c);
        if (
            ! empty($return_array_template_c[0])
            and preg_match('/^(\d+)\s/', $return_array_template_c[0], $matches_template_c)
        ) {
            $infos['tsizes'] = $matches_template_c[1] * 1024;
        }
    }

    $infos['last_date_calc'] = date('Y-m-d H:i:s');

    /** @var array<int, mixed> $output */
    $output = [];
    foreach ($infos as $name => $value) {
        $output[] = [
            'name' => $name,
            'value' => $value,
        ];
    }

    conf_update_param('cache_sizes', $output, true);

    return [
        'infos' => new PwgNamedArray($output, 'item'),
    ];
}

/**
 * API method
 * Adds images to the caddie
 * @param array{image_id: array<int, int>, ...} $params image_id:
 *    WS_PARAM_FORCE_ARRAY|WS_TYPE_ID, mandatory (no 'default') -- always
 *    a list of positive ints.
 */
function ws_caddie_add(array $params, PwgServer &$service): int
{
    /** @var array<string, mixed> $user */
    global $user;

    $user_id = $user['id'];
    $user_id = is_numeric($user_id) ? (int) $user_id : 0;

    $query = '
SELECT id
  FROM ' . IMAGES_TABLE . '
      LEFT JOIN ' . CADDIE_TABLE . '
      ON id=element_id AND user_id=' . $user_id . '
  WHERE id IN (' . implode(',', $params['image_id']) . ')
    AND element_id IS NULL
;';
    $result = array_from_query($query, 'id');

    $datas = [];
    foreach ($result as $id) {
        $datas[] = [
            'element_id' => $id,
            'user_id' => $user_id,
        ];
    }
    if (count($datas)) {
        mass_inserts(
            CADDIE_TABLE,
            ['element_id', 'user_id'],
            $datas
        );
    }
    return count($datas);
}

/**
 * API method
 * Deletes rates of an user
 * @param array{user_id: int, anonymous_id: string|null, image_id?: int, ...} $params
 *    user_id: WS_TYPE_ID, mandatory -- always int. anonymous_id: no
 *    WS_TYPE flag, null default -- string|null. image_id:
 *    WS_PARAM_OPTIONAL with no 'default' -- may be entirely absent.
 */
function ws_rates_delete(array $params, PwgServer &$service): int|string
{
    $query = '
DELETE FROM ' . RATE_TABLE . '
  WHERE user_id=' . $params['user_id'];

    if (! empty($params['anonymous_id'])) {
        $query .= ' AND anonymous_id=\'' . $params['anonymous_id'] . '\'';
    }
    if (! empty($params['image_id'])) {
        $query .= ' AND element_id=' . $params['image_id'];
    }

    $changes = pwg_db_changes();
    if ($changes) {
        include_once PHPWG_ROOT_PATH . 'include/functions_rate.inc.php';
        update_rating_score();
    }
    return $changes;
}

/**
 * API method
 * Performs a login
 * @param array{username: string, password: string|null, ...} $params
 *    username: no WS_TYPE flag, mandatory -- always a plain string.
 *    password: no WS_TYPE flag, null default -- string|null.
 */
function ws_session_login(array $params, PwgServer &$service): \PwgError|true
{
    if (defined('PWG_API_KEY_REQUEST')) {
        return new PwgError(401, 'Cannot use this method with an api key');
    }

    if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', (string) $params['username'])) {
        $secret = pwg_db_real_escape_string($params['password']);
        $authenticate = auth_key_login($params['username'] . ':' . $secret);
        if ($authenticate) {
            $_SESSION['connected_with'] = 'ws_session_login_api_key';
            return true;
        }
    } elseif (try_log_user($params['username'], $params['password'], false)) {
        $_SESSION['connected_with'] = 'ws_session_login';
        return true;
    }
    return new PwgError(999, 'Invalid username/password');
}

/**
 * API method
 * Performs a logout
 * @param mixed[] $params
 */
function ws_session_logout($params, PwgServer &$service): \PwgError|true
{
    if (defined('PWG_API_KEY_REQUEST')) {
        return new PwgError(401, 'Cannot use this method with an api key');
    }

    if (! is_a_guest()) {
        logout_user();
    }
    return true;
}

/**
 * API method
 * Returns info about the current user
 * @param mixed[] $params
 * @return array<string, mixed>
 */
function ws_session_getStatus($params, PwgServer &$service): array
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, mixed> $conf
     */
    global $user, $conf;

    $username_raw = $user['username'];
    $username_raw = is_string($username_raw) ? $username_raw : '';
    $res['username'] = is_a_guest() ? 'guest' : stripslashes($username_raw);
    foreach (['status', 'theme', 'language'] as $k) {
        $res[$k] = $user[$k];
    }
    $res['pwg_token'] = get_pwg_token();
    $res['charset'] = get_pwg_charset();

    $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
    assert($row !== null);
    [$dbnow] = $row;
    $res['current_datetime'] = $dbnow;
    $res['version'] = PHPWG_VERSION;
    $res['save_visits'] = do_log();
    $res['connected_with'] = $_SESSION['connected_with'] ?? null;

    // Piwigo Remote Sync does not support receiving the new (version 14) output "save_visits"
    $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (is_string($http_user_agent) and preg_match('/^PiwigoRemoteSync/', $http_user_agent)) {
        unset($res['save_visits']);
        unset($res['connected_with']);
    }

    // Piwigo Remote Sync does not support receiving the available sizes
    $piwigo_remote_sync_agent = 'Apache-HttpClient/';
    if (! is_string($http_user_agent) or ! str_starts_with($http_user_agent, $piwigo_remote_sync_agent)) {
        $res['available_sizes'] = array_keys(ImageStdParams::get_defined_type_map());
    }

    if (is_admin()) {
        $upload_ext_list = $conf['upload_form_all_types'] ? $conf['file_ext'] : $conf['picture_ext'];
        $upload_ext_list = is_array($upload_ext_list) ? array_values(array_filter($upload_ext_list, 'is_string')) : [];

        $res['upload_file_types'] = implode(
            ',',
            array_unique(
                array_map(
                    strtolower(...),
                    $upload_ext_list
                )
            )
        );

        $chunk_size = $conf['upload_form_chunk_size'];
        $res['upload_form_chunk_size'] = is_numeric($chunk_size) ? (int) $chunk_size : 0;
    }

    return $res;
}

/**
 * API method
 * Returns lines of users activity
 *  @since 12
 * @param array{page: int|null, offset: int, uid: int|null, date_min: string|null, date_max: string|null, id: int|null, object: string|null, action: string|null, ...} $param
 *    page/uid/id: WS_TYPE_INT|POSITIVE or WS_TYPE_ID, null default ->
 *    int|null. offset: WS_TYPE_INT|POSITIVE, default 0 (non-null) ->
 *    always int. date_min/date_max/object/action: no WS_TYPE flag, null
 *    default -> string|null.
 * @return \PwgError|array{result_lines: array<int, array<string, mixed>>, page_offset: int, end_page: bool, params: array<string, mixed>}
 */
function ws_getActivityList(array $param, PwgServer &$service): \PwgError|array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    foreach (['date_min', 'date_max'] as $datefield) {
        if (! empty($param[$datefield]) and ! is_valid_mysql_datetime($param[$datefield])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid ' . $datefield);
        }
    }

    $output_lines = [];
    $current_key = '';
    $page_size = 100; // We will fetch X lines in database =/= lines displayed due to line concatenation
    // $page_offset = $param['page']*$page_size;
    $page_offset = $param['offset'];
    $nb_rows_to_fetch = 10000;

    $user_ids = [];

    $line_id = 0;

    // $min/$max are only read below when the same date_min/date_max
    // condition that sets them here is true again.
    $min = null;
    $max = null;
    if (! empty($param['date_min'])) {
        // is_valid_mysql_datetime() above already validated date_min; a
        // valid Y-m-d[ H:i:s] string always parses
        $min_date = date_create($param['date_min']);
        assert($min_date !== false);
        $min = date_format($min_date, 'Y-m-d H:i:s');

        // date_max may be empty/unvalidated/absent here — date_create()
        // only returns false on a genuinely malformed string, never on ''
        // (which means "now"), so a missing date_max is coalesced to ''
        $max_date = date_create($param['date_max'] ?? '');
        assert($max_date !== false);
        $max = date_format($max_date, 'Y-m-d 23:59:59');
    }

    if (! empty($param['date_max'])) {
        $max_date = date_create($param['date_max']);
        assert($max_date !== false);
        $max = date_format($max_date, 'Y-m-d 23:59:59');
    }

    $where = 'WHERE object != \'system\'';

    if (isset($param['uid'])) {
        $where .= '
    AND performed_by = ' . $param['uid'];
    }

    if (isset($param['action'])) {
        $where .= '
    AND action = "' . pwg_db_real_escape_string($param['action']) . '"';
    }

    if (isset($param['object'])) {
        $where .= '
    AND object = "' . pwg_db_real_escape_string($param['object']) . '"';
    }

    if (! empty($param['date_min'])) {
        $where .= '
    AND occured_on >= "' . $min . '"';
    }

    if (! empty($param['date_max'])) {
        $where .= '
    AND occured_on <= "' . $max . '"';
    }

    if (! empty($param['id'])) {
        $where .= '
    AND object_id = ' . $param['id'];
    }

    if ($conf['activity_display_connections'] == 'none') {
        $where .= '
    AND action NOT IN (\'login\', \'logout\')';
    } elseif ($conf['activity_display_connections'] == 'admins_only') {
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        $where .= '
    AND NOT (action IN (\'login\', \'logout\') AND object_id NOT IN (' . implode(',', get_admins()) . '))';
    }

    $more_rows_available = true;

    while (count($output_lines) < $page_size and $more_rows_available) {
        $query = '
SELECT
    activity_id,
    performed_by,
    object,
    object_id,
    action,
    session_idx,
    ip_address,
    occured_on,
    details,
    user_agent
  FROM ' . ACTIVITY_TABLE . '
  ' . $where . '
  ORDER BY activity_id DESC
  LIMIT ' . $nb_rows_to_fetch . ' OFFSET ' . $page_offset . '
;';
        $rows = query2array($query);

        if (count($rows) < $nb_rows_to_fetch) {
            $more_rows_available = false;
        }

        foreach ($rows as $row) {
            if (count($output_lines) < $page_size) {
                $page_offset++;

                $line_key = $row['session_idx'] . '~' . $row['object'] . '~' . $row['action'] . '~'; // idx~photo~add

                if ($line_key === $current_key) {
                    // I increment the counter of the previous line
                    $last_idx = count($output_lines) - 1;
                    $prev_counter = $output_lines[$last_idx]['counter'];
                    $output_lines[$last_idx]['counter'] = (is_int($prev_counter) ? $prev_counter : 0) + 1;

                    $prev_object_ids = $output_lines[$last_idx]['object_id'];
                    if (is_array($prev_object_ids)) {
                        $prev_object_ids[] = $row['object_id'];
                        $output_lines[$last_idx]['object_id'] = $prev_object_ids;
                    }
                } else {
                    $row['details'] = str_replace('`groups`', 'groups', (string) $row['details']);
                    $row['details'] = str_replace('`rank`', 'rank', $row['details']);
                    $details = @unserialize($row['details']);
                    if (! is_array($details)) {
                        $details = [];
                    }
                    $detailsType = null;

                    if (isset($row['user_agent'])) {
                        $details['agent'] = $row['user_agent'];
                    }

                    if (isset($details['method'])) {
                        $detailsType = 'method';
                    }

                    if (isset($details['script'])) {
                        $detailsType = 'script';
                    }

                    [$date, $hour] = explode(' ', (string) $row['occured_on']);
                    // New line
                    $output_lines[] = [
                        'id' => $line_id,
                        'object' => $row['object'],
                        'object_id' => [$row['object_id']],
                        'action' => $row['action'],
                        'ip_address' => $row['ip_address'],
                        'date' => format_date($date),
                        'hour' => $hour,
                        'user_id' => $row['performed_by'],
                        'detailsType' => $detailsType,
                        'details' => $details,
                        'counter' => 1,
                    ];

                    if ($row['performed_by'] !== null) {
                        $user_ids[$row['performed_by']] = 1;
                    }
                    if ($row['object'] == 'user' and $row['object_id'] !== null) {
                        $user_ids[$row['object_id']] = 1;
                    }

                    $current_key = $line_key;
                    $line_id++;
                }
            } else {
                $more_rows_available = true;
                break;
            }
        }
    }

    $username_of = [];
    $user_id_list = [];
    if (count($user_ids) > 0) {
        $user_fields = $conf['user_fields'];
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $user_field_id = $user_fields['id'] ?? 'id';
        $user_field_id = is_string($user_field_id) ? $user_field_id : 'id';
        $user_field_username = $user_fields['username'] ?? 'username';
        $user_field_username = is_string($user_field_username) ? $user_field_username : 'username';

        $query = '
SELECT
    `' . $user_field_id . '` AS user_id,
    `' . $user_field_username . '` AS username
  FROM ' . USERS_TABLE . '
  WHERE `' . $user_field_id . '` IN (' . implode(',', array_keys($user_ids)) . ')
;';
        $username_of = query2array($query, 'user_id', 'username');
    }

    foreach ($output_lines as $idx => $output_line) {
        if ($output_line['object'] == 'user' and is_array($output_line['object_id'])) {
            foreach ($output_line['object_id'] as $user_id) {
                if (! is_string($user_id) and ! is_int($user_id)) {
                    continue;
                }

                $details = $output_lines[$idx]['details'];
                if (! is_array($details)) {
                    $details = [];
                }

                $users = $details['users'] ?? [];
                if (! is_array($users)) {
                    $users = [];
                }

                $users[] = $username_of[$user_id] ?? 'user#' . $user_id;
                $details['users'] = $users;
                $output_lines[$idx]['details'] = $details;
            }

            $details = $output_lines[$idx]['details'];
            if (is_array($details) and isset($details['users']) and is_array($details['users'])) {
                $details['users_string'] = implode(', ', array_filter($details['users'], 'is_string'));
                $output_lines[$idx]['details'] = $details;
            }
        }

        $user_id_val = $output_lines[$idx]['user_id'];
        $user_id_key = is_string($user_id_val) || is_int($user_id_val) ? $user_id_val : '';
        $output_lines[$idx]['username'] = 'user#' . $user_id_key;
        if (isset($username_of[$user_id_key])) {
            $output_lines[$idx]['username'] = $username_of[$user_id_key];
        }
    }

    return [
        'result_lines' => $output_lines,
        'page_offset' => $page_offset,
        'end_page' => ! $more_rows_available,
        'params' => $param,
    ];
}

/**
 * API method
 * Log a new line in visit history
 * @since 13
 * @param array{image_id: int, cat_id: int|null, section: string|null, tags_string: string|null, is_download: bool, ...} $params
 *    image_id: WS_TYPE_ID, mandatory -- always int. cat_id: WS_TYPE_ID,
 *    null default -- int|null. section/tags_string: no WS_TYPE flag,
 *    null default -- string|null. is_download: WS_TYPE_BOOL, default
 *    false (non-null) -- always bool.
 */
function ws_history_log(array $params, PwgServer &$service): void
{
    /** @var array<string, mixed> $page */
    global $logger, $page;

    if (! empty($params['section']) and in_array($params['section'], get_enums(HISTORY_TABLE, 'section'))) {
        $page['section'] = $params['section'];
    }

    if (! empty($params['cat_id'])) {
        $page['category'] = [
            'id' => $params['cat_id'],
        ];
    }

    if (! empty($params['tags_string']) and preg_match('/^\d+(,\d+)*$/', (string) $params['tags_string'])) {
        $page['tag_ids'] = explode(',', (string) $params['tags_string']);
    }

    // when visiting a photo (which is currently, in version 14, the only event registered
    // by pwg.history.log) we should also increment images.hit
    if (! empty($params['image_id'])) {
        include_once PHPWG_ROOT_PATH . 'include/functions_picture.inc.php';
        increase_image_visit_counter($params['image_id']);
    }

    $image_type = 'picture';
    if ($params['is_download']) {
        $image_type = 'high';
    }

    pwg_log($params['image_id'], $image_type);
}

/**
 * API method
 * Returns lines of an history search
 * @since 13
 * @param array{start: string|null, end: string|null, types: array<int, string>, user_id: int|string, image_id: int|null, filename: string|null, ip: string|null, display_thumbnail: string, pageNumber: int|null, ...} $param
 *    start/end/filename/ip: no WS_TYPE flag, null default -- string|null.
 *    types: WS_PARAM_FORCE_ARRAY, non-null array default, no WS_TYPE flag
 *    -- always an array (never coerced element-wise). user_id: no
 *    WS_TYPE flag, non-null int default (-1) -- int if the default is
 *    used, otherwise the raw uncoerced request string. image_id:
 *    WS_TYPE_ID, null default -- int|null. display_thumbnail: no
 *    WS_TYPE flag, non-null string default -- always string.
 *    pageNumber: WS_TYPE_INT|POSITIVE, null default -- int|null.
 * @return array<string, mixed>
 */
function ws_history_search(array $param, PwgServer &$service): array
{

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    include_once PHPWG_ROOT_PATH . 'admin/include/functions_history.inc.php';

    /** @var array<string, mixed> $conf */
    global $conf;

    if (isset($_GET['start']) and is_numeric($_GET['start'])) {
        /** @var array<string, mixed> $page */
        $page['start'] = $_GET['start'];
    } else {
        /** @var array<string, mixed> $page */
        $page['start'] = 0;
    }

    $types = array_merge(['none'], get_enums(HISTORY_TABLE, 'image_type'));

    $display_thumbnails = [
        'no_display_thumbnail' => l10n('No display'),
        'display_thumbnail_classic' => l10n('Classic display'),
        'display_thumbnail_hoverbox' => l10n('Hoverbox display'),
    ];

    // +-----------------------------------------------------------------------+
    // | Build search criteria and redirect to results                         |
    // +-----------------------------------------------------------------------+

    $page['errors'] = [];
    $search = [];
    $search['fields'] = [];

    // date start
    if (! empty($param['start'])) {
        check_input_parameter('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
        $search['fields']['date-after'] = $param['start'];
    }

    // date end
    if (! empty($param['end'])) {
        check_input_parameter('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
        $search['fields']['date-before'] = $param['end'];
    }

    // types
    if (empty($param['types'])) {
        $search['fields']['types'] = $types;
    } else {
        check_input_parameter('types', $param, true, '/^(' . implode('|', $types) . ')$/');
        $search['fields']['types'] = $param['types'];
    }

    // user
    $search['fields']['user'] = intval($param['user_id']);

    // image
    if (! empty($param['image_id'])) {
        $search['fields']['image_id'] = intval($param['image_id']);
    }

    // filename
    if (! empty($param['filename'])) {
        // pwg_db_real_escape_string() only returns null for a null input,
        // and the empty() guard above already rules that out.
        $escaped_filename = pwg_db_real_escape_string($param['filename']);
        assert($escaped_filename !== null);
        $search['fields']['filename'] = str_replace('*', '%', $escaped_filename);
    }

    // ip
    if (! empty($param['ip'])) {
        $escaped_ip = pwg_db_real_escape_string($param['ip']);
        assert($escaped_ip !== null);
        $search['fields']['ip'] = str_replace('*', '%', $escaped_ip);
    }

    // thumbnails
    check_input_parameter('display_thumbnail', $param, false, '/^(' . implode('|', array_keys($display_thumbnails)) . ')$/');

    $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
    // Display choise are also save to one cookie
    if (! empty($param['display_thumbnail'])
        and isset($display_thumbnails[$param['display_thumbnail']])) {
        $cookie_val = $param['display_thumbnail'];
    } else {
        $cookie_val = null;
    }

    pwg_set_cookie_var('display_thumbnail', $cookie_val, strtotime('+1 month'));

    // TODO manage inconsistency of having $_POST['image_id'] and
    // $_POST['filename'] simultaneously

    // store seach in database
    // register search rules in database, then they will be available on
    // thumbnails page and picture page.
    $query = '
  INSERT INTO ' . SEARCH_TABLE . '
  (rules)
  VALUES
  (\'' . pwg_db_real_escape_string(serialize($search)) . '\')
  ;';

    pwg_query($query);

    $search_id = pwg_db_insert_id();

    // Remove redirect for ajax //
    // redirect(
    //   PHPWG_ROOT_PATH.'admin.php?page=history&search_id='.$search_id
    //   );

    // what are the lines to display in reality ?
    $query = '
SELECT rules
  FROM ' . SEARCH_TABLE . '
  WHERE id = ' . $search_id . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$serialized_rules] = $row;
    // this row is the one we just INSERTed above (via $search_id =
    // pwg_db_insert_id()) with a serialize() call we made ourselves, so
    // the 'rules' column is guaranteed to be a non-null string here.
    assert(is_string($serialized_rules));

    $page['search'] = unserialize($serialized_rules);

    /* TODO - no need to get a huge number of rows from db (should take only what needed for display + SQL_CALC_FOUND_ROWS */
    // trigger_change()'s return type is genuinely mixed (it dispatches
    // to whatever handler is registered for 'get_history'); narrow to the
    // list of row-arrays that get_history() actually returns.
    $data = trigger_change('get_history', [], $page['search'], $types);
    if (! is_array($data)) {
        $data = [];
    }
    // get_history() (the only real handler for the 'get_history' event, per
    // admin/include/functions_history.inc.php) returns array<int,
    // array<string, mixed>>; the trigger_change() dispatch above only
    // proved each element is an array, not that its keys are strings.
    /** @var array<int, array<string, mixed>> $data */
    $data = array_values(array_filter($data, is_array(...)));
    usort($data, history_compare(...));

    $page['nb_lines'] = count($data);

    // Number of ids of each kind
    $history_lines = [];
    $user_ids = [];
    $username_of = [];
    $category_ids = [];
    $image_ids = [];
    $has_tags = false;
    $search_ids = [];

    // get_history() (the real 'get_history' handler) builds each $row via
    // pwg_db_fetch_assoc(), so every field here is really string|null.
    foreach ($data as $row) {
        $row_user_id = $row['user_id'] ?? null;
        if (is_string($row_user_id)) {
            $user_ids[$row_user_id] = 1;
        }

        if (isset($row['category_id']) and is_string($row['category_id'])) {
            array_push($category_ids, $row['category_id']);
        }

        if (isset($row['image_id']) and is_string($row['image_id'])) {
            $image_ids[$row['image_id']] = 1;
        }

        if (isset($row['tag_ids'])) {
            $has_tags = true;
        }

        if (isset($row['search_id']) and is_string($row['search_id'])) {
            array_push($search_ids, $row['search_id']);
        }

        $history_lines[] = $row;
    }

    // prepare reference data (users, tags, categories...)
    // Declared unconditionally (not just inside the "if" below) so it is
    // always defined by the time it is read later, even when $search_ids
    // is empty.
    $search_details = [];
    if (count($search_ids) > 0) {
        $query = '
SELECT
    id,
    rules
  FROM ' . SEARCH_TABLE . '
  WHERE id IN (' . implode(',', $search_ids) . ')
;';
        $search_details_raw = query2array($query, 'id', 'rules');
        // Built into a fresh array (rather than mutating $search_details_raw
        // while iterating over it) so PHPStan can keep a precise
        // array<int|string, array<array-key, mixed>> element type instead of
        // widening to mixed from the in-place self-reassignment.
        foreach ($search_details_raw as $id_search => $rules_search_raw) {
            if (! is_string($rules_search_raw)) {
                continue;
            }

            $unserialized = safe_unserialize($rules_search_raw);
            $rules_search = is_array($unserialized) && isset($unserialized['fields']) && is_array($unserialized['fields'])
                ? $unserialized['fields']
                : [];

            $rules_tags = is_array($rules_search['tags'] ?? null) ? $rules_search['tags'] : [];
            if (! empty($rules_tags['words'])) {
                $has_tags = true;
            }

            $rules_cat = is_array($rules_search['cat'] ?? null) ? $rules_search['cat'] : [];
            if (! empty($rules_cat['words']) and is_array($rules_cat['words'])) {
                foreach ($rules_cat['words'] as $cat_id) {
                    if (is_string($cat_id) || is_int($cat_id)) {
                        $category_ids[] = (string) $cat_id;
                    }
                }
            }

            $rules_added_by = $rules_search['added_by'] ?? null;
            if (! empty($rules_added_by) and is_array($rules_added_by)) {
                foreach ($rules_added_by as $key) {
                    if (is_string($key) || is_int($key)) {
                        $user_ids[$key] = 1;
                    }
                }
            }

            $search_details[$id_search] = $rules_search;
        }
    }

    if (count($user_ids) > 0) {
        $user_fields = $conf['user_fields'];
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $user_field_id = $user_fields['id'] ?? 'id';
        $user_field_id = is_string($user_field_id) ? $user_field_id : 'id';
        $user_field_username = $user_fields['username'] ?? 'username';
        $user_field_username = is_string($user_field_username) ? $user_field_username : 'username';

        $query = '
SELECT ' . $user_field_id . ' AS id
     , ' . $user_field_username . ' AS username
  FROM ' . USERS_TABLE . '
  WHERE id IN (' . implode(',', array_keys($user_ids)) . ')
;';
        $result = pwg_query($query);

        $username_of = [];
        while ($row = pwg_db_fetch_assoc($result)) {
            if ($row['id'] === null) {
                continue;
            }
            $username_of[$row['id']] = stripslashes((string) $row['username']);
        }
    }

    $name_of_category = [];
    // Declared unconditionally (not just inside the "if" below), matching
    // $name_of_category above: it is read later regardless of whether
    // $category_ids was empty here, and previously stayed genuinely
    // undefined in that case (a real pre-existing bug, not just a PHPStan
    // false positive).
    $full_cat_path = [];
    if (count($category_ids) > 0) {
        $query = '
SELECT id, uppercats
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
        $uppercats_of = query2array($query, 'id', 'uppercats');

        foreach ($uppercats_of as $category_id => $uppercats) {
            if ($uppercats === null) {
                continue;
            }

            $full_cat_path[$category_id] = get_cat_display_name_cache(
                $uppercats,
                'admin.php?page=album-'
            );

            $uppercats = explode(',', $uppercats);
            $name_of_category[$category_id] = get_cat_display_name_cache(
                end($uppercats),
                'admin.php?page=album-'
            );
        }
    }

    $image_infos = [];
    if (count($image_ids) > 0) {
        $query = '
SELECT
    id,
    IF(name IS NULL, file, name) AS label,
    filesize,
    file,
    path,
    representative_ext
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', array_keys($image_ids)) . ')
;';
        $image_infos = query2array($query, 'id');
    }

    global $name_of_tag; // used for preg_replace
    $name_of_tag = [];
    if ($has_tags > 0) {
        $query = '
SELECT
    id,
    name, url_name
  FROM ' . TAGS_TABLE;

        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            if ($row['id'] === null) {
                continue;
            }
            $name_of_tag[$row['id']] = trigger_change('render_tag_name', $row['name'], $row);
        }
    }

    $page_start = $page['start'];
    $page_start = is_numeric($page_start) ? (int) $page_start : 0;

    $nb_logs_page = $conf['nb_logs_page'];
    $nb_logs_page = is_numeric($nb_logs_page) ? (int) $nb_logs_page : 0;

    $i = 0;
    $first_line = $page_start + 1;
    $last_line = $page_start + $nb_logs_page;

    /** @var array<string, mixed> $summary */
    $summary = [];
    $summary['total_filesize'] = 0;
    $summary['guests_IP'] = [];

    $result = [];
    $sorted_members = [];

    foreach ($history_lines as $line) {
        // every field of $line comes straight from get_history()'s
        // pwg_db_fetch_assoc() rows, so it is really string|null.
        $line_image_type = $line['image_type'] ?? null;
        $line_image_type = is_string($line_image_type) ? $line_image_type : null;
        $line_image_id = $line['image_id'] ?? null;
        $line_image_id = is_string($line_image_id) ? $line_image_id : null;
        $line_user_id = $line['user_id'] ?? null;
        $line_user_id = is_string($line_user_id) ? $line_user_id : null;
        $line_ip = $line['IP'] ?? null;
        $line_ip = is_string($line_ip) ? $line_ip : null;
        $line_tag_ids = $line['tag_ids'] ?? null;
        $line_tag_ids = is_string($line_tag_ids) ? $line_tag_ids : null;
        $line_search_id = $line['search_id'] ?? null;
        $line_search_id = is_string($line_search_id) ? $line_search_id : null;
        $line_date = $line['date'] ?? null;
        $line_date = is_string($line_date) ? $line_date : null;
        $line_time = $line['time'] ?? null;
        $line_time = is_string($line_time) ? $line_time : null;
        $line_section = $line['section'] ?? null;
        $line_section = is_string($line_section) ? $line_section : null;
        $line_category_id = $line['category_id'] ?? null;
        $line_category_id = is_string($line_category_id) ? $line_category_id : null;

        if ($line_image_type === 'high' and $line_image_id !== null) {
            // 'total_filesize' is only ever set to int by this same loop
            // (initialized to 0 above, then always int + int below).
            $running_total_filesize = $summary['total_filesize'];
            $summary['total_filesize'] = $running_total_filesize + @intval($image_infos[$line_image_id]['filesize'] ?? null);
        }

        if ($line_user_id == $conf['guest_id']) {
            $ip_key = $line_ip ?? '';
            // 'guests_IP' is only ever set to array by this same loop
            // (initialized to [] above, then always reassigned as array below).
            $guests_ip = $summary['guests_IP'];
            if (! isset($guests_ip[$ip_key])) {
                $guests_ip[$ip_key] = 0;
            }

            // always int: either the literal 0 just set above, or a value
            // this same loop previously wrote as int + 1.
            $guest_ip_count = $guests_ip[$ip_key];
            $guests_ip[$ip_key] = $guest_ip_count + 1;
            $summary['guests_IP'] = $guests_ip;
        }

        $i++;

        if ($i <= $first_line and $i >= $last_line) {
            continue;
        }

        $user_name = '#unknown';
        $user_string = '';
        $user_id_key = $line_user_id ?? '';
        if (isset($username_of[$user_id_key])) {
            $user_name = $username_of[$user_id_key];
            $user_string .= $username_of[$user_id_key];
        } else {
            $user_string .= $user_id_key;
        }
        $user_string .= '&nbsp;<a href="';
        $user_string .= PHPWG_ROOT_PATH . 'admin.php?page=history';
        $user_string .= '&amp;search_id=' . $search_id;
        $user_string .= '&amp;user_id=' . $user_id_key;
        $user_string .= '">+</a>';

        $tag_names = '';
        $tag_ids = '';
        if ($line_tag_ids !== null) {
            $tag_names = preg_replace_callback(
                '/(\d+)/',
                /**
                 * @param array<int, string> $m
                 */
                function (array $m) use ($name_of_tag): string {
                    $tag_id = $m[1];
                    $found = $name_of_tag[$tag_id] ?? $tag_id;
                    return is_string($found) ? $found : $tag_id;
                },
                $line_tag_ids
            );
            $tag_ids = $line_tag_ids;
        }

        $image_string = '';
        $image_title = '';
        $image_edit_string = '';
        $image_id = '';
        $cat_name = '';
        if ($line_image_id !== null) {
            $image_edit_string = PHPWG_ROOT_PATH . 'admin.php?page=photo-' . $line_image_id;
            $picture_url = make_picture_url(
                [
                    'image_id' => $line_image_id,
                ]
            );

            $element = [];
            if (isset($image_infos[$line_image_id])) {
                $element = [
                    'id' => $line_image_id,
                    'file' => $image_infos[$line_image_id]['file'],
                    'path' => $image_infos[$line_image_id]['path'],
                    'representative_ext' => $image_infos[$line_image_id]['representative_ext'],
                ];
                $page_search = $page['search'];
                $page_search_fields = is_array($page_search) ? ($page_search['fields'] ?? null) : null;
                $thumbnail_display = is_array($page_search_fields) ? ($page_search_fields['display_thumbnail'] ?? 'no_display_thumbnail') : 'no_display_thumbnail';
            } else {
                $thumbnail_display = 'no_display_thumbnail';
            }

            $image_title = '';

            if (isset($image_infos[$line_image_id]['label'])) {
                $rendered_label = trigger_change('render_element_description', $image_infos[$line_image_id]['label']);
                $image_title .= ' ' . (is_string($rendered_label) ? $rendered_label : '');
            } else {
                $image_edit_string = '';
                $image_title .= ' unknown filename';
            }

            $image_string = '';
            $image_id = $line_image_id;

            $image_string =
            '<span><img src="' . @DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $element)
            . '" alt="' . $image_title . '" title="' . $image_title . '">';
        }

        if ($line_search_id !== null) {
            $search_detail_fields = $search_details[$line_search_id] ?? null;
            $search_detail_fields = is_array($search_detail_fields) ? $search_detail_fields : [];

            $allwords_words = is_array($search_detail_fields['allwords'] ?? null) ? ($search_detail_fields['allwords']['words'] ?? null) : null;

            $tags_words = is_array($search_detail_fields['tags'] ?? null) ? ($search_detail_fields['tags']['words'] ?? null) : null;
            $tags_words = is_array($tags_words) ? array_values(array_filter($tags_words, 'is_string')) : null;

            $date_posted = $search_detail_fields['date_posted'] ?? null;

            $cat_words = is_array($search_detail_fields['cat'] ?? null) ? ($search_detail_fields['cat']['words'] ?? null) : null;
            $cat_words = is_array($cat_words) ? array_values(array_filter($cat_words, 'is_string')) : null;

            $author_words = is_array($search_detail_fields['author'] ?? null) ? ($search_detail_fields['author']['words'] ?? null) : null;

            $added_by = $search_detail_fields['added_by'] ?? null;
            $added_by = is_array($added_by) ? array_values(array_filter($added_by, 'is_string')) : null;

            $filetypes = $search_detail_fields['filetypes'] ?? null;

            $search_detail = [
                'allwords' => ! empty($allwords_words) ? $allwords_words : null,
                'tags' => ! empty($tags_words) ? array_intersect_key($name_of_tag, array_flip($tags_words)) : null,
                'date_posted' => ! empty($date_posted) ? $date_posted : null,
                'cat' => ! empty($cat_words) ? array_intersect_key($name_of_category, array_flip($cat_words)) : null,
                'author' => ! empty($author_words) ? $author_words : null,
                'added_by' => ! empty($added_by) ? array_intersect_key($username_of, array_flip($added_by)) : null,
                'filetypes' => ! empty($filetypes) ? $filetypes : null,
            ];
        } else {
            $search_detail = null;
        }

        @++$sorted_members[$user_name];

        array_push(
            $result,
            [
                'DATE' => format_date($line_date ?? ''),
                'TIME' => $line_time,
                'USER' => $user_string,
                'USERNAME' => $user_name,
                'USERID' => $line_user_id,
                'IP' => $line_ip,
                'IMAGE' => $image_string,
                'IMAGENAME' => $image_title,
                'IMAGEID' => $image_id,
                'EDIT_IMAGE' => $image_edit_string,
                'TYPE' => $line_image_type,
                'SECTION' => $line_section,
                'FULL_CATEGORY_PATH' => $line_category_id !== null && isset($full_cat_path[$line_category_id]) ? strip_tags($full_cat_path[$line_category_id]) : l10n('Root') . $line_category_id,
                'CATEGORY' => $line_category_id !== null && isset($name_of_category[$line_category_id]) ? $name_of_category[$line_category_id] : l10n('Root') . $line_category_id,
                'SEARCH_ID' => $line_search_id,
                'TAGS' => explode(',', (string) $tag_names),
                'TAGIDS' => explode(',', $tag_ids),
                'SEARCH_DETAILS' => $search_detail,
            ]
        );
    }

    $max_page = ceil(count($result) / 300);
    $result = array_reverse($result, true);
    $result = array_slice($result, $param['pageNumber'] * 300, 300);

    // always array: see the loop-invariant comment on 'guests_IP' above.
    $guests_ip_final = $summary['guests_IP'];

    $summary['nb_guests'] = 0;
    if (count(array_keys($guests_ip_final)) > 0) {
        $summary['nb_guests'] = count(array_keys($guests_ip_final));

        // we delete the "guest" from the $username_of hash so that it is
        // avoided in next steps
        $guest_id = $conf['guest_id'];
        $guest_id_key = is_string($guest_id) || is_int($guest_id) ? $guest_id : null;
        if ($guest_id_key !== null) {
            unset($username_of[$guest_id_key]);
        }
    }

    $summary['nb_members'] = count($username_of);

    $member_strings = [];
    foreach ($username_of as $user_id => $user_name) {
        $member_string = $user_name;
        $member_strings[] = [
            $member_string => $user_id,
        ];
    }

    arsort($sorted_members);
    unset($sorted_members['guest']);

    // 'total_filesize'/'nb_members'/'nb_guests' are only ever set to int by
    // this same function (see the loop-invariant comments above, plus the
    // count()-based assignments for nb_members/nb_guests); 'nb_lines' is set
    // once above from count($data).
    $summary_total_filesize = $summary['total_filesize'];
    $summary_nb_members = $summary['nb_members'];
    $summary_nb_guests = $summary['nb_guests'];
    $page_nb_lines = $page['nb_lines'];

    $search_summary =
    [
        'NB_LINES' => l10n_dec(
            '%d line filtered',
            '%d lines filtered',
            $page_nb_lines
        ),
        'FILESIZE' => $summary_total_filesize != 0 ? ceil($summary_total_filesize / 1024) : 0,
        'USERS' => l10n_dec(
            '%d user',
            '%d users',
            $summary_nb_members + $summary_nb_guests
        ),
        'MEMBERS' => $member_strings,
        'SORTED_MEMBERS' => $sorted_members,
        'GUESTS' => l10n_dec(
            '%d guest',
            '%d guests',
            $summary_nb_guests
        ),
    ];

    unset($name_of_tag);

    return [
        'lines' => $result,
        'params' => $param,
        'maxPage' => ($max_page == 0) ? 1 : $max_page,
        'summary' => $search_summary,
    ];
}
