<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang, $prefixeTable;

use Piwigo\Admin\Image\PwgImage;
use Piwigo\Core\Logger;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';

// fast bootstrap - no db connection
\Piwigo\Config\ConfigLoader::applyDefaults();

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
defined('PWG_DERIVATIVE_DIR') or define('PWG_DERIVATIVE_DIR', \Piwigo\Config\Config::dataLocation().'i/');

\Piwigo\Config\ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
\Piwigo\Config\ConfigLoader::applyEnvOverrides();

$prefixeTable = \Piwigo\Config\Config::dbPrefix();

$logger = new Logger(array(
  'directory' => PHPWG_ROOT_PATH . \Piwigo\Config\Config::dataLocation() . \Piwigo\Config\Config::logDir(),
  'severity' => \Piwigo\Config\Config::logLevel(),
  // we use an hashed filename to prevent direct file access, and we salt with
  // the db_password instead of secret_key because the log must be usable in i.php
  // (secret_key is in the database)
  'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . \Piwigo\Config\Config::dbPassword()) . '.txt',
  ));
\Piwigo\Core\LoggerRegistry::set($logger);


// Subset of MKGETDIR_* constants normally defined in functions.inc.php.
// i.php skips that include, so we define them here for Logger and mkgetdir.
define('MKGETDIR_NONE', 0);
define('MKGETDIR_RECURSIVE', 1);
define('MKGETDIR_DIE_ON_ERROR', 2);
define('MKGETDIR_PROTECT_INDEX', 4);
define('MKGETDIR_PROTECT_HTACCESS', 8);
define('MKGETDIR_DEFAULT', MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX);

function trigger_notify(string $event, mixed ...$args): void
{
}
function trigger_change(string $event, mixed ...$args): mixed
{
    return $args[0] ?? null;
}
function get_extension(string $filename): string
{
    $ext = strrchr($filename, '.');
    return $ext !== false ? substr($ext, 1) : '';
}

function mkgetdir(string $dir, int $flags = 0): bool
{
    if (!is_dir($dir)) {
        if (substr(PHP_OS, 0, 3) == 'WIN') {
            $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
        }
        $umask = umask(0);
        set_error_handler(static fn (): bool => true);
        try {
            $mkd = mkdir($dir, \Piwigo\Config\Config::chmodValue(), true);
        } finally {
            restore_error_handler();
        }
        umask($umask);
        if ($mkd == false && !is_dir($dir) /* retest existence because of potential concurrent i.php with slow file systems*/) {
            return false;
        }

        $file = $dir.'/index.htm';
        if (!file_exists($file)) {
            set_error_handler(static fn (): bool => true);
            try {
                file_put_contents($file, 'Not allowed!');
            } finally {
                restore_error_handler();
            }
        }
    }
    if (!is_writable($dir)) {
        return false;
    }
    return true;
}

// end fast bootstrap

function ierror(string $msg, int $code): never
{
    $logger = \Piwigo\Core\LoggerRegistry::current();
    if ($code == 301 || $code == 302) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        // default url is on html format
        $url = html_entity_decode($msg);
        $logger->debug($code . ' ' . $url, array(
          'url' => $_SERVER['REQUEST_URI'],
          ));
        header('Request-URI: '.$url);
        header('Content-Location: '.$url);
        header('Location: '.$url);
        exit;
    }
    if ($code >= 400) {
        $protocolRaw = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0';
        $protocol = is_string($protocolRaw) ? $protocolRaw : 'HTTP/1.0';
        if (('HTTP/1.1' != $protocol) && ('HTTP/1.0' != $protocol)) {
            $protocol = 'HTTP/1.0';
        }

        header($protocol.' '.$code.' '.$msg, true, $code);
    }
    //todo improve
    echo $msg;
    $logger->error($code . ' ' . $msg, array(
        'url' => $_SERVER['REQUEST_URI'],
        ));
    exit;
}

