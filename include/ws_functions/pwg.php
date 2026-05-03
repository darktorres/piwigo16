<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;

/**
 * Returns the total byte size of a directory tree, or null if it is missing
 * or unreadable. Pure-PHP replacement for `du -sk` — works on Windows where
 * du is not available.
 */
function ws_directory_size_bytes(string $path): ?int
{
    if (!is_dir($path)) {
        return null;
    }
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile()) {
            $bytes += $entry->getSize();
        }
    }
    return $bytes;
}

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns a list of missing derivatives (not generated yet)
 * @param mixed[] $params
 *    @option string types (optional)
 *    @option int[] ids
 *    @option int max_urls
 *    @option int prev_page (optional)
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_getMissingDerivatives(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    if (empty($params['types'])) {
        $types = array_keys(ImageStdParams::get_defined_type_map());
    } else {
        $types_raw = is_array($params['types']) ? $params['types'] : [];
        $types = array_intersect(array_keys(ImageStdParams::get_defined_type_map()), array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $types_raw));
        if (count($types) == 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid types');
        }
    }

    $max_urls = is_numeric($params['max_urls']) ? (int) $params['max_urls'] : 0;
    $query = 'SELECT MAX(id)+1, COUNT(*) FROM '. IMAGES_TABLE .';';
    [$max_id, $image_count] = pwg_db_fetch_row(pwg_query($query)) ?? [null, null];

    if (0 == $image_count) {
        return [];
    }

    $image_count_int = is_numeric($image_count) ? (int) $image_count : 0;
    $start_id = is_numeric($params['prev_page']) ? (int) $params['prev_page'] : 0;
    if ($start_id <= 0) {
        $start_id = is_numeric($max_id) ? (int) $max_id : 0;
    }

    $uid = '&b='.time();

    \Piwigo\Config\Config::override('question_mark_in_urls', true);
    \Piwigo\Config\Config::override('php_extension_in_urls', true);
    \Piwigo\Config\Config::override('derivative_url_style', 2); //script

    $qlimit = min(5000, (int) ceil(max($image_count_int / 500, $max_urls / count($types))));
    /** @var array<string> $where_clauses */
    $where_clauses = ws_std_image_sql_filter($params, '');
    $where_clauses[] = 'id<start_id';

    if (!empty($params['ids'])) {
        $ids_arr = is_array($params['ids']) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $params['ids']) : [];
        $where_clauses[] = 'id IN ('.implode(',', $ids_arr).')';
    }

    $query_model = '
