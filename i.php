<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Image\pwg_image;
use Piwigo\Config\Config;
use Piwigo\Core\Logger;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageRect;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;

define('PHPWG_ROOT_PATH', './');

// fast bootstrap - no db connection
include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';

// Bootstrap global, set by include/config_default.inc.php.
/** @var array<string, mixed> $conf */
global $conf;
// Set by parse_request(), called below.
/** @var array<string, mixed> $page */
global $page;

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
// $conf['data_location'] needs narrowing here specifically (used before
// pwg_apply_env_to_conf() below widens $conf's per-key type info again —
// see the comment near the Logger construction).
$data_location = $conf['data_location'];
if (! is_string($data_location)) {
    die("Invalid \$conf['data_location'] configuration: expected a string.");
}
defined('Config::derivativeDir()') or define('Config::derivativeDir()', $data_location . 'i/');

include PHPWG_ROOT_PATH . 'include/env.inc.php';
pwg_load_env_file(PHPWG_ROOT_PATH);
$prefixeTable = '';
pwg_apply_env_to_conf($conf, $prefixeTable);

// $conf['data_location']/'log_dir'/'db_password' lost their specific string
// types the same way include/common.inc.php's equivalent config reads do
// (see the comment there): pwg_apply_env_to_conf(array &$conf, ...)'s by-ref
// `array` parameter erases the per-key type info PHPStan had built up for
// $conf, so we re-narrow here.
$log_data_location = $conf['data_location'];
$log_dir = $conf['log_dir'];
$db_password = $conf['db_password'];
if (! is_string($log_data_location) || ! is_string($log_dir) || ! is_string($db_password)) {
    die("Invalid \$conf['data_location']/'log_dir'/'db_password' configuration: expected strings.");
}

$logger = new Logger([
    'directory' => PHPWG_ROOT_PATH . $log_data_location . $log_dir,
    'severity' => $conf['log_level'],
    // we use an hashed filename to prevent direct file access, and we salt with
    // the db_password instead of secret_key because the log must be usable in i.php
    // (secret_key is in the database)
    'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
]);

function trigger_notify(string $event, mixed ...$args): void {}
function get_extension(?string $filename): string
{
    if ($filename === null) {
        return '';
    }

    $dot_position = strrpos($filename, '.');

    return $dot_position === false ? '' : substr($filename, $dot_position + 1);
}

function mkgetdir(string $dir): bool
{
    if (! is_dir($dir)) {
        /** @var array<string, mixed> $conf */
        global $conf;
        if (str_starts_with(PHP_OS, 'WIN')) {
            $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
        }
        $umask = umask(0);
        // config_default.inc.php always sets $conf['chmod_value'] to an int
        // literal (0777/0755); guard rather than trust a local-config override.
        $chmod_value = $conf['chmod_value'];
        $chmod_value = is_int($chmod_value) ? $chmod_value : 0755;
        $mkd = @mkdir($dir, $chmod_value, true);
        umask($umask);
        if ($mkd == false && ! is_dir($dir) /* retest existence because of potential concurrent i.php with slow file systems */) {
            return false;
        }

        $file = $dir . '/index.htm';
        file_exists($file) or (bool) @file_put_contents($file, 'Not allowed!');
    }
    if (! is_writable($dir)) {
        return false;
    }
    return true;
}

// end fast bootstrap

function ierror(string $msg, int $code): never
{
    /** @var Logger $logger */
    global $logger;
    if ($code == 301 || $code == 302) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        // default url is on html format
        $url = html_entity_decode($msg);
        $logger->debug($code . ' ' . $url, 'i.php', [
            'url' => $_SERVER['REQUEST_URI'],
        ]);
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit;
    }
    if ($code >= 400) {
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? null;
        if (! is_string($protocol)) {
            $protocol = 'HTTP/1.0';
        }
        if (($protocol != 'HTTP/1.1') && ($protocol != 'HTTP/1.0')) {
            $protocol = 'HTTP/1.0';
        }

        header("{$protocol} {$code} {$msg}", true, $code);
    }
    // todo improve
    echo $msg;
    $logger->error($code . ' ' . $msg, 'i.php', [
        'url' => $_SERVER['REQUEST_URI'],
    ]);
    exit;
}

function time_step(float &$step): int
{
    $tmp = $step;
    $step = microtime(true);
    return intval(1000 * ($step - $tmp));
}

/**
 * @return array{0: int, 1: int}
 */