function time_step(float &$step): int
{
    $tmp = $step;
    $step = microtime(true);
    return intval(1000 * ($step - $tmp));
}

/** @return int[] */
function url_to_size(string $s): array
{
    $pos = strpos($s, 'x');
    if ($pos === false) {
        return array((int)$s, (int)$s);
    }
    return array((int)substr($s, 0, $pos), (int)substr($s, $pos + 1));
}

/** @param string[] $tokens */
function parse_custom_params(array $tokens): \Piwigo\Image\DerivativeParams
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
        $min_size = url_to_size($token ?? '');
    }
    return new DerivativeParams(new SizingParams($size, $crop, $min_size));
}

function parse_request(\Piwigo\Image\ImageDerivativeContext $ctx): \Piwigo\Image\DerivativeParams
{
    if (\Piwigo\Config\Config::questionMarkInUrls() == false and
         isset($_SERVER['PATH_INFO']) and !empty($_SERVER['PATH_INFO'])) {
        $req = is_scalar($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
        $req = str_replace('//', '/', $req);
        $path_count = count(explode('/', $req));
        $ctx->rootPath = PHPWG_ROOT_PATH.str_repeat('../', $path_count - 1);
    } else {
        $req = is_scalar($_SERVER['QUERY_STRING'] ?? null) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($pos = strpos($req, '&')) {
            $req = substr($req, 0, $pos);
        }
        $req = rawurldecode($req);
        $ctx->rootPath = PHPWG_ROOT_PATH;
    }

    $req = ltrim($req, '/');

    foreach (preg_split('#/+#', $req) ?: [] as $token) {
        preg_match(\Piwigo\Config\Config::syncCharsRegex(), $token) or ierror('Invalid chars in request', 400);
    }

    $ctx->derivativePath = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR.$req;

    $pos = strrpos($req, '.');
    $pos !== false || ierror('Missing .', 400);
    $ext = substr($req, $pos);
    $ctx->derivativeExt = $ext;
    $req = substr($req, 0, $pos);

    $pos = strrpos($req, '-');
    $pos !== false || ierror('Missing -', 400);
    $deriv = substr($req, $pos + 1);
    $req = substr($req, 0, $pos);

    $deriv = explode('_', $deriv);
    foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
        if (derivative_to_url($type) == $deriv[0]) {
            $ctx->derivativeType = $type;
            $ctx->derivativeParams = $params;
            break;
        }
    }

    if ($ctx->derivativeType === null) {
        if (derivative_to_url(IMG_CUSTOM) == $deriv[0]) {
            $ctx->derivativeType = IMG_CUSTOM;
        } else {
            ierror('Unknown parsing type', 400);
        }
    }
    array_shift($deriv);

    if ($ctx->derivativeType == IMG_CUSTOM) {
        $params = $ctx->derivativeParams = parse_custom_params($deriv);
        ImageStdParams::apply_global($params);

        if ($params->sizing->ideal_size[0] < 20 or $params->sizing->ideal_size[1] < 20) {
            ierror('Invalid size', 400);
        }
        if ($params->sizing->max_crop < 0 or $params->sizing->max_crop > 1) {
            ierror('Invalid crop', 400);
        }
        $key = array();
        $params->add_url_tokens($key);
        $key = implode('_', $key);
        if (!isset(ImageStdParams::$custom[$key])) {
            ierror('Size not allowed', 403);
        }
    }

    if (is_file(PHPWG_ROOT_PATH.$req.$ext)) {
        $req = './'.$req; // will be used to match #iamges.path
    } elseif (is_file(PHPWG_ROOT_PATH.'../'.$req.$ext)) {
        $req = '../'.$req;
    }

    $ctx->srcLocation = $req.$ext;
    $ctx->srcPath = PHPWG_ROOT_PATH.$ctx->srcLocation;
    $ctx->srcUrl = $ctx->rootPath.$ctx->srcLocation;

    $dp = $ctx->derivativeParams;
    if (!($dp instanceof \Piwigo\Image\DerivativeParams)) {
        ierror('Invalid derivative params', 400);
    }
    return $dp;
}

