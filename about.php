<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Template\Template;

// Bootstrap globals, set by include/common.inc.php below.
/**
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $template, $user;

// ----------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Guest);

// ----------------------------------------------------- template initialization
//
// Start output of page
//
$title = l10n('About Piwigo');
/** @var array<string, mixed> $page */
$page['body_id'] = 'theAboutPage';

trigger_notify('loc_begin_about');

$template->set_filename('about', 'about.tpl');

$template->assign('ABOUT_MESSAGE', load_language('about.html', '', [
    'return' => true,
]));

// build_user() (include/functions_user.inc.php) always resolves
// $user['theme'] to a validated, installed theme string before
// include/common.inc.php returns; $user itself is only known here as
// array<string, mixed>, so narrow with a defensive fallback rather than
// trust the shape blindly.
$user_theme = $user['theme'] ?? null;
$user_theme = is_string($user_theme) ? $user_theme : '';

$theme_about = load_language('about.html', Config::themesPath() . $user_theme . '/', [
    'return' => true,
]);
if ($theme_about !== false) {
    $template->assign('THEME_ABOUT', $theme_about);
}

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theAboutPage', $themeconf['hide_menu_on'])) {
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

include PHPWG_ROOT_PATH . 'include/page_header.php';
flush_page_messages();
$template->pparse('about');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