function url_to_size(string $s): array
{
    $pos = strpos($s, 'x');
    if ($pos === false) {
        return [(int) $s, (int) $s];
    }
    return [(int) substr($s, 0, $pos), (int) substr($s, $pos + 1)];
}

/**
 * @param string[] $tokens
 */
function parse_custom_params(array $tokens): DerivativeParams
{
    if (count($tokens) < 1) {
        ierror('Empty array while parsing Sizing', 400);
    }

    $crop = 0;
    $min_size = null;

    $token = array_shift($tokens);
    if ($token[0] == 's') {
        $size = url_to_size(substr($token, 1));
    } elseif ($token[0] == 'e') {
        $crop = 1;
        $size = $min_size = url_to_size(substr($token, 1));
    } else {
        $size = url_to_size($token);
        if (count($tokens) < 2) {
            ierror('Sizing arr', 400);
        }

        $token = array_shift($tokens);
        $crop = char_to_fraction($token);

        $token = array_shift($tokens);
        assert($token !== null);
        $min_size = url_to_size($token);
    }
    return new DerivativeParams(new SizingParams($size, $crop, $min_size));
}

function parse_request(): DerivativeParams
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     */
    global $conf, $page;

    if ($conf['question_mark_in_urls'] == false and
         isset($_SERVER['PATH_INFO']) and ! empty($_SERVER['PATH_INFO'])) {
        $req = $_SERVER['PATH_INFO'];
        // PHPStan types superglobal reads as mixed; PATH_INFO is only ever
        // populated by the web server as a string (verified via the isset()
        // + !empty() guard above), but we still narrow defensively rather
        // than cast.
        $req = is_string($req) ? $req : '';
        $req = str_replace('//', '/', $req);
        $path_count = count(explode('/', $req));
        $page['root_path'] = PHPWG_ROOT_PATH . str_repeat('../', $path_count - 1);
    } else {
        $req = $_SERVER['QUERY_STRING'];
        $req = is_string($req) ? $req : '';
        if ((bool) ($pos = strpos($req, '&'))) {
            $req = substr($req, 0, $pos);
        }
        $req = rawurldecode($req);
        /*foreach (array_keys($_GET) as $keynum => $key)
        {
          $req = $key;
          break;
        }*/
        $page['root_path'] = PHPWG_ROOT_PATH;
    }

    $req = ltrim($req, '/');

    $req_tokens = preg_split('#/+#', $req);
    if ($req_tokens === false) {
        ierror('Invalid request', 400);
    }
    // config_default.inc.php always sets $conf['sync_chars_regex'] to a
    // string literal; guard rather than trust a local-config override.
    $sync_chars_regex = $conf['sync_chars_regex'];
    if (! is_string($sync_chars_regex)) {
        ierror('Invalid sync_chars_regex configuration', 500);
    }
    foreach ($req_tokens as $token) {
        (bool) preg_match($sync_chars_regex, $token) or ierror('Invalid chars in request', 400);
    }

    $page['derivative_path'] = PHPWG_ROOT_PATH . Config::derivativeDir() . $req;

    $pos = strrpos($req, '.');
    $pos !== false || ierror('Missing .', 400);
    $ext = substr($req, $pos);
    $page['derivative_ext'] = $ext;
    $req = substr($req, 0, $pos);

    $pos = strrpos($req, '-');
    $pos !== false || ierror('Missing -', 400);
    $deriv = substr($req, $pos + 1);
    $req = substr($req, 0, $pos);

    $deriv = explode('_', $deriv);
    foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
        if (derivative_to_url($type) == $deriv[0]) {
            $page['derivative_type'] = $type;
            $page['derivative_params'] = $params;
            break;
        }
    }

    if (! isset($page['derivative_type'])) {
        if (derivative_to_url(IMG_CUSTOM) == $deriv[0]) {
            $page['derivative_type'] = IMG_CUSTOM;
        } else {
            ierror('Unknown parsing type', 400);
        }
    }
    array_shift($deriv);

    if ($page['derivative_type'] == IMG_CUSTOM) {
        $params = $page['derivative_params'] = parse_custom_params($deriv);
        ImageStdParams::apply_global($params);

        if ($params->sizing->ideal_size[0] < 20 or $params->sizing->ideal_size[1] < 20) {
            ierror('Invalid size', 400);
        }
        if ($params->sizing->max_crop < 0 or $params->sizing->max_crop > 1) {
            ierror('Invalid crop', 400);
        }
        $greatest = ImageStdParams::get_by_type(IMG_4XLARGE);

        $key = [];
        $params->add_url_tokens($key);
        $key = implode('_', $key);
        if (! isset(ImageStdParams::$custom[$key])) {
            ierror('Size not allowed', 403);
        }
    }

    if (is_file(PHPWG_ROOT_PATH . $req . $ext)) {
        $req = './' . $req; // will be used to match #iamges.path
    } elseif (is_file(PHPWG_ROOT_PATH . '../' . $req . $ext)) {
        $req = '../' . $req;
    }

    $page['src_location'] = $req . $ext;
    $page['src_path'] = PHPWG_ROOT_PATH . $page['src_location'];
    $page['src_url'] = $page['root_path'] . $page['src_location'];

    // Every non-erroring path above sets $page['derivative_params'] itself
    // (either from the ImageStdParams::get_defined_type_map() match or from
    // parse_custom_params()) before reaching this point; guard explicitly
    // rather than trust flow analysis across the foreach/if branches above.
    $derivative_params = $page['derivative_params'];
    if (! $derivative_params instanceof DerivativeParams) {
        ierror('Internal error: unresolved derivative params', 500);
    }
    return $derivative_params;
}