function try_switch_source(\Piwigo\Image\DerivativeParams $params, ?int $original_mtime, \Piwigo\Image\ImageDerivativeContext $ctx): bool
{
    if ($ctx->originalSize === null) {
        return false;
    }

    $original_size = $ctx->originalSize;
    if ($ctx->rotationAngle == 90 || $ctx->rotationAngle == 270) {
        [$original_size[0], $original_size[1]] = [$original_size[1], $original_size[0]];
    }
    $dsize = $params->compute_final_size($original_size);

    $use_watermark = $params->use_watermark;
    if ($use_watermark) {
        $use_watermark = $params->will_watermark($dsize);
    }

    $candidates = array();
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
            } //a square that requires watermark should not be generated from a larger derivative with watermark, because if the watermark is not centered on the large image, it will be cropped.
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
        $candidate_path = str_replace('-'.derivative_to_url($params->type), '-'.derivative_to_url($candidate->type), $ctx->derivativePath);
        $candidate_mtime = \Piwigo\Core\Filesystem::tryFileMtime($candidate_path);
        if ($candidate_mtime === false
          || $candidate_mtime < $original_mtime
          || $candidate_mtime < $candidate->last_mod_time) {
            continue;
        }
        $params->use_watermark = false;
        $params->sharpen = min(1, $params->sharpen);
        $ctx->srcPath = $candidate_path;
        $ctx->srcUrl = $ctx->rootPath . substr($candidate_path, strlen(PHPWG_ROOT_PATH));
        $ctx->rotationAngle = 0;
        return true;
    }
    return false;
}

function send_derivative(int|false $expires, \Piwigo\Image\ImageDerivativeContext $ctx): void
{
    if (isset($_GET['ajaxload']) and $_GET['ajaxload'] == 'true') {
        include_once(PHPWG_ROOT_PATH.'include/functions_cookie.inc.php');
        include_once(PHPWG_ROOT_PATH.'include/functions_url.inc.php');

        echo json_encode(array( 'url' => embellish_url(get_absolute_root_url().$ctx->derivativePath) ));
        return;
    }
    $fp = fopen($ctx->derivativePath, 'rb');
    if ($fp === false) {
        ierror('Cannot open derivative file', 500);
    }

    $fstat = fstat($fp);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $fstat !== false ? $fstat['mtime'] : 0).' GMT');
    if ($expires !== false) {
        header('Expires: '.gmdate('D, d M Y H:i:s', $expires).' GMT');
    }
    header('Connection: close');

    $ctype = 'application/octet-stream';
    switch (strtolower($ctx->derivativeExt)) {
        case '.jpe': case '.jpeg': case '.jpg': $ctype = 'image/jpeg';
            break;
        case '.png': $ctype = 'image/png';
            break;
        case '.gif': $ctype = 'image/gif';
            break;
        case '.webp': $ctype = 'image/webp';
            break;
    }
    header("Content-Type: $ctype");

    fpassthru($fp);
    fclose($fp);
}

/**
 * @param array<mixed>|string $value
 * @return array<mixed>
 */
function safe_unserialize(array|string $value): array
{
    if (is_string($value)) {
        $result = unserialize($value);
        return is_array($result) ? $result : [];
    }
    return $value;
}

$ctx = new \Piwigo\Image\ImageDerivativeContext();
$begin = $step = microtime(true);
$timing = array();
foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
    $timing[$k] = '';
}

include_once(PHPWG_ROOT_PATH . 'include/dblayer/functions_mysqli.inc.php');
include_once(PHPWG_ROOT_PATH .'/include/derivative_params.inc.php');
include_once(PHPWG_ROOT_PATH .'/include/derivative_std_params.inc.php');

