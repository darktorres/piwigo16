<?php

declare(strict_types=1);

use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;
use Piwigo\plugins\GDThumb\functions_GDThumb;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

global $template, $conf, $page;

functions::load_language('plugin.lang', GDTHUMB_PATH);
require __DIR__ . '/config_default.php';
$params = $conf->gdThumb;

if (isset($_GET['getMissingDerivative'])) {
    header('Content-Type: application/json');
    [$max_id, $image_count] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query('SELECT MAX(id) + 1, COUNT(*) FROM images;'));
    $start_id = intval($_POST['prev_page'] ?? 0);
    $max_urls = intval($_POST['max_urls'] ?? 500);

    if ($start_id <= 0) {
        $start_id = $max_id;
    }

    $uid = '&b=' . time();
    global $conf;
    $conf->question_mark_in_urls = true;
    $conf->php_extension_in_urls = true;
    $conf->derivative_url_style = 2; //script

    $qlimit = min(5000, ceil(max($image_count / 500, $max_urls)));

    $query_model = <<<SQL
        SELECT * FROM images
        WHERE id < start_id
        ORDER BY id DESC
        LIMIT {$qlimit};
        SQL;

    $urls = [];

    do {
        $result = $conf->sql_backend::pwg_query(str_replace('start_id', $start_id, $query_model));
        $is_last = $conf->sql_backend::pwg_db_num_rows($result) < $qlimit;

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $start_id = $row['id'];
            $src_image = new SrcImage($row);

            if ($src_image->is_mimetype() !== 0) {
                continue;
            }

            $derivative = new DerivativeImage(ImageStdParams::get_custom(9999, $params['height']), $src_image);

            $mtime = file_exists($derivative->get_path()) ? filemtime($derivative->get_path()) : false;

            if ($mtime === false) {
                $urls[] = $derivative->get_url() . $uid;
            }

            if (count($urls) >= $max_urls &&
                ! $is_last
            ) {
                break;
            }
        }

        if ($is_last) {
            $start_id = 0;
        }
    } while (count($urls) < $max_urls && $start_id);

    $ret = [];

    if ($start_id) {
        $ret['next_page'] = $start_id;
    }

    $ret['urls'] = $urls;
    echo json_encode($ret);
    exit();
}

// Delete cache
if (isset($_POST['cachedelete'])) {
    functions::check_pwg_token();
    functions_GDThumb::delete_gdthumb_cache($params['height']);
    functions::redirect('admin.php?page=plugin-GDThumb');
}

// Save configuration
if (isset($_POST['submit'])) {
    functions::check_pwg_token();
    $method = in_array($_POST['method'] ?? '', ['crop', 'resize']) ? $_POST['method'] : 'crop';
    $normalize = empty($_POST['normalize_title'] ?? null) ? 'off' : $_POST['normalize_title'];
    $valid_modes = ['top', 'top_static', 'bottom', 'bottom_static', 'overlay', 'overlay-ex', 'hide'];
    $thumb_mode_album = in_array($_POST['thumb_mode_album'] ?? '', $valid_modes) ? $_POST['thumb_mode_album'] : 'bottom';
    $thumb_mode_photo = in_array($_POST['thumb_mode_photo'] ?? '', $valid_modes) ? $_POST['thumb_mode_photo'] : 'bottom';

    $params = [
        'height' => (int) ($_POST['height'] ?? $conf->gdThumb['height']),
        'margin' => (int) ($_POST['margin'] ?? $conf->gdThumb['margin']),
        'nb_image_page' => (int) ($_POST['nb_image_page'] ?? $conf->gdThumb['nb_image_page']),
        'normalize_title' => $normalize,
        'method' => $method,
        'thumb_mode_album' => $thumb_mode_album,
        'thumb_mode_photo' => $thumb_mode_photo,
        'thumb_metamode' => in_array($_POST['thumb_metamode'] ?? '', ['merged', 'merged_desc', 'hide']) ? $_POST['thumb_metamode'] : 'merged',
        'no_wordwrap' => ! empty($_POST['no_wordwrap']),
    ];

    if ($params['height'] != $conf->gdThumb['height']) {
        functions_GDThumb::delete_gdthumb_cache($conf->gdThumb['height']);
    }

    if (empty($page['errors'])) {
        functions::conf_update_param('gdThumb', $params);
        $page['infos'][] = functions::l10n('Information data registered in database');
    }
}

if (! isset($params['normalize_title'])) {
    $params['normalize_title'] = 'off';
} elseif ($params['normalize_title'] == '1') {
    $params['normalize_title'] = 'on';
}

// Configuration du template
$template->assign(
    [
        'GDTHUMB_PATH' => 'plugins/' . GDTHUMB_ID,
        'GDTHUMB_VERSION' => GDTHUMB_VERSION,

        'HEIGHT' => $params['height'],
        'MARGIN' => $params['margin'],
        'NB_IMAGE_PAGE' => $params['nb_image_page'],
        'NORMALIZE_TITLE' => $params['normalize_title'],
        'METHOD' => $params['method'],
        'THUMB_MODE_ALBUM' => $params['thumb_mode_album'],
        'THUMB_MODE_PHOTO' => $params['thumb_mode_photo'],
        'THUMB_METAMODE' => $params['thumb_metamode'],
        'NO_WORDWRAP' => isset($params['no_wordwrap']) && $params['no_wordwrap'],

        'PWG_TOKEN' => functions::get_pwg_token(),
    ]
);

$template->set_filenames([
    'plugin_admin_content' => __DIR__ . '/template/admin.tpl',
]);
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