function try_switch_source(DerivativeParams $params, int $original_mtime): bool
{
    /** @var array<string, mixed> $page */
    global $page;
    if (! isset($page['original_size'])) {
        return false;
    }

    // $page['original_size'] is only ever set (see i.php's top-level flow)
    // from a DB row's width/height columns, which pwg_db_fetch_assoc() types
    // as numeric strings; guard the shape and coerce rather than trust it.
    $original_size = $page['original_size'];
    if (! is_array($original_size)
        || ! isset($original_size[0], $original_size[1])
        || ! is_numeric($original_size[0])
        || ! is_numeric($original_size[1])) {
        return false;
    }
    $original_size = [(int) $original_size[0], (int) $original_size[1]];

    if ($page['rotation_angle'] == 90 || $page['rotation_angle'] == 270) {
        $tmp = $original_size[0];
        $original_size[0] = $original_size[1];
        $original_size[1] = $tmp;
    }
    $dsize = $params->compute_final_size($original_size);

    $use_watermark = $params->use_watermark;
    if ($use_watermark) {
        $use_watermark = $params->will_watermark($dsize);
    }

    $candidates = [];
    foreach (ImageStdParams::get_defined_type_map() as $candidate) {
        if ($candidate->type == $params->type) {
            continue;
        }
        if ($candidate->use_watermark != $use_watermark) {
            continue;
        }
        if ($candidate->max_width() < $params->max_width() || $candidate->max_height() < $params->max_height()) {
            continue;
        }
        $candidate_size = $candidate->compute_final_size($original_size);
        if ($dsize != $params->compute_final_size($candidate_size)) {
            continue;
        }

        if ($params->sizing->max_crop == 0) {
            if ($candidate->sizing->max_crop != 0) {
                continue;
            }
        } else {
            if ($use_watermark && $candidate->use_watermark) {
                continue;
            } // a square that requires watermark should not be generated from a larger derivative with watermark, because if the watermark is not centered on the large image, it will be cropped.
            if ($candidate->sizing->max_crop != 0) {
                continue;
            } // this could be optimized
            if ($candidate_size[0] < $params->sizing->min_size[0] || $candidate_size[1] < $params->sizing->min_size[1]) {
                continue;
            }
        }
        $candidates[] = $candidate;
    }

    foreach (array_reverse($candidates) as $candidate) {
        $candidate_path = $page['derivative_path'];
        if (! is_string($candidate_path)) {
            continue;
        }
        $candidate_path = str_replace('-' . derivative_to_url($params->type), '-' . derivative_to_url($candidate->type), $candidate_path);
        $candidate_mtime = @filemtime($candidate_path);
        if ($candidate_mtime === false
          || $candidate_mtime < $original_mtime
          || $candidate_mtime < $candidate->last_mod_time) {
            continue;
        }
        $params->use_watermark = false;
        $params->sharpen = min(1, $params->sharpen);
        $page['src_path'] = $candidate_path;
        $root_path = $page['root_path'];
        $root_path = is_string($root_path) ? $root_path : '';
        $page['src_url'] = $root_path . substr($candidate_path, strlen(PHPWG_ROOT_PATH));
        $page['rotation_angle'] = 0;
        return true;
    }
    return false;
}