try {
    pwg_db_connect(
        \Piwigo\Config\Config::dbHost(),
        \Piwigo\Config\Config::dbUser(),
        \Piwigo\Config\Config::dbPassword(),
        \Piwigo\Config\Config::dbName()
    );
} catch (Exception $e) {
    $logger->error($e->getMessage());
}
pwg_db_check_charset();

$query = '
SELECT param, value
  FROM '.$prefixeTable.'config
  WHERE param IN (\'derivatives\', \'disabled_derivatives\')
;';

$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    if (is_string($row['param'] ?? null)) {
        \Piwigo\Config\Config::override($row['param'], $row['value']);
    }
}
ImageStdParams::load_from_db();


$dpRaw = parse_request($ctx);
$params = trigger_change('derivative_params_get', $dpRaw);

$src_mtime = \Piwigo\Core\Filesystem::tryFileMtime($ctx->srcPath);
if ($src_mtime === false) {
    ierror('Source not found', 404);
}

$need_generate = false;
$derivative_mtime = \Piwigo\Core\Filesystem::tryFileMtime($ctx->derivativePath);
if ($derivative_mtime === false or
    $derivative_mtime < $src_mtime or
    $derivative_mtime < $params->last_mod_time) {
    $need_generate = true;
    $derivative_mtime = $derivative_mtime ?: 0;
}

$expires = false;
$now = time();
if (isset($_GET['b'])) {
    $expires = $now + 100;
    header('Cache-control: no-store, max-age=100');
} elseif ($now > (max($src_mtime, $params->last_mod_time) + 24 * 3600)) {// somehow arbitrary - if derivative params or src didn't change for the last 24 hours, we send an expire header for several days
    $expires = $now + 10 * 24 * 3600;
}

if (!$need_generate) {
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
      and (is_scalar($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) == $derivative_mtime)) {// send the last mod time of the file back
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $derivative_mtime).' GMT', true, 304);
        header('Expires: '.gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600).' GMT', true, 304);
        exit;
    }
    send_derivative($expires, $ctx);
    exit;
}

$ctx->coi = null;
if (strpos($ctx->srcLocation, '/pwg_representative/') === false
    && strpos($ctx->srcLocation, 'themes/') === false
    && strpos($ctx->srcLocation, 'plugins/') === false) {
    try {
        $query = '
SELECT *
  FROM '.$prefixeTable.'images
  WHERE path=\''.addslashes($ctx->srcLocation).'\'
;';

        if (($row = pwg_db_fetch_assoc(pwg_query($query)))) {
            if (isset($row['width'])) {
                $ctx->originalSize = [is_numeric($row['width']) ? (int)$row['width'] : 0, is_numeric($row['height']) ? (int)$row['height'] : 0];
            }
            $ctx->coi = is_string($row['coi'] ?? null) ? $row['coi'] : null;

            if (!isset($row['rotation'])) {
                $ctx->rotationAngle = PwgImage::get_rotation_angle($ctx->srcPath);

                single_update(
                    $prefixeTable.'images',
                    array('rotation' => PwgImage::get_rotation_code_from_angle($ctx->rotationAngle ?? 0)),
                    array('id' => $row['id'])
                );
            } else {
                $ctx->rotationAngle = PwgImage::get_rotation_angle_from_code((int) $row['rotation']);
            }
        }
        if (!$row) {
            ierror('Db file path not found', 404);
        }
    } catch (Exception $e) {
        $logger->error($e->getMessage());
    }
} else {
    $ctx->rotationAngle = 0;
}
pwg_db_close();

if (!try_switch_source($params, $src_mtime, $ctx) && $params->type == IMG_CUSTOM) {
    $sharpen = 0;
    foreach (ImageStdParams::get_defined_type_map() as $std_params) {
        $sharpen += $std_params->sharpen;
    }
    $params->sharpen = round($sharpen / count(ImageStdParams::get_defined_type_map()));
}

if (!mkgetdir(dirname($ctx->derivativePath))) {
    ierror('dir create error', 500);
}

