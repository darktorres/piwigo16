<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Template\Template;

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $conf, $page, $template;
// Set by the including page script, right before this include.
/** @var string $title */
global $title;

//
// Start output of page
//
$template->set_filenames([
    'header' => 'header.tpl',
]);

trigger_notify('loc_begin_page_header');

$show_mobile_app_banner = conf_get_param('show_mobile_app_banner_in_gallery', false);
if (defined('IN_ADMIN') and IN_ADMIN) {
    $show_mobile_app_banner = conf_get_param('show_mobile_app_banner_in_admin', true);
}

// Config values loaded from the piwigo_config DB table (TEXT column) are
// always strings; $page['page_banner'] is only ever assigned string literals
// (see popuphelp.php, admin.php, admin/popuphelp.php).
/** @var string $conf_gallery_title */
$conf_gallery_title = $conf['gallery_title'];
/** @var string $page_banner */
$page_banner = $page['page_banner'] ?? $conf['page_banner'];

$template->assign(
    [
        'GALLERY_TITLE' => $page['gallery_title'] ?? $conf_gallery_title,

        'PAGE_BANNER' => trigger_change(
            'render_page_banner',
            str_replace(
                '%gallery_title%',
                $conf_gallery_title,
                $page_banner
            )
        ),

        'BODY_ID' => $page['body_id'] ?? '',

        'CONTENT_ENCODING' => get_pwg_charset(),
        'PAGE_TITLE' => strip_tags($title),

        'U_HOME' => get_gallery_home_url(),

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
// (real invariant: $page['meta_robots'], when set, is always a name=>1 map --
// see notification.php, popuphelp.php, picture.php, index.php,
// admin/popuphelp.php, include/section_init.inc.php)
if (! isset($page['meta_robots']) || ! is_array($page['meta_robots'])) {
    $page['meta_robots'] = [];
}
if (! (bool) $conf['meta_ref']) {
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
// $refresh/$url_link are optional variables the including page script may
// set in top-level scope right before this include (mirrors $title above);
// no current caller sets them, but the contract predates this file.
$refresh_numeric = isset($refresh) && is_numeric($refresh) ? $refresh : null;
if ($refresh_numeric !== null and intval($refresh_numeric) >= 0
    and isset($url_link)) {
    $template->assign(
        [
            'page_refresh' => [
                'TIME' => $refresh_numeric,
                'U_REFRESH' => $url_link,
            ],
        ]
    );
}

trigger_notify('loc_end_page_header');

header('Content-Type: text/html; charset=' . get_pwg_charset());
$template->parse('header');

trigger_notify('loc_after_page_header');
