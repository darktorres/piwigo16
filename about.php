<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//----------------------------------------------------------- include
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

const PHPWG_ROOT_PATH = './';
require_once __DIR__ . '/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

//----------------------------------------------------- template initialization
//
// Start output of page
//
$title = functions::l10n('About Piwigo');
$page['body_id'] = 'theAboutPage';

functions_plugins::trigger_notify('loc_begin_about');

$template->set_filename('about', 'about.tpl');

$template->assign('ABOUT_MESSAGE', functions::load_language('about.html', '', [
    'return' => true,
]));

$theme_about = functions::load_language('about.html', PHPWG_THEMES_PATH . $user['theme'] . '/', [
    'return' => true,
]);

if ($theme_about !== false) {
    $template->assign('THEME_ABOUT', $theme_about);
}

// include menubar
$themeconf = $template->get_template_vars('themeconf');

if (! isset($themeconf['hide_menu_on']) ||
    ! in_array('theAboutPage', $themeconf['hide_menu_on'])
) {
    menubar::initialize_menu();
}

require __DIR__ . '/inc/page_header.php';
functions_html::flush_page_messages();
$template->pparse('about');
require __DIR__ . '/inc/page_tail.php';
