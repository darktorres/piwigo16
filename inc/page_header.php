<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//
// Start output of page
//
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;

$template->set_filenames([
    'header' => 'header.tpl',
]);

functions_plugins::trigger_notify('loc_begin_page_header');

$show_mobile_app_banner = functions::conf_get_param('show_mobile_app_banner_in_gallery', false);
if (defined('IN_ADMIN') and IN_ADMIN) {
    $show_mobile_app_banner = functions::conf_get_param('show_mobile_app_banner_in_admin', true);
}

$template->assign(
    [
        'GALLERY_TITLE' =>
          isset($page['gallery_title']) ?
            $page['gallery_title'] : $conf['gallery_title'],

        'PAGE_BANNER' =>
          functions_plugins::trigger_change(
              'render_page_banner',
              str_replace(
                  '%gallery_title%',
                  $conf['gallery_title'],
                  isset($page['page_banner']) ? $page['page_banner'] : $conf['page_banner']
              )
          ),

        'BODY_ID' =>
          isset($page['body_id']) ?
            $page['body_id'] : '',

        'CONTENT_ENCODING' => functions::get_pwg_charset(),
        'PAGE_TITLE' => strip_tags($title),

        'U_HOME' => functions_url::get_gallery_home_url(),

        'LEVEL_SEPARATOR' => $conf['level_separator'],

        'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,

        'BODY_CLASSES' => $page['body_classes'],

        'BODY_DATA' => json_encode($page['body_data']),
    ]
);

// Header notes
if (! empty($header_notes)) {
    $template->assign('header_notes', $header_notes);
}

// No referencing is required
if (! $conf['meta_ref']) {
    $page['meta_robots']['noindex'] = 1;
    $page['meta_robots']['nofollow'] = 1;
}

if (! empty($page['meta_robots'])) {
    $template->append(
        'head_elements',
        '<meta name="robots" content="'
          . implode(',', array_keys($page['meta_robots']))
          . '">'
    );
}
if (! isset($page['meta_robots']['noindex'])) {
    $template->assign('meta_ref', 1);
}

// refresh
if (isset($refresh) and intval($refresh) >= 0
    and isset($url_link)) {
    $template->assign(
        [
            'page_refresh' => [
                'TIME' => $refresh,
                'U_REFRESH' => $url_link,
            ],
        ]
    );
}

functions_plugins::trigger_notify('loc_end_page_header');

header('Content-Type: text/html; charset=' . functions::get_pwg_charset());
$template->parse('header');

functions_plugins::trigger_notify('loc_after_page_header');