ignore_user_abort(true);
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

$image = new PwgImage($ctx->srcPath);
$timing['load'] = time_step($step);

$changes = 0;

// rotate
if (0 != $ctx->rotationAngle) {
    $image->rotate((int) $ctx->rotationAngle);
    $changes++;
    $timing['rotate'] = time_step($step);
}

// Crop & scale
$o_size = $d_size = array($image->get_width(),$image->get_height());
$crop_rect = null;
$scaled_size = null;
$params->sizing->compute($o_size, $ctx->coi, $crop_rect, $scaled_size);
if ($crop_rect) {
    $changes++;
    $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
    $timing['crop'] = time_step($step);
}

if ($scaled_size) {
    $changes++;
    $image->resize((int) $scaled_size[0], (int) $scaled_size[1]);
    $d_size = $scaled_size;
    $timing['scale'] = time_step($step);
}

if ($params->sharpen) {
    $changes += $image->sharpen((int)$params->sharpen);
    $timing['sharpen'] = time_step($step);
}

if ($params->will_watermark($d_size)) {
    $wm = ImageStdParams::get_watermark();
    $wm_image = new PwgImage(PHPWG_ROOT_PATH.$wm->file);
    $wm_size = array($wm_image->get_width(),$wm_image->get_height());
    if ($d_size[0] < $wm_size[0] or $d_size[1] < $wm_size[1]) {
        $wm_scaling_params = SizingParams::classic((int) $d_size[0], (int) $d_size[1]);
        $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
        if ($wm_scaled_size !== null) {
            $wm_size = $wm_scaled_size;
            $wm_image->resize((int) $wm_scaled_size[0], (int) $wm_scaled_size[1]);
        }
    }
    $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
    $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));
    if ($image->compose($wm_image, (int)$x, (int)$y, $wm->opacity)) {
        $changes++;
        if ($wm->xrepeat || $wm->yrepeat) {
            $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
            $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));

            for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                    if (!$i && !$j) {
                        continue;
                    }
                    $x2 = $x + $i * $xpad;
                    $y2 = $y + $j * $ypad;
                    if ($x2 >= 0 && $x2 + $wm_size[0] < $d_size[0] &&
                        $y2 >= 0 && $y2 + $wm_size[1] < $d_size[1]) {
                        if (!$image->compose($wm_image, (int)$x2, (int)$y2, $wm->opacity)) {
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
if (!$changes) {
    header('X-i: No change');
    ierror($ctx->srcUrl, 301);
}

if ($d_size[0] * $d_size[1] < \Piwigo\Config\Config::derivativesStripMetadataThreshold()) {// strip metadata for small images
    $image->strip();
}

$compression_quality = ImageStdParams::$quality;

// for big sizing never go beyond 75 quality
if (in_array($ctx->derivativeType, [IMG_4XLARGE, IMG_3XLARGE])) {
    $compression_quality = min(ImageStdParams::$quality, 75);
}

$image->write($ctx->derivativePath);
$image->destroy();
\Piwigo\Core\Filesystem::tryChmod($ctx->derivativePath, 0644);
$timing['save'] = time_step($step);

send_derivative($expires, $ctx);
$timing['send'] = time_step($step);

$timing['total'] = time_step($begin);

if ($logger->severity() >= Logger::DEBUG) {
    $logger->debug('image timing', array(
      'src_path' => basename($ctx->srcPath),
      'derivative_path' => basename($ctx->derivativePath),
      'o_size' => $o_size[0] . ' ' . $o_size[1] . ' ' . ($o_size[0] * $o_size[1]),
      'd_size' => $d_size[0] . ' ' . $d_size[1] . ' ' . ($d_size[0] * $d_size[1]),
      'mem_usage' => function_exists('memory_get_peak_usage') ? round(memory_get_peak_usage() / (1024 * 1024), 1) : '',
      'timing' => $timing,
      'quality' => $compression_quality,
      ));
}
