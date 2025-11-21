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
    [$max_id, $image_count] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query('SELECT MAX(id) + 1, COUNT(*) FROM images;'));
    $start_id = intval($_POST['prev_page']);
    $max_urls = intval($_POST['max_urls']);

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

            if (($params['method'] == 'slide') ||
                ($params['method'] == 'square')
            ) {
                $derivative = new DerivativeImage(ImageStdParams::get_custom($params['height'], 9999), $src_image);
            } else {
                $derivative = new DerivativeImage(ImageStdParams::get_custom(9999, $params['height']), $src_image);
            }

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
    functions_GDThumb::delete_gdthumb_cache($params['height'] * 2 + $params['margin']);
    functions::redirect('admin.php?page=plugin-GDThumb');
}

// Save configuration
if (isset($_POST['submit'])) {
    $method = empty($_POST['method']) ? 'resize' : $_POST['method'];
    $normalize = empty($_POST['normalize_title']) ? 'off' : $_POST['normalize_title'];
    $big_thumb = ! empty($_POST['big_thumb']);
    $big_thumb_noinpw = ! empty($_POST['big_thumb_noinpw']);
    $thumb_animate = ! empty($_POST['thumb_animate']);
    $thumb_mode_album = $_POST['thumb_mode_album'];
    $thumb_mode_photo = $_POST['thumb_mode_photo'];
    if ($method == 'slide') {
        if ($big_thumb) {
            $big_thumb = false;
            $page['warnings'][] = functions::l10n('Big thumb cannot be used in Slide mode. Disabled');
        }

        if ($thumb_animate) {
            $thumb_animate = false;
            $page['warnings'][] = functions::l10n('Thumb animation cannot be used in Slide mode. Disabled');
        }

        if ($thumb_mode_album == 'overlay-ex' ||
            $thumb_mode_album == 'overlay' ||
            $thumb_mode_album == 'top' ||
            $thumb_mode_album == 'bottom'
        ) {
            $thumb_mode_album = 'bottom_static';
            $page['warnings'][] = functions::l10n('This Thumb mode cannot be used in Slide mode. Changed to default');
        }

        if ($thumb_mode_photo == 'overlay-ex' ||
            $thumb_mode_photo == 'overlay' ||
            $thumb_mode_photo == 'top' ||
            $thumb_mode_photo == 'bottom'
        ) {
            $thumb_mode_photo = 'bottom_static';
            $page['warnings'][] = functions::l10n('This Thumb mode cannot be used in Slide mode. Changed to default');
        }
    }

    if ($big_thumb_noinpw &&
        ! $big_thumb
    ) {
        $big_thumb_noinpw = false;
    }

    $params = [
        'height' => $_POST['height'],
        'margin' => $_POST['margin'],
        'nb_image_page' => $_POST['nb_image_page'],
        'big_thumb' => $big_thumb,
        'big_thumb_noinpw' => $big_thumb_noinpw,
        'cache_big_thumb' => ! empty($_POST['cache_big_thumb']),
        'normalize_title' => $normalize,
        'method' => $method,
        'thumb_mode_album' => $thumb_mode_album,
        'thumb_mode_photo' => $thumb_mode_photo,
        'thumb_metamode' => $_POST['thumb_metamode'],
        'no_wordwrap' => ! empty($_POST['no_wordwrap']),
        'thumb_animate' => $thumb_animate,
    ];
    if (! is_numeric($params['height'])) {
        $page['errors'][] = functions::l10n('Thumbnails max height must be an integer');
    }

    if (! is_numeric($params['margin'])) {
        $page['errors'][] = functions::l10n('Margin between thumbnails must be an integer');
    }

    if (! is_numeric($params['nb_image_page'])) {
        $page['errors'][] = functions::l10n('Number of photos per page must be an integer');
    }

    if ($params['height'] != $conf->gdThumb['height']) {
        // Height changed - clear all derivatives using old height
        functions_GDThumb::delete_gdthumb_cache($conf->gdThumb['height']);
        // Also clear big thumbnail cache (2x height + margin)
        functions_GDThumb::delete_gdthumb_cache($conf->gdThumb['height'] * 2 + $conf->gdThumb['margin']);
    } elseif ($params['margin'] != $conf->gdThumb['margin']) {
        // Margin changed - clear big thumbnail cache
        functions_GDThumb::delete_gdthumb_cache($conf->gdThumb['height'] * 2 + $conf->gdThumb['margin']);
    }

    if (empty($page['errors'])) {
        // Store old height for event notification
        $old_height = $conf->gdThumb['height'];
        $new_height = $params['height'];

        functions::conf_update_param('gdThumb', $params);
        $page['infos'][] = functions::l10n('Information data registered in database');

        // Notify other plugins when GDThumb config changes
        if ($old_height !== $new_height) {
            functions_plugins::trigger_notify('gdthumb_config_changed', [
                'old_height' => $old_height,
                'new_height' => $new_height,
            ]);
        }
    }
}

// Try to find GreyDragon Theme and use Theme's styles for admin area
$css_file = str_replace('/./', '/', dirname(__FILE__, 3) . '/' . GDTHEME_PATH . 'admin/css/styles.css');

$custom_css = file_exists($css_file) ? 'yes' : 'no';

if (! isset($params['normalize_title'])) {
    $params['normalize_title'] = 'off';
} elseif ($params['normalize_title'] == '1') {
    $params['normalize_title'] = 'on';
}

// Configuration du template
$template->assign(
    [
        'GDTHUMB_PATH' => 'plugins/' . GDTHUMB_ID,
        'GDTHEME_PATH' => GDTHEME_PATH,
        'GDTHUMB_VERSION' => GDTHUMB_VERSION,
        'PHPWG_ROOT_PATH' => './',

        'HEIGHT' => $params['height'],
        'MARGIN' => $params['margin'],
        'NB_IMAGE_PAGE' => $params['nb_image_page'],
        'BIG_THUMB' => $params['big_thumb'],
        'BIG_THUMB_NOINPW' => isset($params['big_thumb_noinpw']) && $params['big_thumb_noinpw'],
        'CACHE_BIG_THUMB' => $params['cache_big_thumb'],
        'NORMALIZE_TITLE' => $params['normalize_title'],
        'METHOD' => $params['method'],
        'THUMB_MODE_ALBUM' => $params['thumb_mode_album'],
        'THUMB_MODE_PHOTO' => $params['thumb_mode_photo'],
        'THUMB_METAMODE' => $params['thumb_metamode'],
        'NO_WORDWRAP' => isset($params['no_wordwrap']) && $params['no_wordwrap'],
        'THUMB_ANIMATE' => isset($params['thumb_animate']) && $params['thumb_animate'],

        'PWG_TOKEN' => functions::get_pwg_token(),
        'CUSTOM_CSS' => $custom_css,
    ]
);

$template->set_filenames([
    'plugin_admin_content' => __DIR__ . '/template/admin.tpl',
]);
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