function send_derivative(false|int $expires): void
{
    /** @var array<string, mixed> $page */
    global $page;

    // 'derivative_path' is always built as a string concatenation inside
    // parse_request() (a separate scope PHPStan can't trace here); narrow
    // once for every read in this function rather than trust a bare cast.
    $derivative_path = $page['derivative_path'];
    $derivative_path = is_string($derivative_path) ? $derivative_path : '';

    if (isset($_GET['ajaxload']) and $_GET['ajaxload'] == 'true') {
        include_once PHPWG_ROOT_PATH . 'include/functions_cookie.inc.php';
        include_once PHPWG_ROOT_PATH . 'include/functions_url.inc.php';

        echo json_encode([
            'url' => embellish_url(get_absolute_root_url() . $derivative_path),
        ]);
        return;
    }
    $fp = fopen($derivative_path, 'rb');
    if ($fp === false) {
        ierror('Unable to open derivative file', 500);
    }

    $fstat = fstat($fp);
    if ($fstat === false) {
        ierror('Unable to stat derivative file', 500);
    }
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fstat['mtime']) . ' GMT');
    if ($expires !== false) {
        header('Expires: ' . gmdate('D, d M Y H:i:s', $expires) . ' GMT');
    }
    header('Connection: close');

    $derivative_ext = $page['derivative_ext'];
    $derivative_ext = is_string($derivative_ext) ? $derivative_ext : '';

    $ctype = 'application/octet-stream';
    switch (strtolower($derivative_ext)) {
        case '.jpe': case '.jpeg': case '.jpg': $ctype = 'image/jpeg';
            break;
        case '.png': $ctype = 'image/png';
            break;
        case '.gif': $ctype = 'image/gif';
            break;
        case '.webp': $ctype = 'image/webp';
            break;
    }
    header("Content-Type: {$ctype}");

    fpassthru($fp);
    fclose($fp);
}

/**
 * @param array<int|string, mixed>|string $value
 * @return mixed the unserialized value, false if $value is a malformed
 *   serialized string, or $value itself unchanged if it isn't a string
 */
function safe_unserialize($value)
{
    if (is_string($value)) {
        return unserialize($value);
    }
    return $value;
}

$page = [];
$begin = $step = microtime(true);
$timing = [];
foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
    $timing[$k] = '';
}

// $conf['dblayer']/'db_host'/'db_user'/'db_base' lost their specific string
// types the same way described above (see the comment near the Logger
// construction); 'db_password' was already re-narrowed there and is reused
// as-is since nothing reassigns it in between.
$dblayer = $conf['dblayer'];
if (! is_string($dblayer)) {
    ierror("Invalid \$conf['dblayer'] configuration: expected a string.", 500);
}
include_once PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';
include_once PHPWG_ROOT_PATH . '/include/derivative_params.inc.php';
include_once PHPWG_ROOT_PATH . '/include/derivative_std_params.inc.php';

$db_host = $conf['db_host'];
$db_user = $conf['db_user'];
$db_base = $conf['db_base'];
if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_base)) {
    ierror("Invalid \$conf['db_host']/'db_user'/'db_base' configuration: expected strings.", 500);
}

try {
    pwg_db_connect(
        $db_host,
        $db_user,
        $db_password,
        $db_base
    );
} catch (Exception $e) {
    $logger->error($e->getMessage(), 'i.php');
}
pwg_db_check_charset();

