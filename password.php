<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

const PHPWG_ROOT_PATH = './';
require_once __DIR__.'/inc/common.php';
require_once __DIR__.'/inc/functions_mail.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_FREE);

functions_plugins::trigger_notify('loc_begin_password');

functions::check_input_parameter('action', $_GET, false, '/^(lost|reset|none)$/');
$get_action = input_string('action', null, $_GET);
$get_key = input_string('key', null, $_GET);

// +-----------------------------------------------------------------------+
// | Process form                                                          |
// +-----------------------------------------------------------------------+
$post_submit = input_string('submit', null, $_POST);

if ($post_submit !== null) {
    functions::check_pwg_token();

    if ($get_action == 'lost' && functions::process_password_request()) {
        $page['action'] = 'none';
    }

    if ($get_action == 'reset' && functions::reset_password()) {
        $page['action'] = 'none';
    }
}

// +-----------------------------------------------------------------------+
// | key and action                                                        |
// +-----------------------------------------------------------------------+

// a connected user can't reset the password from a mail
if ($get_key !== null &&
    ! functions_user::is_a_guest()
) {
    $get_key = null;
}

if ($get_key !== null &&
    $post_submit === null
) {
    $user_id = functions::check_password_reset_key($get_key);

    if (is_numeric($user_id)) {
        $userdata = functions_user::getuserdata($user_id);
        $page['username'] = $userdata['username'];
        $template->assign('key', $get_key);

        if (! isset($page['action'])) {
            $page['action'] = 'reset';
        }
    } else {
        $page['action'] = 'none';
    }
}

if (! isset($page['action'])) {
    if ($get_action === null) {
        $page['action'] = 'lost';
    } elseif (in_array($get_action, ['lost', 'reset', 'none'])) {
        $page['action'] = $get_action;
    }
}

if ($page['action'] == 'reset' &&
    $get_key === null &&
    (functions_user::is_a_guest() || functions_user::is_generic())
) {
    functions::redirect(functions_url::get_gallery_home_url());
}

if ($page['action'] == 'lost' &&
    ! functions_user::is_a_guest()
) {
    functions::redirect(functions_url::get_gallery_home_url());
}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+

$title = functions::l10n('Password Reset');

if ($page['action'] == 'lost') {
    $title = functions::l10n('Forgot your password?');

    $post_username_or_email = input_string('username_or_email', null, $_POST);

    if ($post_username_or_email !== null) {
        $template->assign('username_or_email', htmlspecialchars(stripslashes($post_username_or_email)));
    }
}

$page['body_id'] = 'thePasswordPage';

$template->set_filenames([
    'password' => 'password.tpl',
]);
$template->assign(
    [
        'title' => $title,
        'form_action' => functions_url::get_root_url().'password.php',
        'action' => $page['action'],
        'username' => $page['username'] ?? $user['username'],
        'PWG_TOKEN' => functions::get_pwg_token(),
    ]
);

// include menubar
$themeconf = $template->get_template_vars('themeconf');

if (! isset($themeconf['hide_menu_on']) ||
    ! in_array('thePasswordPage', $themeconf['hide_menu_on'])
) {
    menubar::initialize_menu();
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

require __DIR__.'/inc/page_header.php';
functions_plugins::trigger_notify('loc_end_password');
functions_html::flush_page_messages();
$template->pparse('password');
require __DIR__.'/inc/page_tail.php';
