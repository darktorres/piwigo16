<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//--------------------------------------------------------------------- include
use Piwigo\admin\inc\functions_notification_by_mail;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH . 'inc/common.php');
functions_user::check_status(ACCESS_FREE);
include_once(PHPWG_ROOT_PATH . 'inc/functions_mail.php');
include_once(PHPWG_ROOT_PATH . 'admin/inc/functions_notification_by_mail.php');
// Translations are in admin file too
functions::load_language('admin.lang');
// Need to update a second time
functions_plugins::trigger_notify('loading_lang');
functions::load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
    'no_fallback' => true,
    'local' => true,
]);

// +-----------------------------------------------------------------------+
// | Main                                                                  |
// +-----------------------------------------------------------------------+
if (isset($_GET['subscribe'])
    and preg_match('/^[A-Za-z0-9]{16}$/', $_GET['subscribe'])) {
    functions_notification_by_mail::subscribe_notification_by_mail(false, [$_GET['subscribe']]);
} elseif (isset($_GET['unsubscribe'])
    and preg_match('/^[A-Za-z0-9]{16}$/', $_GET['unsubscribe'])) {
    functions_notification_by_mail::unsubscribe_notification_by_mail(false, [$_GET['unsubscribe']]);
} else {
    $page['errors'][] = functions::l10n('Unknown identifier');
}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+
$title = functions::l10n('Notification');
$page['body_id'] = 'theNBMPage';

$template->set_filenames([
    'nbm' => 'nbm.tpl',
]);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
if (! isset($themeconf['hide_menu_on']) or ! in_array('theNBMPage', $themeconf['hide_menu_on'])) {
    menubar::initialize_menu();
}

// +-----------------------------------------------------------------------+
// | html code display                                                     |
// +-----------------------------------------------------------------------+
include(PHPWG_ROOT_PATH . 'inc/page_header.php');
functions_html::flush_page_messages();
$template->parse('nbm');
include(PHPWG_ROOT_PATH . 'inc/page_tail.php');
