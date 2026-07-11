<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;

// Bootstrap globals, set by include/common.inc.php below.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $user
 */
global $conf, $user;

define('PHPWG_ROOT_PATH', './');
session_cache_limiter('public');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Check Access and exit when user status is not ok
check_status(AccessLevel::Guest);

function guess_mime_type(string $ext): string
{
    $ctype = match (strtolower($ext)) {
        'jpe', 'jpeg', 'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'tiff', 'tif' => 'image/tiff',
        'txt' => 'text/plain',
        'html', 'htm' => 'text/html',
        'xml' => 'text/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'ogg' => 'application/ogg',
        default => 'application/octet-stream',
    };
    return $ctype;
}

function do_error(int $code, string $str): never
{
    set_status_header($code);
    echo $str;
    exit();
}

if ((bool) $conf['enable_formats'] and isset($_GET['format'])) {
    check_input_parameter('format', $_GET, false, ValidationPattern::ID);

    if (! is_numeric($_GET['format'])) {
        do_error(400, 'Invalid request - format');
    }
    $format_id = (int) $_GET['format'];

    $query = '
SELECT
    *
  FROM ' . Tables::imageFormat() . '
  WHERE format_id = ' . $format_id . '
;';
    $formats = query2array($query);

    if (count($formats) == 0) {
        do_error(400, 'Invalid request - format');
    }

    $format = $formats[0];

    $_GET['id'] = $format['image_id'];
    $_GET['part'] = 'f'; // "f" for "format"
}

if (! isset($_GET['id'])
    or ! is_numeric($_GET['id'])
    or ! isset($_GET['part'])
    or ! in_array($_GET['part'], ['e', 'r', 'f'])) {
    do_error(400, 'Invalid request - id/part');
}
$_GET['id'] = (int) $_GET['id'];

$query = '
SELECT * FROM ' . Tables::images() . '
  WHERE id=' . $_GET['id'] . '
;';

$element_info = pwg_db_fetch_assoc(pwg_query($query));
if (empty($element_info)) {
    do_error(404, 'Requested id not found');
}

// special download action for admins
$is_admin_download = false;
if (is_admin() and isset($_GET['pwg_token']) and get_pwg_token() == $_GET['pwg_token']) {
    $is_admin_download = true;
    $user['enabled_high'] = true;
}

$src_image = new SrcImage($element_info);

// $filter['visible_categories'] and $filter['visible_images']
// are not used because it's not necessary (filter <> restriction)
$query = '
SELECT id
  FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::imageCategory() . ' ON category_id = id
  WHERE image_id = ' . $_GET['id'] . '
' . get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'forbidden_images' => 'image_id',
    ],
    '    AND'
) . '
  LIMIT 1
;';
if (! $is_admin_download and pwg_db_num_rows(pwg_query($query)) < 1) {
    do_error(401, 'Access denied');
}

include_once PHPWG_ROOT_PATH . 'include/functions_picture.inc.php';

// $format is only set when the enable_formats block above ran; part=f is
// reachable directly by request even when it never ran. PHPStan's
// control-flow merge loses the original query2array() row-shape narrowing
// of $format across the switch below (and the later part=='f' check), so
// resolve it once into a typed local reused at every read site.
/** @var array<string, mixed>|null $format_row */
$format_row = (isset($format) && is_array($format)) ? $format : null;

$file = '';
switch ($_GET['part']) {
    case 'e':
        if ($src_image->is_original() and ! (bool) $user['enabled_high']) {// we have a photo and the user has no access to HD
            $deriv = new DerivativeImage(IMG_XXLARGE, $src_image);
            if (! $deriv->same_as_source()) {
                do_error(401, 'Access denied e');
            }
        }
        $file = get_element_path($element_info);
        break;
    case 'r':
        $representative_ext = $element_info['representative_ext'];
        // images.representative_ext is nullable in the schema (only set
        // when a custom representative image exists) — a genuine missing
        // value means there is no representative file to serve.
        if (empty($representative_ext)) {
            do_error(404, 'Requested file not found');
        }
        $file = original_to_representative(get_element_path($element_info), $representative_ext);
        break;
    case 'f':
        if ($format_row === null) {
            do_error(400, 'Invalid request - format');
        }
        $format_ext = $format_row['ext'];
        // image_format.ext is `varchar(255) NOT NULL` in the schema — a
        // genuine DB row for this format always carries a string here.
        assert(is_string($format_ext));
        $file = original_to_format(get_element_path($element_info), $format_ext);
        $original_file = $element_info['file'];
        // images.file is `varchar(255) NOT NULL` in the schema — a genuine
        // DB row for this element always carries a string here.
        assert(is_string($original_file));
        $element_info['file'] = get_filename_wo_extension($original_file) . '.' . $format_ext;
        break;
}

if (empty($file)) {
    do_error(404, 'Requested file not found');
}

if ($_GET['part'] == 'e') {
    pwg_log($_GET['id'], 'high');
} elseif ($_GET['part'] == 'r') {
    pwg_log($_GET['id'], 'other');
} elseif ($_GET['part'] == 'f') {
    if ($format_row === null) {
        do_error(400, 'Invalid request - format');
    }
    $format_id_val = $format_row['format_id'] ?? null;
    $format_id_val = is_string($format_id_val) ? $format_id_val : null;
    pwg_log($_GET['id'], 'high', $format_id_val);
}

trigger_notify('loc_action_before_http_headers');

$http_headers = [];

$ctype = null;
if (! url_is_remote($file)) {
    if (! @is_readable($file)) {
        do_error(404, "Requested file not found - {$file}");
    }
    $http_headers[] = 'Content-Length: ' . @filesize($file);
    if (function_exists('mime_content_type')) {
        $ctype = mime_content_type($file);
    }

    $file_mtime = filemtime($file);
    // is_readable() was just checked above, so the file exists
    assert($file_mtime !== false);
    $gmt_mtime = gmdate('D, d M Y H:i:s', $file_mtime) . ' GMT';
    $http_headers[] = 'Last-Modified: ' . $gmt_mtime;

    // following lines would indicate how the client should handle the cache
    /* $max_age=300;
    $http_headers[] = 'Expires: '.gmdate('D, d M Y H:i:s', time()+$max_age).' GMT';
    // HTTP/1.1 only
    $http_headers[] = 'Cache-Control: private, must-revalidate, max-age='.$max_age;*/

    if ($_GET['part'] != 'f' and isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        set_status_header(304);
        foreach ($http_headers as $header) {
            header($header);
        }
        exit();
    }
}

if (! isset($ctype)) { // give it a guess
    $ctype = guess_mime_type(get_extension($file));
}

$http_headers[] = 'Content-Type: ' . $ctype;

if (isset($_GET['download'])) {
    $http_headers[] = 'Content-Disposition: attachment; filename="' . htmlspecialchars_decode((string) $element_info['file']) . '";';
    $http_headers[] = 'Content-Transfer-Encoding: binary';
} else {
    $http_headers[] = 'Content-Disposition: inline; filename="'
              . basename($file) . '";';
}

foreach ($http_headers as $header) {
    header($header);
}

// Looking at the safe_mode configuration for execution time
if (ini_get('safe_mode') == 0) {
    @set_time_limit(0);
}

// Without clean and flush there may be some image download problems, or image can be corrupted after download
if (ob_get_length() !== false) {
    ob_flush();
}
flush();

@readfile($file);