$query = '
SELECT param, value
  FROM ' . $prefixeTable . 'config
  WHERE param IN (\'derivatives\', \'disabled_derivatives\')
;';

$result = pwg_query($query);
while ((bool) ($row = pwg_db_fetch_assoc($result))) {
    // 'param' is the config table's primary key column (never NULL in
    // practice), but pwg_db_fetch_assoc() types every column as
    // string|null, so an array key still needs narrowing.
    if (! is_string($row['param'])) {
        continue;
    }
    $conf[$row['param']] = $row['value'];
}
ImageStdParams::load_from_db();

// parse_request() fills these by mutating the $page global from inside its
// own function scope; the defaults below only keep analysis sound for the
// reads that follow (always overwritten before use in every real path).
$page['root_path'] = '';
$page['derivative_path'] = '';
$page['derivative_ext'] = '';
$page['derivative_type'] = null;
$page['coi'] = null;
$page['src_location'] = '';
$page['src_path'] = '';
$page['src_url'] = '';
$page['original_size'] = null;
$page['rotation_angle'] = 0;

// parse_request() always either sets $page['derivative_params'] itself
// (both non-error paths do) or calls ierror(), which never returns — so its
// return value here is never null; returning it directly (rather than
// re-reading $page['derivative_params'] afterwards) keeps this typed
// soundly, since PHPStan can't trace the global mutation back through the
// call.
$params = parse_request();
// var_export($page);

$src_mtime = @filemtime($page['src_path']);
if ($src_mtime === false) {
    ierror('Source not found', 404);
}

$need_generate = false;
$derivative_mtime = @filemtime($page['derivative_path']);
if ($derivative_mtime === false or
    $derivative_mtime < $src_mtime or
    $derivative_mtime < $params->last_mod_time) {
    $need_generate = true;
}

$expires = false;
$now = time();
if (isset($_GET['b'])) {
    $expires = $now + 100;
    header('Cache-control: no-store, max-age=100');
} elseif ($now > (max($src_mtime, $params->last_mod_time) + 24 * 3600)) {// somehow arbitrary - if derivative params or src didn't change for the last 24 hours, we send an expire header for several days
    $expires = $now + 10 * 24 * 3600;
}

if (! $need_generate) {
    $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
    if (is_string($if_modified_since)
      and strtotime($if_modified_since) == $derivative_mtime) {// send the last mod time of the file back
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT', true, 304);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT', true, 304);
        exit;
    }
    send_derivative($expires);
    exit;
}

$page['coi'] = null;
if (! str_contains($page['src_location'], '/pwg_representative/')
    && ! str_contains($page['src_location'], 'themes/')
    && ! str_contains($page['src_location'], 'plugins/')) {
    try {
        $query = '
SELECT *
  FROM ' . $prefixeTable . 'images
  WHERE path=\'' . addslashes($page['src_location']) . '\'
;';

        if ((bool) ($row = pwg_db_fetch_assoc(pwg_query($query)))) {
            if (isset($row['width'])) {
                $page['original_size'] = [$row['width'], $row['height']];
            }
            $page['coi'] = $row['coi'];

            if (! isset($row['rotation'])) {
                $page['rotation_angle'] = pwg_image::get_rotation_angle($page['src_path']);

                single_update(
                    $prefixeTable . 'images',
                    [
                        'rotation' => pwg_image::get_rotation_code_from_angle($page['rotation_angle']),
                    ],
                    [
                        'id' => $row['id'],
                    ]
                );
            } else {
                // get_rotation_angle_from_code()'s docblock confirms
                // (empirically, against the real DB) that this driver's
                // fetch_assoc() always returns a numeric string here; guard
                // it explicitly rather than casting, since a cast alone
                // wouldn't satisfy the numeric-string contract.
                // isset($row['rotation']) above already narrows this to string.
                $rotation = $row['rotation'];
                if (! is_numeric($rotation)) {
                    ierror('Invalid rotation value in database', 500);
                }
                $page['rotation_angle'] = pwg_image::get_rotation_angle_from_code($rotation);
            }
        }
        if (! (bool) $row) {
            ierror('Db file path not found', 404);
        }
    } catch (Exception $e) {
        $logger->error($e->getMessage(), 'i.php');
    }
} else {
    $page['rotation_angle'] = 0;
}
pwg_db_close();

if (! try_switch_source($params, $src_mtime) && $params->type == IMG_CUSTOM) {
    $sharpen = 0;
    foreach (ImageStdParams::get_defined_type_map() as $std_params) {
        $sharpen += $std_params->sharpen;
    }
    $params->sharpen = round($sharpen / count(ImageStdParams::get_defined_type_map()));
}

if (! mkgetdir(dirname($page['derivative_path']))) {
    ierror('dir create error', 500);
}

ignore_user_abort(true);
@set_time_limit(0);

$image = new pwg_image($page['src_path']);
$timing['load'] = time_step($step);

$changes = 0;

// rotate
if ($page['rotation_angle'] != 0) {
    $image->rotate($page['rotation_angle']);
    $changes++;
    $timing['rotate'] = time_step($step);
}