SELECT id, path, representative_ext, width, height, rotation
  FROM '. IMAGES_TABLE .'
  WHERE '. implode(' AND ', $where_clauses) .'
  ORDER BY id DESC
  LIMIT '. $qlimit .'
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
                    $url = $derivative->get_url();
                    $urls[] = (is_string($url) ? $url : '').$uid;
                }
            }

            if (count($urls) >= $max_urls and !$is_last) {
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
function ws_getVersion($params, \Piwigo\Ws\PwgServer &$service): string
{
    return PHPWG_VERSION;
}

/**
 * API method
 * Returns general informations about the installation
 * @param mixed[] $params
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_getInfos(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    $infos['version'] = PHPWG_VERSION;

    $query = 'SELECT COUNT(*) FROM '.IMAGES_TABLE.';';
    [$infos['nb_elements']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.CATEGORIES_TABLE.';';
    [$infos['nb_categories']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.CATEGORIES_TABLE.' WHERE dir IS NULL;';
    [$infos['nb_virtual']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.CATEGORIES_TABLE.' WHERE dir IS NOT NULL;';
    [$infos['nb_physical']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.';';
    [$infos['nb_image_category']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.TAGS_TABLE.';';
    [$infos['nb_tags']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.IMAGE_TAG_TABLE.';';
    [$infos['nb_image_tag']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.USERS_TABLE.';';
    [$infos['nb_users']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM `'.GROUPS_TABLE.'`;';
    [$infos['nb_groups']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $query = 'SELECT COUNT(*) FROM '.COMMENTS_TABLE.';';
    [$infos['nb_comments']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    // first element
    if ($infos['nb_elements'] > 0) {
        $query = 'SELECT MIN(date_available) FROM '.IMAGES_TABLE.';';
        [$infos['first_date']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    }

    // unvalidated comments
    if ($infos['nb_comments'] > 0) {
        $query = 'SELECT COUNT(*) FROM '.COMMENTS_TABLE.' WHERE validated=\'false\';';
        [$infos['nb_unvalidated_comments']] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
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
    return ['infos' => new PwgNamedArray($output, 'item')];
}

/**
 * API method
 * Calculates and returns the size of the cache
 *
 * @since 12
 * @param mixed[] $params
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_getCacheSize(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    // Cache size
    $path_cache = \Piwigo\Config\Config::dataLocation();
    $infos['cache_size'] = ws_directory_size_bytes($path_cache);

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    // Multiples sizes size
    $path_msizes = \Piwigo\Config\Config::dataLocation().'i';
    $msizes = get_cache_size_derivatives($path_msizes);

    $infos['msizes'] = array_fill_keys(array_keys(ImageStdParams::get_defined_type_map()), 0);
    $infos['msizes']['custom'] = 0;
    $all = 0;

    foreach (array_keys($infos['msizes']) as $size_type) {
        $infos['msizes'][$size_type] += @$msizes[derivative_to_url($size_type)];
        $all += $infos['msizes'][$size_type];
    }
    $infos['msizes']['all'] = $all;

    // Compiled templates size
    $path_template_c = \Piwigo\Config\Config::dataLocation().'templates_c';
    $infos['tsizes'] = ws_directory_size_bytes($path_template_c);

    $infos['last_date_calc'] = date('Y-m-d H:i:s');

    foreach ($infos as $name => $value) {
        $output[] = [
          'name' => $name,
          'value' => $value,
        ];
    }

    conf_update_param('cache_sizes', $output, true);

    return ['infos' => new PwgNamedArray($output, 'item')];
}

/**
 * API method
 * Adds images to the caddie
 * @param mixed[] $params
 *    @option int[] image_id
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_caddie_add(array $params, \Piwigo\Ws\PwgServer &$service): int
{
    $userId = \Piwigo\Users\CurrentUser::get()->id;

    $query = '
SELECT id
  FROM '. IMAGES_TABLE .'
      LEFT JOIN '. CADDIE_TABLE .'
      ON id=element_id AND user_id='. $userId .'
  WHERE id IN ('. implode(',', array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, is_array($params['image_id']) ? $params['image_id'] : [])) .')
    AND element_id IS NULL
;';
    $result = query2array($query, null, 'id');

    $datas = [];
    foreach ($result as $id) {
        $datas[] = [
          'element_id' => $id,
          'user_id' => $userId,
          ];
    }
    if (count($datas)) {
        mass_inserts(
            CADDIE_TABLE,
            ['element_id','user_id'],
            $datas
        );
    }
    return count($datas);
}

/**
 * API method
 * Deletes rates of an user
 * @param mixed[] $params
 *    @option int user_id
 *    @option string anonymous_id (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_rates_delete(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    $user_id = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
    $query = '
DELETE FROM '. RATE_TABLE .'
  WHERE user_id='. $user_id;

    if (!empty($params['anonymous_id'])) {
        $query .= ' AND anonymous_id=\''. (is_scalar($params['anonymous_id']) ? (string) $params['anonymous_id'] : '') .'\'';
    }
    if (!empty($params['image_id'])) {
        $query .= ' AND element_id='. (is_numeric($params['image_id']) ? (int) $params['image_id'] : 0);
    }

    $changes = pwg_db_changes();
    if ($changes) {
        include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
        update_rating_score();
    }
    return $changes;
}

/**
 * API method
 * Performs a login
 * @param mixed[] $params
 *    @option string username
 *    @option string password
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_session_login(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|true
{
    if (defined('PWG_API_KEY_REQUEST')) {
        return new PwgError(401, 'Cannot use this method with an api key');
    }

    $username = is_scalar($params['username']) ? (string) $params['username'] : '';
    $password = is_scalar($params['password']) ? (string) $params['password'] : '';
    if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $username)) {
        $secret = pwg_db_real_escape_string($password);
        $authenticate = auth_key_login($username.':'.$secret);
        if ($authenticate) {
            $_SESSION['connected_with'] = 'ws_session_login_api_key';
            return true;
        }
    } elseif (try_log_user($username, $password, false)) {
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
function ws_session_logout($params, \Piwigo\Ws\PwgServer &$service): PwgError|true
{
    if (defined('PWG_API_KEY_REQUEST')) {
        return new PwgError(401, 'Cannot use this method with an api key');
    }

    if (!is_a_guest()) {
        logout_user();
    }
    return true;
}

/**
 * API method
 * Returns info about the current user
 * @param mixed[] $params
 */
function ws_session_getStatus($params, \Piwigo\Ws\PwgServer &$service): mixed
{
    $currentUser = \Piwigo\Users\CurrentUser::get();
    $res['username'] = is_a_guest() ? 'guest' : stripslashes($currentUser->username);
    $res['status'] = $currentUser->status;
    $res['theme'] = $currentUser->theme;
    $res['language'] = $currentUser->language;
    $res['pwg_token'] = get_pwg_token();
    $res['charset'] = get_pwg_charset();

    [$dbnow] = pwg_db_fetch_row(pwg_query('SELECT NOW();')) ?? [null];
    $res['current_datetime'] = $dbnow;
    $res['version'] = PHPWG_VERSION;
    $res['save_visits'] = do_log();
    $res['connected_with'] = $_SESSION['connected_with'] ?? null;

    // Piwigo Remote Sync does not support receiving the new (version 14) output "save_visits"
    $http_user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ($http_user_agent !== '' and preg_match('/^PiwigoRemoteSync/', $http_user_agent)) {
        unset($res['save_visits']);
        unset($res['connected_with']);
    }

    // Piwigo Remote Sync does not support receiving the available sizes
    $piwigo_remote_sync_agent = 'Apache-HttpClient/';
    if ($http_user_agent === '' or !str_starts_with($http_user_agent, $piwigo_remote_sync_agent)) {
        $res['available_sizes'] = array_keys(ImageStdParams::get_defined_type_map());
    }

    if (is_admin()) {
        $res['upload_file_types'] = implode(
            ',',
            array_unique(
                array_map(
                    strtolower(...),
                    \Piwigo\Config\Config::uploadFormAllTypes() ? \Piwigo\Config\Config::fileExtensions() : \Piwigo\Config\Config::pictureExtensions()
                )
            )
        );

        $res['upload_form_chunk_size'] = \Piwigo\Config\Config::uploadFormChunkSize();
    }

    return $res;
}

/**
 * API method
 * Returns lines of users activity
 *  @since 12
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $param
 * @param array<mixed> $param
 */function ws_getActivityList(array $param, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    foreach (['date_min', 'date_max'] as $datefield) {
        if (!empty($param[$datefield]) and !is_valid_mysql_datetime(is_scalar($param[$datefield]) ? (string) $param[$datefield] : '')) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid '.$datefield);
        }
    }

    $output_lines = [];
    $current_key = '';
    $page_size = 100; //We will fetch X lines in database =/= lines displayed due to line concatenation
    //$page_offset = $param['page']*$page_size;
    $page_offset = is_numeric($param['offset']) ? (int) $param['offset'] : 0;
    $nb_rows_to_fetch = 10000;

    $user_ids = [];

    $line_id = 0;

    $min = '';
    $max = '';
    if (!empty($param['date_min'])) {
        $date_min_str = is_scalar($param['date_min']) ? (string) $param['date_min'] : '';
        $date_max_str = isset($param['date_max']) && is_scalar($param['date_max']) ? (string) $param['date_max'] : '';
        $dmin = date_create($date_min_str);
        $dmax = $date_max_str !== '' ? date_create($date_max_str) : false;
        $min = $dmin !== false ? date_format($dmin, 'Y-m-d H:i:s') : '';
        $max = $dmax !== false ? date_format($dmax, 'Y-m-d 23:59:59') : '';
    }

    if (!empty($param['date_max'])) {
        $date_max_str2 = is_scalar($param['date_max']) ? (string) $param['date_max'] : '';
        $dmax2 = date_create($date_max_str2);
        $max = $dmax2 !== false ? date_format($dmax2, 'Y-m-d 23:59:59') : '';
    }

    $where = 'WHERE object != \'system\'';

    if (isset($param['uid'])) {
        $where .= '
    AND performed_by = '. (is_numeric($param['uid']) ? (int) $param['uid'] : 0);
    }

    if (isset($param['action'])) {
        $where .= '
    AND action = "'.pwg_db_real_escape_string(is_scalar($param['action']) ? (string) $param['action'] : '').'"';
    }

    if (isset($param['object'])) {
        $where .= '
    AND object = "'.pwg_db_real_escape_string(is_scalar($param['object']) ? (string) $param['object'] : '').'"';
    }

    if (!empty($param['date_min'])) {
        $where .= '
    AND occured_on >= "'.$min.'"';
    }

    if (!empty($param['date_max'])) {
        $where .= '
    AND occured_on <= "'.$max.'"';
    }

    if (!empty($param['id'])) {
        $where .= '
    AND object_id = '. (is_numeric($param['id']) ? (int) $param['id'] : 0);
    }

    if ('none' == \Piwigo\Config\Config::activityDisplayConnections()) {
        $where .= '
    AND action NOT IN (\'login\', \'logout\')';
    } elseif ('admins_only' == \Piwigo\Config\Config::activityDisplayConnections()) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
        $where .= '
    AND NOT (action IN (\'login\', \'logout\') AND object_id NOT IN ('.implode(',', get_admins()).'))';
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
  FROM '.ACTIVITY_TABLE.'
  '.$where.'
  ORDER BY activity_id DESC
  LIMIT '.$nb_rows_to_fetch.' OFFSET '.$page_offset.'
;';
        $rows = query2array($query);

        if (count($rows) < $nb_rows_to_fetch) {
            $more_rows_available = false;
        }

        foreach ($rows as $row) {
            if (count($output_lines) < $page_size) {
                $page_offset++;

                $row_session_idx = is_scalar($row['session_idx']) ? (string) $row['session_idx'] : '';
                $row_object = is_scalar($row['object']) ? (string) $row['object'] : '';
                $row_action = is_scalar($row['action']) ? (string) $row['action'] : '';
                $line_key = $row_session_idx.'~'.$row_object.'~'.$row_action.'~'; // idx~photo~add

                if ($line_key === $current_key) {
                    // I increment the counter of the previous line
                    $output_lines[count($output_lines) - 1]['counter']++;
                    $output_lines[count($output_lines) - 1]['object_id'][] = $row['object_id'];
                } else {
                    $row_details_str = is_scalar($row['details']) ? (string) $row['details'] : '';
                    $row_details_str = str_replace('`groups`', 'groups', $row_details_str);
                    $row_details_str = str_replace('`rank`', 'rank', $row_details_str);
                    $details_raw = @unserialize($row_details_str);
                    $details = is_array($details_raw) ? $details_raw : [];

                    if (isset($row['user_agent'])) {
                        $details['agent'] = $row['user_agent'];
                    }

                    $detailsType = '';
                    if (isset($details['method'])) {
                        $detailsType = 'method';
                    }

                    if (isset($details['script'])) {
                        $detailsType = 'script';
                    }

                    [$date, $hour] = explode(' ', is_scalar($row['occured_on']) ? (string) $row['occured_on'] : '');
                    $row_performed_by = $row['performed_by'];
                    // New line
                    $output_lines[] = [
                      'id' => $line_id,
                      'object' => $row_object,
                      'object_id' => [$row['object_id']],
                      'action' => $row_action,
                      'ip_address' => $row['ip_address'],
                      'date' => format_date($date),
                      'hour' => $hour,
                      'user_id' => $row_performed_by,
                      'detailsType' => $detailsType,
                      'details' => $details,
                      'counter' => 1,
                    ];

                    $user_ids_key_by = is_scalar($row_performed_by) ? (string) $row_performed_by : '';
                    if ($user_ids_key_by !== '') {
                        $user_ids[$user_ids_key_by] = 1;
                    }
                    if ('user' == $row_object) {
                        $obj_id = $row['object_id'];
                        $obj_id_key = is_scalar($obj_id) ? (string) $obj_id : '';
                        if ($obj_id_key !== '') {
                            $user_ids[$obj_id_key] = 1;
                        }
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
        $query = '
SELECT
    `'.\Piwigo\Config\Config::userFields()['id'].'` AS user_id,
    `'.\Piwigo\Config\Config::userFields()['username'].'` AS username
  FROM '.USERS_TABLE.'
  WHERE `'.\Piwigo\Config\Config::userFields()['id'].'` IN ('.implode(',', array_keys($user_ids)).')
;';
        $username_of = query2array($query, 'user_id', 'username');
    }

    foreach ($output_lines as $idx => $output_line) {
        if ('user' == ($output_line['object'] ?? '')) {
            $obj_ids = $output_line['object_id'];
            foreach ($obj_ids as $user_id) {
                $user_id_key = is_scalar($user_id) ? (string) $user_id : '';
                /** @var array<string, mixed> $details_arr */
                $details_arr = $output_lines[$idx]['details'] ?? [];
                /** @var list<mixed> $details_users */
                $details_users = is_array($details_arr['users'] ?? null) ? $details_arr['users'] : [];
                $details_users[] = (isset($username_of[$user_id_key]) ? $username_of[$user_id_key] : ('user#' . $user_id_key));
                $details_arr['users'] = $details_users;
                $output_lines[$idx]['details'] = $details_arr;
            }

            /** @var array<string, mixed> $details_arr2 */
            $details_arr2 = $output_lines[$idx]['details'];
            if (isset($details_arr2['users'])) {
                $users_arr = is_array($details_arr2['users']) ? $details_arr2['users'] : [];
                $details_arr2['users_string'] = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $users_arr));
                $output_lines[$idx]['details'] = $details_arr2;
            }
        }

        $line_user_id = $output_lines[$idx]['user_id'] ?? '';
        $line_user_id_str = (string) $line_user_id;
        $output_lines[$idx]['username'] = 'user#'.$line_user_id_str;
        if ($line_user_id_str !== '' && isset($username_of[$line_user_id_str])) {
            $output_lines[$idx]['username'] = $username_of[$line_user_id_str];
        }
    }

    return [
      'result_lines' => $output_lines,
      'page_offset' => $page_offset,
      'end_page' => !$more_rows_available,
      'params' => $param,
    ];
}

/**
 * API method
 * Log a new line in visit history
 * @since 13
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_history_log(array $params, \Piwigo\Ws\PwgServer &$service): void
{
    global $page;
    if (!empty($params['section']) and in_array($params['section'], get_enums(HISTORY_TABLE, 'section'))) {
        $page['section'] = $params['section'];
    }

    if (!empty($params['cat_id'])) {
        $page['category'] = ['id' => $params['cat_id']];
    }

    $tags_string = is_scalar($params['tags_string']) ? (string) $params['tags_string'] : '';
    if ($tags_string !== '' and preg_match('/^\d+(,\d+)*$/', $tags_string)) {
        $page['tag_ids'] = explode(',', $tags_string);
    }

    // when visiting a photo (which is currently, in version 14, the only event registered
    // by pwg.history.log) we should also increment images.hit
    $log_image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
    if (!empty($params['image_id'])) {
        include_once(PHPWG_ROOT_PATH.'include/functions_picture.inc.php');
        if ($log_image_id !== null) {
            increase_image_visit_counter($log_image_id);
        }
    }

    $image_type = 'picture';
    if ($params['is_download']) {
        $image_type = 'high';
    }

    pwg_log($log_image_id, $image_type);
}

/**
 * API method
 * Returns lines of an history search
 * @since 13
 */
/**
 * @return array<mixed>
 * @param array<mixed> $param
 * @param array<mixed> $param
 */function ws_history_search(array $param, \Piwigo\Ws\PwgServer &$service): array
{

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    include_once(PHPWG_ROOT_PATH.'admin/include/functions_history.inc.php');

    if (isset($_GET['start']) and is_numeric($_GET['start'])) {
        $page['start'] = $_GET['start'];
    } else {
        $page['start'] = 0;
    }

    $types = array_merge(['none'], get_enums(HISTORY_TABLE, 'image_type'));

    $display_thumbnails = ['no_display_thumbnail' => l10n('No display'),
                                'display_thumbnail_classic' => l10n('Classic display'),
                                'display_thumbnail_hoverbox' => l10n('Hoverbox display'),
      ];

    // +-----------------------------------------------------------------------+
    // | Build search criteria and redirect to results                         |
    // +-----------------------------------------------------------------------+

    $page['errors'] = [];
    $search = [];

    // date start
    if (!empty($param['start'])) {
        check_input_parameter('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
        $search['fields']['date-after'] = $param['start'];
    }

    // date end
    if (!empty($param['end'])) {
        check_input_parameter('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
        $search['fields']['date-before'] = $param['end'];
    }

    // types
    if (empty($param['types'])) {
        $search['fields']['types'] = $types;
    } else {
        check_input_parameter('types', $param, true, '/^('.implode('|', $types).')$/');
        $search['fields']['types'] = $param['types'];
    }

    // user
    $search['fields']['user'] = is_numeric($param['user_id']) ? (int) $param['user_id'] : 0;

    // image
    if (!empty($param['image_id'])) {
        $search['fields']['image_id'] = is_numeric($param['image_id']) ? (int) $param['image_id'] : 0;
    }

    // filename
    if (!empty($param['filename'])) {
        $search['fields']['filename'] = str_replace(
            '*',
            '%',
            pwg_db_real_escape_string(is_scalar($param['filename']) ? (string) $param['filename'] : '')
        );
    }

    // ip
    if (!empty($param['ip'])) {
        $search['fields']['ip'] = str_replace(
            '*',
            '%',
            pwg_db_real_escape_string(is_scalar($param['ip']) ? (string) $param['ip'] : '')
        );
    }

    // thumbnails
    check_input_parameter('display_thumbnail', $param, false, '/^('.implode('|', array_keys($display_thumbnails)).')$/');

    $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
    // Display choise are also save to one cookie
    $display_thumbnail_str = is_scalar($param['display_thumbnail']) ? (string) $param['display_thumbnail'] : '';
    if ($display_thumbnail_str !== ''
        and isset($display_thumbnails[$display_thumbnail_str])) {
        $cookie_val = $display_thumbnail_str;
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
  INSERT INTO '.SEARCH_TABLE.'
  (rules)
  VALUES
  (\''.pwg_db_real_escape_string(serialize($search)).'\')
  ;';

    pwg_query($query);

    $search_id = pwg_db_insert_id();

    // what are the lines to display in reality ?
    $query = '
SELECT rules
  FROM '.SEARCH_TABLE.'
  WHERE id = '.$search_id.'
;';
    [$serialized_rules] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    $page['search'] = unserialize(is_string($serialized_rules) ? $serialized_rules : '');


    /*TODO - no need to get a huge number of rows from db (should take only what needed for display + SQL_CALC_FOUND_ROWS*/
    /** @var list<array<string, mixed>> $data */
    $data = trigger_change('get_history', [], $page['search'], $types);
    usort($data, history_compare(...));

    $page['nb_lines'] = count($data);

    //Number of ids of each kind
    $history_lines = [];
    $user_ids = [];
    $username_of = [];
    $category_ids = [];
    $image_ids = [];
    $has_tags = false;
    $search_ids = [];

    foreach ($data as $row) {
        $row_user_id = $row['user_id'] ?? null;
        $row_user_id_key = is_scalar($row_user_id) ? (string) $row_user_id : '';
        if ($row_user_id_key !== '') {
            $user_ids[$row_user_id_key] = 1;
        }

        if (isset($row['category_id'])) {
            array_push($category_ids, $row['category_id']);
        }

        if (isset($row['image_id'])) {
            $row_image_id = $row['image_id'];
            $row_image_id_key = is_scalar($row_image_id) ? (string) $row_image_id : '';
            if ($row_image_id_key !== '') {
                $image_ids[$row_image_id_key] = 1;
            }
        }

        if (isset($row['tag_ids'])) {
            $has_tags = true;
        }

        if (isset($row['search_id'])) {
            array_push($search_ids, $row['search_id']);
        }

        $history_lines[] = $row;
    }

    // prepare reference data (users, tags, categories...)
    if (count($search_ids) > 0) {
        $search_ids_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $search_ids);
        $query = '
SELECT
    id,
    rules
  FROM '.SEARCH_TABLE.'
  WHERE id IN ('.implode(',', $search_ids_str).')
;';
        $search_details = query2array($query, 'id', 'rules');

        foreach ($search_details as $id_search => $rules_search) {
            $rules_arr = safe_unserialize(is_scalar($rules_search) ? (string) $rules_search : '');
            $rules_fields = is_array($rules_arr['fields'] ?? null) ? $rules_arr['fields'] : [];
            $rf_tags = is_array($rules_fields['tags'] ?? null) ? $rules_fields['tags'] : [];
            $rf_cat = is_array($rules_fields['cat'] ?? null) ? $rules_fields['cat'] : [];
            if (!empty($rf_tags['words'])) {
                $has_tags = true;
            }

            if (!empty($rf_cat['words'])) {
                $cat_words = is_array($rf_cat['words']) ? $rf_cat['words'] : [];
                $category_ids = array_merge($category_ids, $cat_words);
            }

            if (!empty($rules_fields['added_by'])) {
                $added_by = is_array($rules_fields['added_by']) ? $rules_fields['added_by'] : [];
                foreach ($added_by as $key) {
                    $key_str = is_scalar($key) ? (string) $key : '';
                    if ($key_str !== '') {
                        $user_ids[$key_str] = 1;
                    }
                }
            }

            $search_details[$id_search] = $rules_fields;
        }
    }

    if (count($user_ids) > 0) {
        $query = '
SELECT '.\Piwigo\Config\Config::userFields()['id'].' AS id
     , '.\Piwigo\Config\Config::userFields()['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE id IN ('.implode(',', array_keys($user_ids)).')
;';
        $result = pwg_query($query);

        $username_of = [];
        while ($row = pwg_db_fetch_assoc($result)) {
            $row_id_key = is_scalar($row['id']) ? (string) $row['id'] : '';
            if ($row_id_key !== '') {
                $username_of[$row_id_key] = stripslashes((string) $row['username']);
            }
        }
    }

    $name_of_category = [];
    $image_infos = [];
    if (count($category_ids) > 0) {
        $category_ids_str = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $category_ids);
        $query = '
SELECT id, uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $category_ids_str).')
;';
        $uppercats_of = query2array($query, 'id', 'uppercats');

        $full_cat_path = [];
        $name_of_category = [];

        foreach ($uppercats_of as $category_id => $uppercats) {
            $uppercats_s = is_scalar($uppercats) ? (string) $uppercats : '';
            $full_cat_path[$category_id] = get_cat_display_name_cache(
                $uppercats_s,
                'admin.php?page=album-'
            );

            $uppercats_parts = explode(',', $uppercats_s);
            $name_of_category[$category_id] = get_cat_display_name_cache(
                end($uppercats_parts) ?: '',
                'admin.php?page=album-'
            );
        }
    }

    if (count($image_ids) > 0) {
        $query = '
SELECT
    id,
    IF(name IS NULL, file, name) AS label,
    filesize,
    file,
    path,
    representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', array_keys($image_ids)).')
;';
        $image_infos = query2array($query, 'id');
    }

    $name_of_tag = [];
    if ($has_tags > 0) {
        $query = '
SELECT
    id,
    name, url_name
  FROM '.TAGS_TABLE;

        $name_of_tag = [];
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            $tag_id_key = is_scalar($row['id']) ? (string) $row['id'] : '';
            if ($tag_id_key !== '') {
                $name_of_tag[$tag_id_key] = trigger_change('render_tag_name', $row['name'], $row);
            }
        }
    }

    $i = 0;
    $first_line = $page['start'] + 1;
    $last_line = $page['start'] + \Piwigo\Config\Config::nbLogsPage();

    $summary['total_filesize'] = 0;
    $summary['guests_IP'] = [];

    $result = [];
    $sorted_members = [];

    foreach ($history_lines as $line) {
        $line_image_type = is_scalar($line['image_type']) ? (string) $line['image_type'] : '';
        $line_image_id = $line['image_id'] ?? null;
        $line_image_id_str = is_scalar($line_image_id) ? (string) $line_image_id : '';
        $line_user_id = $line['user_id'] ?? null;
        $line_user_id_str = is_scalar($line_user_id) ? (string) $line_user_id : '';
        $line_IP = is_scalar($line['IP']) ? (string) $line['IP'] : '';
        $line_cat_id = $line['category_id'] ?? null;
        $line_cat_id_str = is_scalar($line_cat_id) ? (string) $line_cat_id : '';
        $line_search_id = $line['search_id'] ?? null;
        $line_search_id_str = is_scalar($line_search_id) ? (string) $line_search_id : '';
        $line_section = is_scalar($line['section']) ? (string) $line['section'] : '';

        if ($line_image_type === 'high' && $line_image_id_str !== '') {
            $summary['total_filesize'] += @intval($image_infos[$line_image_id_str]['filesize'] ?? 0);
        }

        if ($line_user_id_str === (string) \Piwigo\Config\Config::guestId()) {
            if (!isset($summary['guests_IP'][$line_IP])) {
                $summary['guests_IP'][$line_IP] = 0;
            }

            $summary['guests_IP'][$line_IP]++;
        }

        $i++;

        if ($i <= $first_line and $i >= $last_line) {
            continue;
        }

        $user_name = '#unknown';
        $user_string = '';
        if ($line_user_id_str !== '' && isset($username_of[$line_user_id_str])) {
            $user_name = $username_of[$line_user_id_str];
            $user_string .= $username_of[$line_user_id_str];
        } else {
            $user_string .= $line_user_id_str;
        }
        $user_string .= '&nbsp;<a href="';
        $user_string .= PHPWG_ROOT_PATH.'admin.php?page=history';
        $user_string .= '&amp;search_id='.$search_id;
        $user_string .= '&amp;user_id='.$line_user_id_str;
        $user_string .= '">+</a>';

        $tag_names = '';
        $tag_ids = '';
        if (isset($line['tag_ids'])) {
            $line_tag_ids = is_scalar($line['tag_ids']) ? (string) $line['tag_ids'] : '';
            $tag_names = preg_replace_callback(
                '/(\d+)/',
                fn (array $m): string => is_scalar($name_of_tag[$m[1]] ?? null) ? (string) ($name_of_tag[$m[1]] ?? $m[1]) : (string) $m[1],
                $line_tag_ids
            ) ?? $line_tag_ids;
            $tag_ids = $line_tag_ids;
        }

        $image_string = '';
        $image_title = '';
        $image_edit_string = '';
        $image_id = '';
        $cat_name = '';
        if ($line_image_id_str !== '') {
            $image_edit_string = PHPWG_ROOT_PATH.'admin.php?page=photo-'.$line_image_id_str;
            $picture_url = make_picture_url(
                [
                'image_id' => $line_image_id,
                ]
            );

            $element = [];
            if (isset($image_infos[$line_image_id_str])) {
                $element = [
                  'id' => $line_image_id,
                  'file' => $image_infos[$line_image_id_str]['file'],
                  'path' => $image_infos[$line_image_id_str]['path'],
                  'representative_ext' => $image_infos[$line_image_id_str]['representative_ext'],
                  ];
                $thumbnail_display = $page['search']['fields']['display_thumbnail'];
            } else {
                $thumbnail_display = 'no_display_thumbnail';
            }

            $image_title = '';

            if (isset($image_infos[$line_image_id_str]['label'])) {
                $tc_result = trigger_change('render_element_description', $image_infos[$line_image_id_str]['label']);
                $image_title .= ' '.$tc_result;
            } else {
                $image_edit_string = '';
                $image_title .= ' unknown filename';
            }

            $image_string = '';
            $image_id = $line_image_id;

            $img_url = @DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $element);
            $image_string =
            '<span><img src="'.(is_string($img_url) ? $img_url : '')
            .'" alt="'.$image_title.'" title="'.$image_title.'">';
        }

        if ($line_search_id_str !== '' && isset($search_details[$line_search_id_str])) {
            $sd = is_array($search_details[$line_search_id_str]) ? $search_details[$line_search_id_str] : [];
            $sd_tags = is_array($sd['tags'] ?? null) ? $sd['tags'] : [];
            $sd_cat = is_array($sd['cat'] ?? null) ? $sd['cat'] : [];
            $sd_allwords = is_array($sd['allwords'] ?? null) ? $sd['allwords'] : [];
            $sd_author = is_array($sd['author'] ?? null) ? $sd['author'] : [];
            $sd_tags_words = is_array($sd_tags['words'] ?? null) ? $sd_tags['words'] : [];
            $sd_cat_words = is_array($sd_cat['words'] ?? null) ? $sd_cat['words'] : [];
            $sd_allwords_words = is_array($sd_allwords['words'] ?? null) ? $sd_allwords['words'] : [];
            $sd_author_words = is_array($sd_author['words'] ?? null) ? $sd_author['words'] : [];
            $sd_added_by = is_array($sd['added_by'] ?? null) ? $sd['added_by'] : [];
            $search_detail = [
              'allwords' => !empty($sd_allwords_words) ? $sd_allwords_words : null,
              'tags' => !empty($sd_tags_words) ? array_intersect_key($name_of_tag, array_flip(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $sd_tags_words))) : null,
              'date_posted' => !empty($sd['date_posted']) ? $sd['date_posted'] : null,
              'cat' => !empty($sd_cat_words) ? array_intersect_key($name_of_category, array_flip(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $sd_cat_words))) : null,
              'author' => !empty($sd_author_words) ? $sd_author_words : null,
              'added_by' => !empty($sd_added_by) ? array_intersect_key($username_of, array_flip(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $sd_added_by))) : null,
              'filetypes' => !empty($sd['filetypes']) ? $sd['filetypes'] : null,
            ];
        } else {
            $search_detail = null;
        }

        @$sorted_members[$user_name] += 1;

        $line_date = is_scalar($line['date'] ?? null) ? $line['date'] : null;
        array_push(
            $result,
            [
            'DATE'       => format_date(is_string($line_date) || is_int($line_date) ? $line_date : null),
            'TIME'       => $line['time'] ?? null,
            'USER'       => $user_string,
            'USERNAME'   => $user_name,
            'USERID'     => $line_user_id,
            'IP'         => $line_IP,
            'IMAGE'      => $image_string,
            'IMAGENAME'  => $image_title,
            'IMAGEID'    => $image_id,
            'EDIT_IMAGE' => $image_edit_string,
            'TYPE'       => $line_image_type,
            'SECTION'    => $line_section,
            'FULL_CATEGORY_PATH' => ($line_cat_id_str !== '' && isset($full_cat_path[$line_cat_id_str])) ? strip_tags((string) $full_cat_path[$line_cat_id_str]) : l10n('Root').$line_cat_id_str,
            'CATEGORY'   => ($line_cat_id_str !== '' && isset($name_of_category[$line_cat_id_str])) ? $name_of_category[$line_cat_id_str] : l10n('Root') . $line_cat_id_str,
            'SEARCH_ID'  => $line_search_id ?? null,
            'TAGS'       => explode(',', (string) $tag_names),
            'TAGIDS'     => explode(',', $tag_ids),
            'SEARCH_DETAILS'  => $search_detail,
      ]
        );
    }

    $max_page = ceil(count($result) / 300);
    $result = array_reverse($result, true);
    $pageNumber = is_numeric($param['pageNumber']) ? (int) $param['pageNumber'] : 0;
    $result = array_slice($result, $pageNumber * 300, 300);

    $summary['nb_guests'] = 0;
    if (count(array_keys($summary['guests_IP'])) > 0) {
        $summary['nb_guests'] = count(array_keys($summary['guests_IP']));

        // we delete the "guest" from the $username_of hash so that it is
        // avoided in next steps
        unset($username_of[ (string) \Piwigo\Config\Config::guestId() ]);
    }

    $summary['nb_members'] = count($username_of);

    $member_strings = [];
    foreach ($username_of as $user_id => $user_name) {
        $member_string = $user_name;
        $member_strings[] = [$member_string => $user_id];
    }

    arsort($sorted_members);
    unset($sorted_members['guest']);

    $search_summary =
    [
      'NB_LINES' => l10n_dec(
          '%d line filtered',
          '%d lines filtered',
          $page['nb_lines']
      ),
      'FILESIZE' => $summary['total_filesize'] != 0 ? ceil($summary['total_filesize'] / 1024) : 0,
      'USERS' => l10n_dec(
          '%d user',
          '%d users',
          $summary['nb_members'] + $summary['nb_guests']
      ),
      'MEMBERS' => $member_strings,
      'SORTED_MEMBERS' => $sorted_members,
      'GUESTS' => l10n_dec(
          '%d guest',
          '%d guests',
          $summary['nb_guests']
      ),
      ];

    unset($name_of_tag);

    return [
      'lines'   => $result,
      'params'  => $param,
      'maxPage' => ($max_page == 0) ? 1 : $max_page,
      'summary' => $search_summary,
    ];
}
