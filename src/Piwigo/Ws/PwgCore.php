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
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\WsError;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Url\UrlService;

/**
 * P23 batch 8e-4: relocated from include/ws_functions/pwg.php.
 * Top-level `pwg.*` WS methods (getVersion, getInfos, getCacheSize,
 * caddie.add, rates.delete, session.*, getActivityList, history.log/
 * search -- 13 registrations) -- registered via callable arrays in
 * include/ws_default_methods.inc.php. historyGet() is the 'get_history'
 * event handler (registered via first-class-callable, not an addMethod()
 * WS registration).
 */
final class PwgCore
{
    /**
     * Constructed identically 3 times across sessionLogin()/
     * sessionLogout() -- none inside a loop, so (unlike
     * Ws\PwgUsers::authService(Connection $conn)) this builds its own
     * connection per call, same shape as Ws\PwgUsers::apiKeyService().
     */
    private static function authService(): AuthService
    {
        return new AuthService(new AuthRepository(DbConnection::build()), new ActivityService(new ActivityRepository(DbConnection::build())), new HtmlService(), new PasswordService(new PasswordRepository(DbConnection::build())), new CookieService());
    }

    /**
     * Constructed identically 4 times across this file (sessionGetStatus()/
     * historyLog()/historyGet()/historySearch()) -- same DRY-extraction
     * shape as authService() above, builds its own connection per call.
     */
    private static function historyService(): HistoryService
    {
        return new HistoryService(new HistoryRepository(DbConnection::build()), CurrentConfigService::get());
    }

    /**
     * API method
     * Returns a list of missing derivatives (not generated yet)
     * @param array{types: array<int, string>, ids: array<int, int>, max_urls: int, prev_page: int|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     *    types/ids: WsParamFlag::FORCE_ARRAY, null default -- never null
     *    (makeArrayParam() converts to []). max_urls: WsParamType::INT|POSITIVE,
     *    default 200 (non-null) -- always int. prev_page: WsParamType::INT|
     *    POSITIVE, null default -- int|null. f_* (see
     *    WsHelper::stdImageSqlFilter()'s docblock): shared filter set merged in
     *    via ws.php's $f_params.
     * @return PwgError|array{next_page?: int|string, urls?: string[]}
     */
    public static function getMissingDerivatives(array $params, PwgServer &$service): PwgError|array
    {
        if ($params['types'] === []) {
            $types = array_keys(ImageStdParams::get_defined_type_map());
        } else {
            $types = array_intersect(array_keys(ImageStdParams::get_defined_type_map()), $params['types']);
            if (count($types) === 0) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid types');
            }
        }

        $conn = DbConnection::build();
        $max_urls = $params['max_urls'];
        $query = 'SELECT MAX(id)+1, COUNT(*) FROM ' . Tables::images() . ';';
        $row = $conn->fetchNumeric($query);
        assert($row !== false);
        $max_id = $row[0] ?? null;
        $image_count = $row[1] ?? null;
        // COUNT(*) is always numeric; MAX(id)+1 is numeric whenever rows
        // exist, which the $image_count == 0 early return below guarantees
        // for every later use of $max_id.
        $image_count = is_numeric($image_count) ? (int) $image_count : 0;
        $max_id = is_numeric($max_id) ? (int) $max_id : 0;

        if ($image_count === 0) {
            return [];
        }

        $start_id = $params['prev_page'];
        if ($start_id <= 0) {
            $start_id = $max_id;
        }

        $uid = '&b=' . time();

        \Piwigo\Config\Config::override('question_mark_in_urls', true);
        \Piwigo\Config\Config::override('php_extension_in_urls', true);
        \Piwigo\Config\Config::override('derivative_url_style', 2); // script

        $qlimit = min(5000, ceil(max($image_count / 500, $max_urls / count($types))));
        $where_clauses = WsHelper::stdImageSqlFilter($params, $service, '');
        $where_clauses[] = 'id<start_id';

        if ($params['ids'] !== []) {
            $where_clauses[] = 'id IN (' . implode(',', $params['ids']) . ')';
        }

        $query_model = '
SELECT id, path, representative_ext, width, height, rotation
  FROM ' . Tables::images() . '
  WHERE ' . implode(' AND ', $where_clauses) . '
  ORDER BY id DESC
  LIMIT ' . (string) $qlimit . '
;';

