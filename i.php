<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\pwg_image;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SizingParams;

define('PHPWG_ROOT_PATH', './');

// fast bootstrap - no db connection
include(PHPWG_ROOT_PATH . 'inc/config_default.php');
@include(PHPWG_ROOT_PATH . 'local/config/config.php');

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
defined('PWG_DERIVATIVE_DIR') or define('PWG_DERIVATIVE_DIR', $conf['data_location'] . 'i/');

@include(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.php');

$logger = new Katzgrau\KLogger\Logger(PHPWG_ROOT_PATH . $conf['data_location'] . $conf['log_dir'], $conf['log_level'], [
    // we use an hashed filename to prevent direct file access, and we salt with
    // the db_password instead of secret_key because the log must be usable in i.php
    // (secret_key is in the database)
    'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $conf['db_password']) . '.txt',
]);

// end fast bootstrap

$page = [];
$begin = $step = microtime(true);
$timing = [];
foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
    $timing[$k] = '';
}

try {
    functions_mysqli::pwg_db_connect(
        $conf['db_host'],
        $conf['db_user'],
        $conf['db_password'],
        $conf['db_base']
    );
} catch (Exception $e) {
    $logger->error($e->getMessage());
}
functions_mysqli::pwg_db_check_charset();

list($conf['derivatives']) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT value FROM ' . $prefixTable . 'config WHERE param=\'derivatives\''));
ImageStdParams::load_from_db();

functions::parse_request();
//var_export($page);

$params = $page['derivative_params'];

$src_mtime = @filemtime($page['src_path']);
if ($src_mtime === false) {
    functions::ierror('Source not found', 404);
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
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
      and strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $derivative_mtime) {// send the last mod time of the file back
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT', true, 304);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT', true, 304);
        exit;
    }
    functions::send_derivative($expires);
    exit;
}

$page['coi'] = null;
if (strpos($page['src_location'], '/pwg_representative/') === false
    && strpos($page['src_location'], 'themes/') === false
    && strpos($page['src_location'], 'plugins/') === false) {
    try {
        $query = '
SELECT *
  FROM ' . $prefixTable . 'images
  WHERE path=\'' . addslashes($page['src_location']) . '\'
;';

        if (($row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query)))) {
            if (isset($row['width'])) {
                $page['original_size'] = [$row['width'], $row['height']];
            }
            $page['coi'] = $row['coi'];

            if (! isset($row['rotation'])) {
                $page['rotation_angle'] = pwg_image::get_rotation_angle($page['src_path']);

                functions_mysqli::single_update(
                    $prefixTable . 'images',
                    [
                        'rotation' => pwg_image::get_rotation_code_from_angle($page['rotation_angle']),
                    ],
                    [
                        'id' => $row['id'],
                    ]
                );
            } else {
                $page['rotation_angle'] = pwg_image::get_rotation_angle_from_code($row['rotation']);
            }
        }
        if (! $row) {
            functions::ierror('Db file path not found', 404);
        }
    } catch (Exception $e) {
        $logger->error($e->getMessage());
    }
} else {
    $page['rotation_angle'] = 0;
}
functions_mysqli::pwg_db_close();

if (! functions::try_switch_source($params, $src_mtime) && $params->type == derivative_std_params::IMG_CUSTOM) {
    $sharpen = 0;
    foreach (ImageStdParams::get_defined_type_map() as $std_params) {
        $sharpen += $std_params->sharpen;
    }
    $params->sharpen = round($sharpen / count(ImageStdParams::get_defined_type_map()));
}

if (! functions::mkgetdir(dirname($page['derivative_path']))) {
    functions::ierror('dir create error', 500);
}

ignore_user_abort(true);
@set_time_limit(0);

$image = new pwg_image($page['src_path']);
$timing['load'] = functions::time_step($step);

$changes = 0;

// rotate
if ($page['rotation_angle'] != 0) {
    $image->rotate($page['rotation_angle']);
    $changes++;
    $timing['rotate'] = functions::time_step($step);
}

// Crop & scale
$o_size = $d_size = [$image->get_width(), $image->get_height()];
$params->sizing->compute($o_size, $page['coi'], $crop_rect, $scaled_size);
if ($crop_rect) {
    $changes++;
    $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
    $timing['crop'] = functions::time_step($step);
}

if ($scaled_size) {
    $changes++;
    $image->resize($scaled_size[0], $scaled_size[1]);
    $d_size = $scaled_size;
    $timing['scale'] = functions::time_step($step);
}

if ($params->sharpen) {
    $changes += $image->sharpen($params->sharpen);
    $timing['sharpen'] = functions::time_step($step);
}

if ($params->will_watermark($d_size)) {
    $wm = ImageStdParams::get_watermark();
    $wm_image = new pwg_image(PHPWG_ROOT_PATH . $wm->file);
    $wm_size = [$wm_image->get_width(), $wm_image->get_height()];
    if ($d_size[0] < $wm_size[0] or $d_size[1] < $wm_size[1]) {
        $wm_scaling_params = SizingParams::classic($d_size[0], $d_size[1]);
        $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
        $wm_size = $wm_scaled_size;
        $wm_image->resize($wm_scaled_size[0], $wm_scaled_size[1]);
    }
    $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
    $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));
    if ($image->compose($wm_image, $x, $y, $wm->opacity)) {
        $changes++;
        if ($wm->xrepeat || $wm->yrepeat) {
            $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
            $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));

            for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                    if (! $i && ! $j) {
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
    $timing['watermark'] = functions::time_step($step);
}

// no change required - redirect to source
if (! $changes) {
    header('X-i: No change');
    functions::ierror($page['src_url'], 301);
}

if ($conf['derivatives_strip_metadata_threshold'] > $d_size[0] * $d_size[1]) {// strip metadata for small images
    $image->strip();
}

$image->set_compression_quality(ImageStdParams::$quality);
$image->write($page['derivative_path']);
$image->destroy();
@chmod($page['derivative_path'], 0644);
$timing['save'] = functions::time_step($step);

functions::send_derivative($expires);
$timing['send'] = functions::time_step($step);

$timing['total'] = functions::time_step($begin);

if ($conf['log_level'] >= Psr\Log\LogLevel::DEBUG) {
    $logger->debug('', [
        'src_path' => basename($page['src_path']),
        'derivative_path' => basename($page['derivative_path']),
        'o_size' => $o_size[0] . ' ' . $o_size[1] . ' ' . ($o_size[0] * $o_size[1]),
        'd_size' => $d_size[0] . ' ' . $d_size[1] . ' ' . ($d_size[0] * $d_size[1]),
        'mem_usage' => function_exists('memory_get_peak_usage') ? round(memory_get_peak_usage() / (1024 * 1024), 1) : '',
        'timing' => $timing,
    ]);
}
