<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// --------------------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $page, $template;

// $page['errors'] is always initialized to an array by common.inc.php, but
// that isn't visible across the include() boundary -- narrow it once here
// so the top-level $page['errors'][...] = ... write below type-checks.
$page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

check_status(ACCESS_FREE);
include_once PHPWG_ROOT_PATH . 'include/functions_notification.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions_notification_by_mail.inc.php';
// Translations are in admin file too
load_language('admin.lang');
// Need to update a second time
trigger_notify('loading_lang');
load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
    'no_fallback' => true,
    'local' => true,
]);

// +-----------------------------------------------------------------------+
// | Main                                                                  |
// +-----------------------------------------------------------------------+
if (isset($_GET['subscribe']) and is_string($_GET['subscribe'])
    and (bool) preg_match('/^[A-Za-z0-9]{16}$/', $_GET['subscribe'])) {
    subscribe_notification_by_mail(false, [$_GET['subscribe']]);
} elseif (isset($_GET['unsubscribe']) and is_string($_GET['unsubscribe'])
    and (bool) preg_match('/^[A-Za-z0-9]{16}$/', $_GET['unsubscribe'])) {
    unsubscribe_notification_by_mail(false, [$_GET['unsubscribe']]);
} else {
    $page['errors'][] = l10n('Unknown identifier');
}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+
$title = l10n('Notification');
$page['body_id'] = 'theNBMPage';

$template->set_filenames([
    'nbm' => 'nbm.tpl',
]);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
$hide_menu_on = $themeconf['hide_menu_on'] ?? null;
if (! is_array($hide_menu_on) or ! in_array('theNBMPage', $hide_menu_on)) {
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

// +-----------------------------------------------------------------------+
// | html code display                                                     |
// +-----------------------------------------------------------------------+
include PHPWG_ROOT_PATH . 'include/page_header.php';
flush_page_messages();
$template->parse('nbm');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
