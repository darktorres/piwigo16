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

use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
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

require __DIR__ . '/config_default.php';
if (! isset($conf->gdThumb)) {
    functions::conf_update_param('gdThumb', $config_default);
    functions::load_conf_from_db();
} elseif (array_diff_key($config_default, (array) $conf->gdThumb)) {
    $conf->gdThumb = array_merge($config_default, (array) $conf->gdThumb);
    functions::conf_update_param('gdThumb', $conf->gdThumb);
}

functions_plugins::add_event_handler('init', GDThumb_init(...));
functions_plugins::add_event_handler('loc_begin_index', GDThumb_index(...), 60);
functions_plugins::add_event_handler('loc_end_index_category_thumbnails', GDThumb_process_category(...), 50);
functions_plugins::add_event_handler('get_admin_plugin_menu_links', GDThumb_admin_menu(...));

function GDThumb_init(): void
{
    global $conf, $user, $page, $stripped;

    $confTemp = $conf->gdThumb;
    $stripped['maxThumb'] = $user['nb_image_page'];
}

function GDThumb_index(): void
{
    global $template;

    $template->set_prefilter('index', GDThumb_prefilter(...));

    functions_plugins::add_event_handler('loc_end_index_thumbnails', GDThumb_process_thumb(...), 50);
}

function GDThumb_process_thumb(
    array $tpl_vars
): array {
    global $template, $conf;
    $confTemp = $conf->gdThumb;
    $confTemp['GDTHUMB_ROOT'] = 'plugins/' . GDTHUMB_ID;
    $confTemp['height'] = GDThumb_effective_width();

    if ($confTemp['normalize_title'] == '1') {
        $confTemp['normalize_title'] = 'on';
    }

    $template->set_filename('index_thumbnails', __DIR__ . '/template/gdthumb_thumb.tpl');
    $template->assign('GDThumb', $confTemp);
    require_once PHPWG_ROOT_PATH . 'inc/vite_helper.php';
    \Piwigo\Vite\vite_assign_modules($template, [
        'gdthumb' => 'plugins/GDThumb/js/gdthumb',
    ]);
    $template->assign('GDThumb_derivative_params', GDThumb_get_derivative_params());

    return $tpl_vars;
}

function GDThumb_process_category(
    array $tpl_vars
): array {
    global $template, $conf;
    $confTemp = $conf->gdThumb;
    $confTemp['GDTHUMB_ROOT'] = 'plugins/' . GDTHUMB_ID;
    $confTemp['height'] = GDThumb_effective_width();

    $template->set_filename('index_category_thumbnails', __DIR__ . '/template/gdthumb_cat.tpl');
    $template->assign('GDThumb', $confTemp);
    require_once PHPWG_ROOT_PATH . 'inc/vite_helper.php';
    \Piwigo\Vite\vite_assign_modules($template, [
        'gdthumb' => 'plugins/GDThumb/js/gdthumb',
    ]);
    $template->assign('GDThumb_derivative_params', GDThumb_get_derivative_params());

    return $tpl_vars;
}

function GDThumb_effective_width(): int
{
    $type = functions_session::pwg_get_session_var('index_deriv', derivative_std_params::IMG_THUMB);
    $size = ImageStdParams::get_by_type($type)->sizing->ideal_size;
    return max($size[0], $size[1]);
}

function GDThumb_get_derivative_params(): \Piwigo\inc\DerivativeParams
{
    return ImageStdParams::get_fit_width(GDThumb_effective_width());
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
