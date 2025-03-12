<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\SrcImage;

define('PHPWG_ROOT_PATH', './');
session_cache_limiter('public');
require_once PHPWG_ROOT_PATH . 'inc/common.php';

// Check Access and exit when user status is not ok
functions_user::check_status(ACCESS_GUEST);

if ($conf['enable_formats'] and
    isset($_GET['format'])
) {
    functions::check_input_parameter('format', $_GET, false, PATTERN_ID);

    $query = <<<SQL
        SELECT *
        FROM image_format
        WHERE format_id = {$_GET['format']};
        SQL;
    $formats = functions_mysqli::query2array($query);

    if (count($formats) == 0) {
        functions::do_error(400, 'Invalid request - format');
    }

    $format = $formats[0];

    $_GET['id'] = $format['image_id'];
    $_GET['part'] = 'f'; // "f" for "format"
}

if (! isset($_GET['id']) or
    ! is_numeric($_GET['id']) or
    ! isset($_GET['part']) or
    ! in_array($_GET['part'], ['e', 'r', 'f'])
) {
    functions::do_error(400, 'Invalid request - id/part');
}

$query = <<<SQL
    SELECT *
    FROM images
    WHERE id = {$_GET['id']};
    SQL;

$element_info = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

if (empty($element_info)) {
    functions::do_error(404, 'Requested id not found');
}

// special download action for admins
$is_admin_download = false;

if (functions_user::is_admin() and
    isset($_GET['pwg_token']) and
    functions::get_pwg_token() == $_GET['pwg_token']
) {
    $is_admin_download = true;
    $user['enabled_high'] = true;
}

$src_image = new SrcImage($element_info);

// $filter['visible_categories'] and $filter['visible_images']
// are not used because it's not necessary (filter != restriction)
$sql_condition = functions_user::get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'forbidden_images' => 'image_id',
    ],
    'AND'
);

$query = <<<SQL
    SELECT id
    FROM categories
    INNER JOIN image_category ON category_id = id
    WHERE image_id = {$_GET['id']}
    {$sql_condition}
    LIMIT 1;
    SQL;

if (! $is_admin_download and
    functions_mysqli::pwg_db_num_rows(functions_mysqli::pwg_query($query)) < 1
) {
    functions::do_error(401, 'Access denied');
}

$file = '';

switch ($_GET['part']) {
    case 'e':
        if ($src_image->is_original() and
            ! $user['enabled_high']
        ) { // we have a photo and the user has no access to HD
            $deriv = new DerivativeImage(derivative_std_params::IMG_XXLARGE, $src_image);

            if (! $deriv->same_as_source()) {
                functions::do_error(401, 'Access denied e');
            }
        }

        $file = functions::get_element_path($element_info);
        break;

    case 'r':
        $file = functions::original_to_representative(functions::get_element_path($element_info), $element_info['representative_ext']);
        break;

    case 'f':
        $file = functions::original_to_format(functions::get_element_path($element_info), $format['ext']);
        $element_info['file'] = functions::get_filename_wo_extension($element_info['file']) . '.' . $format['ext'];
        break;
}

if (empty($file)) {
    functions::do_error(404, 'Requested file not found');
}

if ($_GET['part'] == 'e') {
    functions::pwg_log($_GET['id'], 'high');
} elseif ($_GET['part'] == 'r') {
    functions::pwg_log($_GET['id'], 'other');
} elseif ($_GET['part'] == 'f') {
    functions::pwg_log($_GET['id'], 'high', $format['format_id']);
}

functions_plugins::trigger_notify('loc_action_before_http_headers');

$http_headers = [];

$ctype = null;

if (! functions_url::url_is_remote($file)) {
    if (! is_readable($file)) {
        functions::do_error(404, "Requested file not found - {$file}");
    }

    $http_headers[] = 'Content-Length: ' . filesize($file);

    if (function_exists('mime_content_type')) {
        $ctype = mime_content_type($file);
    }

    $gmt_mtime = gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT';
    $http_headers[] = 'Last-Modified: ' . $gmt_mtime;

    // following lines would indicate how the client should handle the cache
    /* $max_age=300;
    $http_headers[] = 'Expires: '.gmdate('D, d M Y H:i:s', time()+$max_age).' GMT';
    // HTTP/1.1 only
    $http_headers[] = 'Cache-Control: private, must-revalidate, max-age='.$max_age;*/

    if ($_GET['part'] != 'f' and
        isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
    ) {
        functions_html::set_status_header(304);

        foreach ($http_headers as $header) {
            header($header);
        }

        exit();
    }
}

if (! isset($ctype)) { // give it a guess
    $ctype = functions::guess_mime_type(functions::get_extension($file));
}

$http_headers[] = 'Content-Type: ' . $ctype;

if (isset($_GET['download'])) {
    $http_headers[] = 'Content-Disposition: attachment; filename="' . htmlspecialchars_decode($element_info['file']) . '";';
    $http_headers[] = 'Content-Transfer-Encoding: binary';
} else {
    $http_headers[] = 'Content-Disposition: inline; filename="' . basename($file) . '";';
}

foreach ($http_headers as $header) {
    header($header);
}

// Looking at the safe_mode configuration for execution time
if (ini_get('safe_mode') == 0) {
    set_time_limit(0);
}

// Without clean and flush there may be some image download problems, or image can be corrupted after download
if (ob_get_length() !== false) {
    ob_flush();
}

flush();

readfile($file);