// Crop & scale
$o_size = $d_size = [(int) $image->get_width(), (int) $image->get_height()];
// $crop_rect/$scaled_size are by-ref out-params; pre-declare as null so the
// call site's argument type matches SizingParams::compute()'s ?ImageRect/
// ?array parameter types (an undefined variable is otherwise seen as mixed).
$crop_rect = null;
$scaled_size = null;
$params->sizing->compute($o_size, $page['coi'], $crop_rect, $scaled_size);
if ((bool) $crop_rect) {
    $changes++;
    $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
    $timing['crop'] = time_step($step);
}

if ((bool) $scaled_size) {
    $changes++;
    $image->resize($scaled_size[0], $scaled_size[1]);
    $d_size = [(int) $scaled_size[0], (int) $scaled_size[1]];
    $timing['scale'] = time_step($step);
}

if ((bool) $params->sharpen) {
    $changes += $image->sharpen($params->sharpen);
    $timing['sharpen'] = time_step($step);
}

if ($params->will_watermark($d_size)) {
    $wm = ImageStdParams::get_watermark();
    $wm_image = new pwg_image(PHPWG_ROOT_PATH . $wm->file);
    $wm_size = [(int) $wm_image->get_width(), (int) $wm_image->get_height()];
    if ($d_size[0] < $wm_size[0] or $d_size[1] < $wm_size[1]) {
        $wm_scaling_params = SizingParams::classic($d_size[0], $d_size[1]);
        // $tmp/$wm_scaled_size are by-ref out-params; pre-declare as null
        // (see the analogous compute() call above).
        $tmp = null;
        $wm_scaled_size = null;
        $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
        if ($wm_scaled_size === null) {
            // compute()'s $scale_size out-param is only null when neither
            // ratio exceeds 1 — impossible here, since we're inside the same
            // "watermark bigger than destination in some dimension" guard
            // that condition is derived from. Guard explicitly instead of
            // asserting: assert() is a no-op in this environment
            // (zend.assertions=-1) and would silently let a null through to
            // the array accesses below if the invariant were ever violated.
            ierror('Internal error: unexpected watermark scaling result', 500);
        }
        $wm_size = $wm_scaled_size;
        $wm_image->resize($wm_scaled_size[0], $wm_scaled_size[1]);
    }
    $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
    $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));
    if ($image->compose($wm_image, $x, $y, $wm->opacity)) {
        $changes++;
        if ((bool) $wm->xrepeat || (bool) $wm->yrepeat) {
            $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
            $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));

            for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                    if (! (bool) $i && ! (bool) $j) {
                        continue;
                    }
                    $x2 = $x + $i * $xpad;
                    $y2 = $y + $j * $ypad;
                    if ($x2 >= 0 && $x2 + $wm_size[0] < $d_size[0] &&
                        $y2 >= 0 && $y2 + $wm_size[1] < $d_size[1]) {
                        if (! $image->compose($wm_image, $x2, $y2, $wm->opacity)) {
                            break;
                        }
                    }
                }
            }
        }
    }
    $wm_image->destroy();
    $timing['watermark'] = time_step($step);
}

// no change required - redirect to source
if (! (bool) $changes) {
    header('X-i: No change');
    ierror($page['src_url'], 301);
}

if ($conf['derivatives_strip_metadata_threshold'] > $d_size[0] * $d_size[1]) {// strip metadata for small images
    $image->strip();
}

$compression_quality = ImageStdParams::$quality;

// for big sizing never go beyond 75 quality
if (in_array($page['derivative_type'], [IMG_4XLARGE, IMG_3XLARGE])) {
    $compression_quality = min(ImageStdParams::$quality, 75);
}

$image->write($page['derivative_path']);
$image->destroy();
@chmod($page['derivative_path'], 0644);
$timing['save'] = time_step($step);

send_derivative($expires);
$timing['send'] = time_step($step);

$timing['total'] = time_step($begin);

if ($logger->severity() >= Logger::DEBUG) {
    $logger->debug('', 'i.php', [
        'src_path' => basename($page['src_path']),
        'derivative_path' => basename($page['derivative_path']),
        'o_size' => $o_size[0] . ' ' . $o_size[1] . ' ' . ($o_size[0] * $o_size[1]),
        'd_size' => $d_size[0] . ' ' . $d_size[1] . ' ' . ($d_size[0] * $d_size[1]),
        'mem_usage' => function_exists('memory_get_peak_usage') ? round(memory_get_peak_usage() / (1024 * 1024), 1) : '',
        'timing' => $timing,
        'quality' => $compression_quality,
    ]);
}