        $urls = [];
        do {
            $rows = $conn->fetchAllAssociative(str_replace('start_id', (string) $start_id, $query_model));
            $is_last = count($rows) < $qlimit;

            foreach ($rows as $image_row) {
                $start_id = is_numeric($image_row['id']) ? (int) $image_row['id'] : 0;
                $src_image = new SrcImage($image_row);
                if ($src_image->is_mimetype()) {
                    continue;
                }

                foreach ($types as $type) {
                    $derivative = new DerivativeImage($type, $src_image);
                    if ($type !== $derivative->get_type()) {
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
        } while (count($urls) < $max_urls and (bool) $start_id);

        $ret = [];
        if ((bool) $start_id) {
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
    public static function getVersion(array $params, PwgServer &$service): string
    {
        return AppInfo::VERSION;
    }

    /**
     * API method
     * Returns general informations about the installation
     * @param mixed[] $params
     * @return array{infos: PwgNamedArray}
     */
    public static function getInfos(array $params, PwgServer &$service): array
    {
        $conn = DbConnection::build();
        $infos = [];
        $infos['version'] = AppInfo::VERSION;

        $query = 'SELECT COUNT(*) FROM ' . Tables::images() . ';';
        $infos['nb_elements'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::categories() . ';';
        $infos['nb_categories'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::categories() . ' WHERE dir IS NULL;';
        $infos['nb_virtual'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::categories() . ' WHERE dir IS NOT NULL;';
        $infos['nb_physical'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::imageCategory() . ';';
        $infos['nb_image_category'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::tags() . ';';
        $infos['nb_tags'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::imageTag() . ';';
        $infos['nb_image_tag'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::users() . ';';
        $infos['nb_users'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM `' . Tables::groups() . '`;';
        $infos['nb_groups'] = $conn->fetchOne($query);

        $query = 'SELECT COUNT(*) FROM ' . Tables::comments() . ';';
        $infos['nb_comments'] = $conn->fetchOne($query);

        // first element
        if ($infos['nb_elements'] > 0) {
            $query = 'SELECT MIN(date_available) FROM ' . Tables::images() . ';';
            $infos['first_date'] = $conn->fetchOne($query);
        }

        // unvalidated comments
        if ($infos['nb_comments'] > 0) {
            $query = 'SELECT COUNT(*) FROM ' . Tables::comments() . ' WHERE validated=\'false\';';
            $infos['nb_unvalidated_comments'] = $conn->fetchOne($query);
        }

        // Cache size: not computed here on purpose. A real answer means
        // shelling out to `du` (see getCacheSize() below, the real
        // pwg.getCacheSize API method) -- too expensive to pay on every
        // pwg.getInfos call, and exec() isn't guaranteed to be enabled.
        // null (not a fake number) matches getCacheSize()'s own sentinel
        // for "couldn't determine size"; callers that need the real value
        // should call pwg.getCacheSize directly.
        $infos['cache_size'] = null;

        $output = [];
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
    public static function getCacheSize(array $params, PwgServer &$service): array
    {
        $data_location = \Piwigo\Config\Config::dataLocation();

        // Cache size
        $path_cache = $data_location;
        $infos = [];
        $infos['cache_size'] = null;
        if (function_exists('exec')) {
            $return_array_cache = [];
            @exec('du -sk ' . $path_cache, $return_array_cache);
            if (
                isset($return_array_cache[0]) && $return_array_cache[0] !== '' && $return_array_cache[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $return_array_cache[0], $matches_cache)
            ) {
                $infos['cache_size'] = (int) $matches_cache[1] * 1024;
            }
        }

        // Multiples sizes size
        $path_msizes = $data_location . 'i';
        $msizes = FilesystemHelper::getCacheSizeDerivatives($path_msizes);

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
            $added_size = @$msizes[DerivativeUrlCodec::derivativeToUrl($size_type)];
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
            $return_array_template_c = [];
            @exec('du -sk ' . $path_template_c, $return_array_template_c);
            if (
                isset($return_array_template_c[0]) && $return_array_template_c[0] !== '' && $return_array_template_c[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $return_array_template_c[0], $matches_template_c)
            ) {
                $infos['tsizes'] = (int) $matches_template_c[1] * 1024;
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

        CurrentConfigService::get()->confUpdateParam('cache_sizes', $output, true);

        return [
            'infos' => new PwgNamedArray($output, 'item'),
        ];
    }

    /**
     * API method
     * Adds images to the caddie
     * @param array{image_id: array<int, int>, ...} $params image_id:
     *    WsParamFlag::FORCE_ARRAY|WsParamType::ID, mandatory (no 'default') -- always
     *    a list of positive ints.
     */
    public static function caddieAdd(array $params, PwgServer &$service): int
    {
        $user_id = \Piwigo\Users\CurrentUser::get()->id;

        return new CaddieRepository(DbConnection::build())
            ->addElements($user_id, $params['image_id']);
    }

    /**
     * API method
     * Deletes rates of an user
     * @param array{user_id: int, anonymous_id: string|null, image_id?: int, ...} $params
     *    user_id: WsParamType::ID, mandatory -- always int. anonymous_id: no
     *    WS_TYPE flag, null default -- string|null. image_id:
     *    WsParamFlag::OPTIONAL with no 'default' -- may be entirely absent.
     */
    public static function ratesDelete(array $params, PwgServer &$service): int|string
    {
        $query = '
DELETE FROM ' . Tables::rate() . '
  WHERE user_id=' . $params['user_id'];

        if (! in_array($params['anonymous_id'], [null, ''], true)) {
            $query .= ' AND anonymous_id=\'' . $params['anonymous_id'] . '\'';
        }
        if (isset($params['image_id']) and $params['image_id'] !== 0) {
            $query .= ' AND element_id=' . $params['image_id'];
        }

        // Real bug fix, not just a MysqliDb retarget: the original (both here
        // and in the pre-rewrite legacy include/ws_functions/pwg.php) built
        // $query above and then NEVER executed it -- MysqliDb::changes()
        // just reads $mysqli->affected_rows from whatever OTHER query last
        // ran on the connection, completely disconnected from this DELETE.
        // executeStatement() both runs the query for real and returns its
        // actual affected-row count.
        $changes = DbConnection::build()->executeStatement($query);
        if ($changes > 0) {
            new RateService(new RateRepository(DbConnection::build()), new CookieService())
                ->updateRatingScore();
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
    public static function sessionLogin(array $params, PwgServer &$service): PwgError|true
    {
        if (ApiKeyRequestFlag::isActive()) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }

        if ((bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['username'])) {
            // realEscapeString() dropped: the combined
            // "username:password" string must match authKeyLogin()'s own
            // strict [a-z0-9]-only regex to be considered valid at all, so
            // escaping could only ever break it, never protect it -- same
            // "value is regex-validated to a shape that can't need SQL
            // escaping" rationale as Bootstrap\UserBootstrap's
            // HTTP_X_PIWIGO_API fix (Phase 1d).
            $authenticate = self::authService()->authKeyLogin($params['username'] . ':' . $params['password']);
            if ($authenticate) {
                $_SESSION['connected_with'] = 'ws_session_login_api_key';
                return true;
            }
        } elseif (self::authService()->tryLogUser($params['username'], $params['password'], false)) {
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
    public static function sessionLogout(array $params, PwgServer &$service): PwgError|true
    {
        if (ApiKeyRequestFlag::isActive()) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }

        if (! \Piwigo\Auth\AccessControl::isAGuest()) {
            self::authService()->logoutUser();
        }
        return true;
    }

    /**
     * API method
     * Returns info about the current user
     * @param mixed[] $params
     * @return array<string, mixed>
     */
    public static function sessionGetStatus(array $params, PwgServer &$service): array
    {

        $currentUser = \Piwigo\Users\CurrentUser::get();
        $res = [];
        $res['username'] = \Piwigo\Auth\AccessControl::isAGuest() ? 'guest' : stripslashes($currentUser->username);
        $res['status'] = $currentUser->status->value;
        $res['theme'] = $currentUser->theme;
        $res['language'] = $currentUser->language;
        $res['pwg_token'] = new \Piwigo\Csrf\CsrfService()->getToken();
        $res['charset'] = \Piwigo\Core\CharsetHelper::getPwgCharset();

        $res['current_datetime'] = DbConnection::build()->fetchOne('SELECT NOW();');
        $res['version'] = AppInfo::VERSION;
        $res['save_visits'] = self::historyService()->isLoggingAllowed();
        $res['connected_with'] = $_SESSION['connected_with'] ?? null;

        // Piwigo Remote Sync does not support receiving the new (version 14) output "save_visits"
        $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (is_string($http_user_agent) and (bool) preg_match('/^PiwigoRemoteSync/', $http_user_agent)) {
            unset($res['save_visits']);
            unset($res['connected_with']);
        }

        // Piwigo Remote Sync does not support receiving the available sizes
        $piwigo_remote_sync_agent = 'Apache-HttpClient/';
        if (! is_string($http_user_agent) or ! str_starts_with($http_user_agent, $piwigo_remote_sync_agent)) {
            $res['available_sizes'] = array_keys(ImageStdParams::get_defined_type_map());
        }

        if (\Piwigo\Auth\AccessControl::isAdmin()) {
            $upload_ext_list = (\Piwigo\Config\Config::uploadFormAllTypes()) ? \Piwigo\Config\Config::fileExtensions() : \Piwigo\Config\Config::pictureExtensions();

            $res['upload_file_types'] = implode(
                ',',
                array_unique(
                    array_map(
                        strtolower(...),
                        $upload_ext_list
                    )
                )
            );

            $chunk_size = \Piwigo\Config\Config::uploadFormChunkSize();
            $res['upload_form_chunk_size'] = $chunk_size;
        }

        return $res;
    }

    /**
     * API method
     * Returns lines of users activity
     *  @since 12
     * @param array{page: int|null, offset: int, uid: int|null, date_min: string|null, date_max: string|null, id: int|null, object: string|null, action: string|null, ...} $param
     *    page/uid/id: WsParamType::INT|POSITIVE or WsParamType::ID, null default ->
     *    int|null. offset: WsParamType::INT|POSITIVE, default 0 (non-null) ->
     *    always int. date_min/date_max/object/action: no WS_TYPE flag, null
     *    default -> string|null.
     * @return PwgError|array{result_lines: array<int, array<string, mixed>>, page_offset: int, end_page: bool, params: array<string, mixed>}
     */
    public static function getActivityList(array $param, PwgServer &$service): PwgError|array
    {
        $conn = DbConnection::build();

        foreach (['date_min', 'date_max'] as $datefield) {
            $datefield_value = $param[$datefield] ?? null;
            if (! in_array($datefield_value, [null, ''], true) and ! \Piwigo\Core\DateHelper::isValidMysqlDatetime($datefield_value)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid ' . $datefield);
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
        $date_min_raw = $param['date_min'] ?? null;
        $date_max_raw = $param['date_max'] ?? null;
        if (! in_array($date_min_raw, [null, ''], true)) {
            // is_valid_mysql_datetime() above already validated date_min; a
            // valid Y-m-d[ H:i:s] string always parses
            $min_date = date_create($date_min_raw);
            assert($min_date !== false);
            $min = date_format($min_date, 'Y-m-d H:i:s');

            // date_max may be empty/unvalidated/absent here — date_create()
            // only returns false on a genuinely malformed string, never on ''
            // (which means "now"), so a missing date_max is coalesced to ''
            $max_date = date_create($date_max_raw ?? '');
            assert($max_date !== false);
            $max = date_format($max_date, 'Y-m-d 23:59:59');
        }

        if (! in_array($date_max_raw, [null, ''], true)) {
            $max_date = date_create($date_max_raw);
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
    AND action = ' . $conn->quote($param['action']);
        }

        if (isset($param['object'])) {
            $where .= '
    AND object = ' . $conn->quote($param['object']);
        }

        if (! in_array($date_min_raw, [null, ''], true)) {
            $where .= '
    AND occured_on >= "' . $min . '"';
        }

        if (! in_array($date_max_raw, [null, ''], true)) {
            $where .= '
    AND occured_on <= "' . $max . '"';
        }

        if ($param['id'] !== null and $param['id'] !== 0) {
            $where .= '
    AND object_id = ' . $param['id'];
        }

        if (\Piwigo\Config\Config::activityDisplayConnections() === 'none') {
            $where .= '
    AND action NOT IN (\'login\', \'logout\')';
        } elseif (\Piwigo\Config\Config::activityDisplayConnections() === 'admins_only') {
            $admin_ids = new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())->findAdminIds();
            $where .= '
    AND NOT (action IN (\'login\', \'logout\') AND object_id NOT IN (' . implode(',', $admin_ids) . '))';
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
  FROM ' . Tables::activity() . '
  ' . $where . '
  ORDER BY activity_id DESC
  LIMIT ' . $nb_rows_to_fetch . ' OFFSET ' . $page_offset . '
;';
            $rows = $conn->fetchAllAssociative($query);

            if (count($rows) < $nb_rows_to_fetch) {
                $more_rows_available = false;
            }

            foreach ($rows as $row) {
                if (count($output_lines) < $page_size) {
                    $page_offset++;

                    // DBAL's fetchAllAssociative() rows are array<string,
                    // mixed> (vs. mysqli's guaranteed string|null under the
                    // legacy driver) -- narrow every field used below once,
                    // here, instead of scattering is_scalar()/is_string()
                    // guards through the rest of this loop.
                    $row_session_idx = is_scalar($row['session_idx']) ? (string) $row['session_idx'] : '';
                    $row_object = is_scalar($row['object']) ? (string) $row['object'] : '';
                    $row_action = is_scalar($row['action']) ? (string) $row['action'] : '';
                    $row_object_id = is_scalar($row['object_id']) ? (string) $row['object_id'] : null;
                    $row_ip_address = is_scalar($row['ip_address']) ? (string) $row['ip_address'] : null;
                    $row_performed_by = is_scalar($row['performed_by']) ? (string) $row['performed_by'] : null;
                    $row_details = is_scalar($row['details']) ? (string) $row['details'] : '';
                    $row_occured_on = is_scalar($row['occured_on']) ? (string) $row['occured_on'] : '';

                    $line_key = $row_session_idx . '~' . $row_object . '~' . $row_action . '~'; // idx~photo~add

                    if ($line_key === $current_key) {
                        // I increment the counter of the previous line
                        $last_idx = count($output_lines) - 1;
                        $prev_counter = $output_lines[$last_idx]['counter'];
                        $output_lines[$last_idx]['counter'] = $prev_counter + 1;

                        // $output_lines elements are always built with the
                        // full literal shape below (id/object/object_id/...)
                        // -- PHPStan loses that precision once the array is
                        // both grown (`$output_lines[] = [...]`) and mutated
                        // by dynamic index in the same loop, widening every
                        // field to optional. Verified safe: $last_idx only
                        // reaches here after at least one element exists.
                        // @phpstan-ignore offsetAccess.notFound
                        $prev_object_ids = $output_lines[$last_idx]['object_id'];
                        $prev_object_ids[] = $row_object_id;
                        $output_lines[$last_idx]['object_id'] = $prev_object_ids;
                    } else {
                        $row_details = str_replace('`groups`', 'groups', $row_details);
                        $row_details = str_replace('`rank`', 'rank', $row_details);
                        $details = @unserialize($row_details);
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

                        [$date, $hour] = explode(' ', $row_occured_on);
                        // New line
                        $output_lines[] = [
                            'id' => $line_id,
                            'object' => $row_object,
                            'object_id' => [$row_object_id],
                            'action' => $row_action,
                            'ip_address' => $row_ip_address,
                            'date' => \Piwigo\Core\DateHelper::formatDate($date),
                            'hour' => $hour,
                            'user_id' => $row_performed_by,
                            'detailsType' => $detailsType,
                            'details' => $details,
                            'counter' => 1,
                        ];

                        if ($row_performed_by !== null) {
                            $user_ids[$row_performed_by] = 1;
                        }
                        if ($row_object === 'user' and $row_object_id !== null) {
                            $user_ids[$row_object_id] = 1;
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
            $user_fields = \Piwigo\Config\Config::userFields();
            $user_field_id = $user_fields['id'];
            $user_field_username = $user_fields['username'];

            $query = '
SELECT
    `' . $user_field_id . '` AS user_id,
    `' . $user_field_username . '` AS username
  FROM ' . Tables::users() . '
  WHERE `' . $user_field_id . '` IN (' . implode(',', array_keys($user_ids)) . ')
;';
            $username_of = array_column($conn->fetchAllAssociative($query), 'username', 'user_id');
        }

        foreach ($output_lines as $idx => $output_line) {
            if (($output_line['object'] ?? null) === 'user') {
                foreach ($output_line['object_id'] as $user_id) {
                    if (! is_string($user_id)) {
                        continue;
                    }

                    $details = $output_lines[$idx]['details'] ?? [];

                    $users = $details['users'] ?? [];
                    if (! is_array($users)) {
                        $users = [];
                    }

                    $users[] = $username_of[$user_id] ?? 'user#' . $user_id;
                    $details['users'] = $users;
                    $output_lines[$idx]['details'] = $details;
                }

                $details = $output_lines[$idx]['details'] ?? [];
                if (isset($details['users']) and is_array($details['users'])) {
                    $details['users_string'] = implode(', ', array_filter($details['users'], is_string(...)));
                    $output_lines[$idx]['details'] = $details;
                }
            }

            $user_id_val = $output_lines[$idx]['user_id'] ?? null;
            $user_id_key = is_string($user_id_val) ? $user_id_val : '';
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
     *    image_id: WsParamType::ID, mandatory -- always int. cat_id: WsParamType::ID,
     *    null default -- int|null. section/tags_string: no WS_TYPE flag,
     *    null default -- string|null. is_download: WsParamType::BOOL, default
     *    false (non-null) -- always bool.
     */
    public static function historyLog(array $params, PwgServer &$service): void
    {
        $section = null;
        if (! in_array($params['section'], [null, ''], true) and in_array($params['section'], new DbInfo(DbConnection::build())->getEnums(Tables::history(), 'section'), true)) {
            $section = $params['section'];
        }

        $category = null;
        if ($params['cat_id'] !== null and $params['cat_id'] !== 0) {
            $category = [
                'id' => $params['cat_id'],
            ];
        }

        $tagIds = null;
        if (! in_array($params['tags_string'], [null, ''], true) and (bool) preg_match('/^\d+(,\d+)*$/', $params['tags_string'])) {
            $tagIds = array_map(intval(...), explode(',', $params['tags_string']));
        }

        // when visiting a photo (which is currently, in version 14, the only event registered
        // by pwg.history.log) we should also increment images.hit
        if ($params['image_id'] !== 0) {
            new ImageRepository(DbConnection::build())->incrementVisitCounter($params['image_id']);
        }

        $image_type = 'picture';
        if ($params['is_download']) {
            $image_type = 'high';
        }

        self::historyService()->logVisit(
            $params['image_id'],
            $image_type,
            section: $section,
            category: $category,
            tagIds: $tagIds,
        );
    }

    /**
     * Perform history search. Registered as the default 'get_history' event
     * handler (see include/ws_init.inc.php) -- historySearch() (this
     * class's only real caller of that event) dispatches via
     * trigger_change() rather than calling this directly, so a plugin can
     * still override history search behavior by registering its own
     * 'get_history' handler at a higher priority.
     *
     * @param array<int, array<string, mixed>> $data  - used in trigger_change
     * @param array<string, mixed> $search
     * @param list<string> $types
     * @return array<int, array<string, mixed>>
     */
    public static function historyGet(array $data, array $search, array $types): array
    {
        return self::historyService()
            ->getHistory($data, $search, $types);
    }

    /**
     * API method
     * Returns lines of an history search
     * @since 13
     * @param array{start: string|null, end: string|null, types: array<int, string>, user_id: int|string, image_id: int|null, filename: string|null, ip: string|null, display_thumbnail: string, pageNumber: int|null, ...} $param
     *    start/end/filename/ip: no WS_TYPE flag, null default -- string|null.
     *    types: WsParamFlag::FORCE_ARRAY, non-null array default, no WS_TYPE flag
     *    -- always an array (never coerced element-wise). user_id: no
     *    WS_TYPE flag, non-null int default (-1) -- int if the default is
     *    used, otherwise the raw uncoerced request string. image_id:
     *    WsParamType::ID, null default -- int|null. display_thumbnail: no
     *    WS_TYPE flag, non-null string default -- always string.
     *    pageNumber: WsParamType::INT|POSITIVE, null default -- int|null.
     * @return array<string, mixed>
     */
    public static function historySearch(array $param, PwgServer &$service): array
    {
        $conn = DbConnection::build();

        /** @var array<string, mixed> $page */
        $page = [];
        if (isset($_GET['start']) and is_numeric($_GET['start'])) {
            $page['start'] = $_GET['start'];
        } else {
            $page['start'] = 0;
        }

        $types = array_merge(['none'], new DbInfo($conn)->getEnums(Tables::history(), 'image_type'));

        $display_thumbnails = [
            'no_display_thumbnail' => Lang::t('No display'),
            'display_thumbnail_classic' => Lang::t('Classic display'),
            'display_thumbnail_hoverbox' => Lang::t('Hoverbox display'),
        ];

        // +-----------------------------------------------------------------------+
        // | Build search criteria and redirect to results                         |
        // +-----------------------------------------------------------------------+

        $page['errors'] = [];
        $search = [];
        $search['fields'] = [];

        // date start
        if (! in_array($param['start'], [null, ''], true)) {
            new \Piwigo\Validation\InputValidator()
                ->validate('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-after'] = $param['start'];
        }

        // date end
        if (! in_array($param['end'], [null, ''], true)) {
            new \Piwigo\Validation\InputValidator()
                ->validate('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-before'] = $param['end'];
        }

        // types
        if ($param['types'] === []) {
            $search['fields']['types'] = $types;
        } else {
            new \Piwigo\Validation\InputValidator()
                ->validate('types', $param, true, '/^(' . implode('|', $types) . ')$/');
            $search['fields']['types'] = $param['types'];
        }

        // user
        $search['fields']['user'] = intval($param['user_id']);

        // image
        if ($param['image_id'] !== null and $param['image_id'] !== 0) {
            $search['fields']['image_id'] = intval($param['image_id']);
        }

        // filename
        // realEscapeString() dropped for filename/ip: both fields are
        // read back later via HistoryRepository::findImageIdsByFilename()/
        // the 'ip' rate-limit lookup, which already bind the value as a
        // real DBAL parameter (:pattern/:ip) -- same "dead pre-escaping"
        // rationale as this phase's other occurrences. The '*' -> '%'
        // wildcard conversion is unrelated to escaping and stays exactly
        // as-is.
        if (! in_array($param['filename'], [null, ''], true)) {
            $search['fields']['filename'] = str_replace('*', '%', $param['filename']);
        }

        // ip
        if (! in_array($param['ip'], [null, ''], true)) {
            $search['fields']['ip'] = str_replace('*', '%', $param['ip']);
        }

        // thumbnails
        new \Piwigo\Validation\InputValidator()
            ->validate('display_thumbnail', $param, false, '/^(' . implode('|', array_keys($display_thumbnails)) . ')$/');

        $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
        // Display choise are also save to one cookie
        if ($param['display_thumbnail'] !== ''
            and isset($display_thumbnails[$param['display_thumbnail']])) {
            $cookie_val = $param['display_thumbnail'];
        } else {
            $cookie_val = null;
        }

        new CookieService()
            ->setCookieVar('display_thumbnail', $cookie_val, strtotime('+1 month'));

        // image_id and filename are set independently above (like every
        // other field in this method) and both end up ANDed together in
        // $search['fields'] -- submitting both simultaneously just
        // narrows the search to their intersection, same as any other
        // multi-field combination here, not a special case to resolve.

        // store seach in database
        // register search rules in database, then they will be available on
        // thumbnails page and picture page.
        $query = '
  INSERT INTO ' . Tables::search() . '
  (rules)
  VALUES
  (' . $conn->quote(serialize($search)) . ')
  ;';

        $conn->executeStatement($query);

        $search_id = (int) $conn->lastInsertId();

        // Remove redirect for ajax //
        // redirect(
        //   PHPWG_ROOT_PATH.'admin.php?page=history&search_id='.$search_id
        //   );

        // what are the lines to display in reality ?
        $query = '
SELECT rules
  FROM ' . Tables::search() . '
  WHERE id = ' . $search_id . '
;';
        $serialized_rules = $conn->fetchOne($query);
        // this row is the one we just INSERTed above (via $search_id =
        // Connection::lastInsertId()) with a serialize() call we made
        // ourselves, so the 'rules' column is guaranteed to be a non-null
        // string here.
        assert(is_string($serialized_rules));

        $page['search'] = unserialize($serialized_rules);

        // Known limitation: the query behind this fetches more rows than
        // the page actually displays instead of a SQL_CALC_FOUND_ROWS-based
        // LIMIT/OFFSET pagination -- a real, non-trivial optimization
        // opportunity on large history tables, not a defect.
        // trigger_change()'s return type is genuinely mixed (it dispatches
        // to whatever handler is registered for 'get_history'); narrow to the
        // list of row-arrays that historyGet() actually returns.
        $data = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_history', [], $page['search'], $types);
        if (! is_array($data)) {
            $data = [];
        }
        // historyGet() (the only real handler for the 'get_history' event,
        // registered in include/ws_init.inc.php) returns array<int,
        // array<string, mixed>>; the trigger_change() dispatch above only
        // proved each element is an array, not that its keys are strings.
        /** @var array<int, array<string, mixed>> $data */
        $data = array_values(array_filter($data, is_array(...)));
        $historyService = self::historyService();
        usort($data, $historyService->historyCompare(...));

        $page['nb_lines'] = count($data);

        // Number of ids of each kind
        $history_lines = [];
        $user_ids = [];
        $username_of = [];
        $category_ids = [];
        $image_ids = [];
        $has_tags = false;
        $search_ids = [];

        // historyGet() (the real 'get_history' handler) builds each $row via
        // HistoryRepository::search()'s DBAL fetchAllAssociative(), so every
        // field here is really mixed -- the is_string()/is_array() guards
        // throughout this loop (and the rest of this method) are the real
        // narrowing, not just defensive boilerplate.
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
  FROM ' . Tables::search() . '
  WHERE id IN (' . implode(',', $search_ids) . ')
;';
            $search_details_raw = array_column($conn->fetchAllAssociative($query), 'rules', 'id');
            // Built into a fresh array (rather than mutating $search_details_raw
            // while iterating over it) so PHPStan can keep a precise
            // array<int|string, array<array-key, mixed>> element type instead of
            // widening to mixed from the in-place self-reassignment.
            foreach ($search_details_raw as $id_search => $rules_search_raw) {
                if (! is_string($rules_search_raw)) {
                    continue;
                }

                $unserialized = \Piwigo\Core\ArrayHelper::safeUnserialize($rules_search_raw);
                $rules_search = is_array($unserialized) && isset($unserialized['fields']) && is_array($unserialized['fields'])
                    ? $unserialized['fields']
                    : [];

                $rules_tags = is_array($rules_search['tags'] ?? null) ? $rules_search['tags'] : [];
                if (! in_array($rules_tags['words'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $has_tags = true;
                }

                $rules_cat = is_array($rules_search['cat'] ?? null) ? $rules_search['cat'] : [];
                if (! in_array($rules_cat['words'] ?? null, [null, false, 0, '0', '', []], true) and is_array($rules_cat['words'])) {
                    foreach ($rules_cat['words'] as $cat_id) {
                        if (is_string($cat_id) || is_int($cat_id)) {
                            $category_ids[] = (string) $cat_id;
                        }
                    }
                }

                $rules_added_by = $rules_search['added_by'] ?? null;
                if (! in_array($rules_added_by, [null, false, 0, '0', '', []], true) and is_array($rules_added_by)) {
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
            $user_fields = \Piwigo\Config\Config::userFields();
            $user_field_id = $user_fields['id'];
            $user_field_username = $user_fields['username'];

            $query = '
SELECT ' . $user_field_id . ' AS id
     , ' . $user_field_username . ' AS username
  FROM ' . Tables::users() . '
  WHERE id IN (' . implode(',', array_keys($user_ids)) . ')
;';
            $username_of = [];
            foreach ($conn->fetchAllAssociative($query) as $row) {
                if (! is_scalar($row['id'])) {
                    continue;
                }
                $username_of[(string) $row['id']] = stripslashes(is_scalar($row['username']) ? (string) $row['username'] : '');
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
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
            $uppercats_of = array_column($conn->fetchAllAssociative($query), 'uppercats', 'id');

            foreach ($uppercats_of as $category_id => $uppercats) {
                if (! is_string($uppercats)) {
                    continue;
                }

                $full_cat_path[$category_id] = new HtmlService()->getCatDisplayNameCache(
                    $uppercats,
                    'admin.php?page=album-'
                );

                $uppercats = explode(',', $uppercats);
                $name_of_category[$category_id] = new HtmlService()->getCatDisplayNameCache(
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
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', array_keys($image_ids)) . ')
;';
            $image_infos = array_column($conn->fetchAllAssociative($query), null, 'id');
        }

        // Not a real global: written, read (including via the closure
        // below), and unset() -- all within this one function, and this is
        // the only place in the codebase that touches $name_of_tag. The
        // `global` keyword was always redundant; kept as a plain local.
        $name_of_tag = [];
        if ($has_tags) {
            $query = '
SELECT
    id,
    name, url_name
  FROM ' . Tables::tags();

            foreach ($conn->fetchAllAssociative($query) as $row) {
                if (! is_scalar($row['id'])) {
                    continue;
                }
                $name_of_tag[(string) $row['id']] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $row['name'], $row);
            }
        }

        $page_start = (int) $page['start'];

        $nb_logs_page = \Piwigo\Config\Config::nbLogsPage();

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
            // every field of $line comes straight from historyGet()'s
            // HistoryRepository::search() rows (DBAL fetchAllAssociative()),
            // so it is really mixed -- the is_string() narrowing below is
            // the real guard, not just defensive boilerplate.
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
                $filesize_row = $image_infos[$line_image_id] ?? null;
                $filesize_value = is_array($filesize_row) ? ($filesize_row['filesize'] ?? null) : null;
                $summary['total_filesize'] = $running_total_filesize + (is_scalar($filesize_value) ? intval($filesize_value) : 0);
            }

            if (is_numeric($line_user_id) and (int) $line_user_id === \Piwigo\Config\Config::guestId()) {
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
            $user_string .= new UrlService(new HtmlService())
                ->getRootUrl() . 'admin.php?page=history';
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
                $image_edit_string = new UrlService(new HtmlService())
                    ->getRootUrl() . 'admin.php?page=photo-' . $line_image_id;
                $picture_url = new UrlService(new HtmlService())
                    ->makePictureUrl(
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
                    $rendered_label = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_description', $image_infos[$line_image_id]['label']);
                    $image_title .= ' ' . (is_string($rendered_label) ? $rendered_label : '');
                } else {
                    $image_edit_string = '';
                    $image_title .= ' unknown filename';
                }

                $image_string = '';
                $image_id = $line_image_id;

                $image_string =
                '<span><img src="' . @DerivativeImage::url(ImageStdParams::get_by_type(ImageStdParams::SQUARE), $element)
                . '" alt="' . $image_title . '" title="' . $image_title . '">';
            }

            if ($line_search_id !== null) {
                $search_detail_fields = $search_details[$line_search_id] ?? null;
                $search_detail_fields = is_array($search_detail_fields) ? $search_detail_fields : [];

                $allwords_words = is_array($search_detail_fields['allwords'] ?? null) ? ($search_detail_fields['allwords']['words'] ?? null) : null;

                $tags_words = is_array($search_detail_fields['tags'] ?? null) ? ($search_detail_fields['tags']['words'] ?? null) : null;
                $tags_words = is_array($tags_words) ? array_values(array_filter($tags_words, is_string(...))) : null;

                $date_posted = $search_detail_fields['date_posted'] ?? null;

                $cat_words = is_array($search_detail_fields['cat'] ?? null) ? ($search_detail_fields['cat']['words'] ?? null) : null;
                $cat_words = is_array($cat_words) ? array_values(array_filter($cat_words, is_string(...))) : null;

                $author_words = is_array($search_detail_fields['author'] ?? null) ? ($search_detail_fields['author']['words'] ?? null) : null;

                $added_by = $search_detail_fields['added_by'] ?? null;
                $added_by = is_array($added_by) ? array_values(array_filter($added_by, is_string(...))) : null;

                $filetypes = $search_detail_fields['filetypes'] ?? null;

                $is_falsy = static fn (mixed $v): bool => in_array($v, [null, false, 0, 0.0, '0', '', []], true);
                $search_detail = [
                    'allwords' => ! $is_falsy($allwords_words) ? $allwords_words : null,
                    'tags' => $tags_words !== null && $tags_words !== [] ? array_intersect_key($name_of_tag, array_flip($tags_words)) : null,
                    'date_posted' => ! $is_falsy($date_posted) ? $date_posted : null,
                    'cat' => $cat_words !== null && $cat_words !== [] ? array_intersect_key($name_of_category, array_flip($cat_words)) : null,
                    'author' => ! $is_falsy($author_words) ? $author_words : null,
                    'added_by' => $added_by !== null && $added_by !== [] ? array_intersect_key($username_of, array_flip($added_by)) : null,
                    'filetypes' => ! $is_falsy($filetypes) ? $filetypes : null,
                ];
            } else {
                $search_detail = null;
            }

            @++$sorted_members[$user_name];

            array_push(
                $result,
                [
                    'DATE' => \Piwigo\Core\DateHelper::formatDate($line_date ?? ''),
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
                    'FULL_CATEGORY_PATH' => $line_category_id !== null && isset($full_cat_path[$line_category_id]) ? strip_tags($full_cat_path[$line_category_id]) : Lang::t('Root') . $line_category_id,
                    'CATEGORY' => $line_category_id !== null && isset($name_of_category[$line_category_id]) ? $name_of_category[$line_category_id] : Lang::t('Root') . $line_category_id,
                    'SEARCH_ID' => $line_search_id,
                    'TAGS' => explode(',', (string) $tag_names),
                    'TAGIDS' => explode(',', $tag_ids),
                    'SEARCH_DETAILS' => $search_detail,
                ]
            );
        }

        $max_page = ceil(count($result) / 300);
        $result = array_reverse($result, true);
        $result = array_slice($result, ($param['pageNumber'] ?? 0) * 300, 300);

        // always array: see the loop-invariant comment on 'guests_IP' above.
        $guests_ip_final = $summary['guests_IP'];

        $summary['nb_guests'] = 0;
        if (count(array_keys($guests_ip_final)) > 0) {
            $summary['nb_guests'] = count(array_keys($guests_ip_final));

            // we delete the "guest" from the $username_of hash so that it is
            // avoided in next steps
            // Config::guestId() is SCHEMA-typed 'int' only.
            $guest_id_key = (string) \Piwigo\Config\Config::guestId();
            $username_of = array_diff_key($username_of, [
                $guest_id_key => true,
            ]);
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
            'NB_LINES' => Translator::get()->plural(
                '%d line filtered',
                '%d lines filtered',
                $page_nb_lines
            ),
            'FILESIZE' => $summary_total_filesize !== 0 ? ceil($summary_total_filesize / 1024) : 0,
            'USERS' => Translator::get()->plural(
                '%d user',
                '%d users',
                $summary_nb_members + $summary_nb_guests
            ),
            'MEMBERS' => $member_strings,
            'SORTED_MEMBERS' => $sorted_members,
            'GUESTS' => Translator::get()->plural(
                '%d guest',
                '%d guests',
                $summary_nb_guests
            ),
        ];

        unset($name_of_tag);

        return [
            'lines' => $result,
            'params' => $param,
            'maxPage' => ($max_page === 0.0) ? 1 : $max_page,
            'summary' => $search_summary,
        ];
    }
}
