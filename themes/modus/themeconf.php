<?php

declare(strict_types=1);

/*
Theme Name: modus
Version: 14.5.0.1
Description: Responsive, horizontal menu, retina aware, no lost space.
Theme URI: https://piwigo.org/ext/extension_view.php?eid=728
Author: rvelices
Author URI: http://www.modusoptimus.com
*/

use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\functions_cookie;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\ImageStdParams;
use Piwigo\themes\modus\functions_modus;

$themeconf = [
    'name' => 'modus',
    'parent' => 'default',
    'colorscheme' => 'dark',
];

define('MODUS_STR_RECENT', "\xe2\x9c\xbd"); //HEAVY TEARDROP-SPOKED ASTERISK
define('MODUS_STR_RECENT_CHILD', "\xe2\x9c\xbb"); //TEARDROP-SPOKED ASTERISK

if (! empty($_GET['skin']) &&
    ! preg_match('/[^a-zA-Z0-9_-]/', $_GET['skin'])
) {
    $conf->modus_theme['skin'] = $_GET['skin'];
}

// we're mainly interested in an override of the colorscheme
require __DIR__ . '/skins/' . $conf->modus_theme['skin'] . '.php';

$this->assign(
    [
        'MODUS_CSS_VERSION' => crc32(implode(',', [
            'a' . $conf->modus_theme['skin'],
            $conf->modus_theme['album_thumb_size'],
            ImageStdParams::get_by_type(derivative_std_params::IMG_SQUARE)->max_width(),
            $conf->index_created_date_icon,
            $conf->index_posted_date_icon,
        ])),
        'MODUS_DISPLAY_PAGE_BANNER' => $conf->modus_theme['display_page_banner'],
    ]
);

if (file_exists(__DIR__ . '/skins/' . $conf->modus_theme['skin'] . '.css')) {
    $this->assign('MODUS_CSS_SKIN', $conf->modus_theme['skin']);
}

if (! $conf->compiled_template_cache_language) {
    functions::load_language('theme.lang', __DIR__ . '/');
    functions::load_language('lang', './local/', [
        'no_fallback' => true,
        'local' => true,
    ]);
}

if (isset($_COOKIE['caps'])) {
    setcookie('caps', '', [
        'expires' => 0,
        'path' => functions_cookie::cookie_path(),
    ]);
    functions_session::pwg_set_session_var('caps', explode('x', $_COOKIE['caps']));
    /*file_put_contents('./'.$conf->data_location.'tmp/modus.log', implode("\t", array(
        date("Y-m-d H:i:s"), $_COOKIE['caps'], $_SERVER['HTTP_USER_AGENT']
        ))."\n", FILE_APPEND);*/
}

if (functions::get_device() == 'mobile') {
    $conf->tag_letters_column_number = 1;
} elseif (functions::get_device() == 'tablet') {
    $conf->tag_letters_column_number = min($conf->tag_letters_column_number, 3);
}

$this->smarty->registerFilter('pre', functions_modus::modus_smarty_prefilter_wrap(...));

// Add prefilter to remove fontello loaded by piwigo 14 search,
// this avoids conflicts of loading 2 fontello
functions_plugins::add_event_handler('loc_begin_index', functions_modus::modus_loc_begin_index(...), 60);
functions_plugins::add_event_handler('combinable_preparse', functions_modus::modus_combinable_preparse(...));

$this->smarty->registerPlugin('function', 'cssResolution', functions_modus::modus_css_resolution(...));
$this->smarty->registerPlugin('function', 'modus_thumbs', functions_modus::modus_thumbs(...));

functions_plugins::add_event_handler('loc_end_index', functions_modus::modus_on_end_index(...));
functions_plugins::add_event_handler('get_index_derivative_params', functions_modus::modus_get_index_photo_derivative_params(...), EVENT_HANDLER_PRIORITY_NEUTRAL + 1);
functions_plugins::add_event_handler('loc_end_index_category_thumbnails', functions_modus::modus_index_category_thumbnails(...));
functions_plugins::add_event_handler('loc_begin_picture', functions_modus::modus_loc_begin_picture(...));
functions_plugins::add_event_handler('render_element_content', functions_modus::modus_picture_content(...), EVENT_HANDLER_PRIORITY_NEUTRAL - 1);
