<?php

declare(strict_types=1);

/*
Plugin Name: gdThumb
Version: 1.0.26
Description: Apply Masonry style to album or image thumbs
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=771
Author: Serge Dosyukov
Author URI: http://blog.dragonsoft.us
Has Settings: true
*/
// Original work by P@t - GTHumb+

use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\ImageStdParams;

global $conf;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

if (functions::mobile_theme()) {
    return;
}

// +-----------------------------------------------------------------------+
// | Plugin constants                                                      |
// +-----------------------------------------------------------------------+
const GDTHUMB_VERSION = '1.0.26';
define('GDTHUMB_ID', basename(__DIR__));
const GDTHUMB_PATH = PHPWG_PLUGINS_PATH . GDTHUMB_ID . '/';

if (! defined('GDTHEME_PATH')) {
    define('GDTHEME_PATH', PHPWG_THEMES_PATH . 'greydragon/');
}

if (! isset($conf->gdThumb)) {
    require __DIR__ . '/config_default.php';
    functions::conf_update_param('gdThumb', $config_default);
    functions::load_conf_from_db();
}

// RV Thumbnails Scroller
if (isset($_GET['rvts'])) {
    $conf->gdThumb['big_thumb'] = false;
    functions_plugins::add_event_handler('loc_end_index_thumbnails', GDThumb_process_thumb(...), 50);
}

functions_plugins::add_event_handler('init', GDThumb_init(...));
functions_plugins::add_event_handler('loc_begin_index', GDThumb_index(...), 60);
functions_plugins::add_event_handler('loc_end_index_category_thumbnails', GDThumb_process_category(...), 50);
functions_plugins::add_event_handler('get_admin_plugin_menu_links', GDThumb_admin_menu(...));
functions_plugins::add_event_handler('loc_end_index', GDThumb_remove_thumb_size(...));

function GDThumb_init(): void
{
    global $conf, $user, $page, $stripped;

    $confTemp = $conf->gdThumb;
    $user['nb_image_page'] = $confTemp['nb_image_page'];
    $page['nb_image_page'] = $confTemp['nb_image_page'];
    $stripped['maxThumb'] = $confTemp['nb_image_page'];
}

function GDThumb_index(): void
{
    global $template;

    $template->smarty->registerPlugin('function', 'media_type', GDThumb_media_type(...));
    $template->set_prefilter('index', GDThumb_prefilter(...));

    functions_plugins::add_event_handler('loc_end_index_thumbnails', GDThumb_process_thumb(...), 50);
}

const GDTHUMB_MEDIA_TYPES = [
    'video' => ['webm', 'webmv', 'ogv', 'm4v', 'flv', 'mp4'],
    'music' => ['mp3', 'ogg', 'oga', 'm4a', 'webma', 'fla', 'wav'],
    'pdf' => ['pdf'],
    'doc' => ['doc', 'docx', 'odt'],
    'xls' => ['xls', 'xlsx', 'ods'],
    'ppt' => ['ppt', 'pptx', 'odp'],
];

function GDThumb_media_type(
    array $params,
    \Smarty\Template $smarty
): string {
    if (empty($params['file'])) {
        return 'image';
    }

    $ext = strtolower(pathinfo($params['file'], PATHINFO_EXTENSION));

    foreach (GDTHUMB_MEDIA_TYPES as $type => $extensions) {
        if (in_array($ext, $extensions, true)) {
            return $type;
        }
    }

    return 'image';
}

function GDThumb_process_thumb(
    array $tpl_vars
): array {
    global $template, $conf;
    $confTemp = $conf->gdThumb;
    $confTemp['GDTHUMB_ROOT'] = 'plugins/' . GDTHUMB_ID;
    $confTemp['big_thumb_noinpw'] = (isset($confTemp['big_thumb_noinpw']) && ($confTemp['big_thumb_noinpw'])) ? 1 : 0;

    if ($confTemp['normalize_title'] == '1') {
        $confTemp['normalize_title'] = 'on';
    }

    $template->set_filename('index_thumbnails', __DIR__ . '/template/gdthumb_thumb.tpl');
    $template->assign('GDThumb', $confTemp);
    $template->assign('GDThumb_derivative_params', GDThumb_get_derivative_params($confTemp));

    if ($confTemp['big_thumb'] && ! empty($tpl_vars[0])) {
        $template->assign('GDThumb_big', new DerivativeImage(
            GDThumb_get_derivative_params($confTemp, true),
            $tpl_vars[0]['src_image']
        ));
    }

    return $tpl_vars;
}

function GDThumb_process_category(
    array $tpl_vars
): array {
    global $template, $conf;
    $confTemp = $conf->gdThumb;
    $confTemp['GDTHUMB_ROOT'] = 'plugins/' . GDTHUMB_ID;
    $confTemp['big_thumb_noinpw'] = (isset($confTemp['big_thumb_noinpw']) && ($confTemp['big_thumb_noinpw'])) ? 1 : 0;

    $template->set_filename('index_category_thumbnails', __DIR__ . '/template/gdthumb_cat.tpl');
    $template->assign('GDThumb', $confTemp);
    $template->assign('GDThumb_derivative_params', GDThumb_get_derivative_params($confTemp));

    if ($confTemp['big_thumb'] && ! empty($tpl_vars[0])) {
        $id = $tpl_vars[0]['representative_picture_id'];

        if (($id) && ($rep = $tpl_vars[0]['representative'])) {
            $template->assign('GDThumb_big', new DerivativeImage(
                GDThumb_get_derivative_params($confTemp, true),
                $rep['src_image']
            ));
        }
    }

    return $tpl_vars;
}

function GDThumb_get_derivative_params(
    array $confTemp,
    bool $big = false
): \Piwigo\inc\DerivativeParams {
    $size = $big ? 2 * $confTemp['height'] + $confTemp['margin'] : $confTemp['height'];
    $is_vertical = ($confTemp['method'] == 'slide') || ($confTemp['method'] == 'square');

    return $is_vertical
        ? ImageStdParams::get_custom($size, 9999)
        : ImageStdParams::get_custom(9999, $size);
}

function GDThumb_prefilter(
    string $content
): string|null {
    $pattern = '#\<div(.*?id\="thumbnails"[^>]*)\>\{\$THUMBNAILS\}\</div\>#';
    $replacement = '<ul$1>{$THUMBNAILS}</ul>';

    return preg_replace($pattern, $replacement, $content);
}

function GDThumb_admin_menu(
    array $menu
): array {
    $menu[] = [
        'NAME' => 'gdThumb',
        'URL' => functions_url::get_root_url() . 'admin.php?page=plugin-' . basename(__DIR__),
    ];
    return $menu;
}

function GDThumb_remove_thumb_size(): void
{
    global $template;
    $template->clear_assign('image_derivatives');
}
