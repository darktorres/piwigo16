<?php

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
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH . 'inc/common.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_FREE);

//----------------------------------------------------------- user registration

if (! $conf['allow_user_registration']) {
    functions_html::page_forbidden('User registration closed');
}

functions_plugins::trigger_notify('loc_begin_register');

if (isset($_POST['submit'])) {
    if (! functions::verify_ephemeral_key(@$_POST['key'])) {
        functions_html::set_status_header(403);
        $page['errors'][] = functions::l10n('Invalid/expired form key');
    }

    if (empty($_POST['password'])) {
        $page['errors'][] = functions::l10n('Password is missing. Please enter the password.');
    } elseif (empty($_POST['password_conf'])) {
        $page['errors'][] = functions::l10n('Password confirmation is missing. Please confirm the chosen password.');
    } elseif ($_POST['password'] != $_POST['password_conf']) {
        $page['errors'][] = functions::l10n('The passwords do not match');
    }

    functions_user::register_user(
        $_POST['login'],
        $_POST['password'],
        $_POST['mail_address'],
        true,
        $page['errors'],
        isset($_POST['send_password_by_mail'])
    );

    if (count($page['errors']) == 0) {
        // email notification
        if (isset($_POST['send_password_by_mail']) and
            functions::email_check_format($_POST['mail_address'])
        ) {
            $_SESSION['page_infos'][] = functions::l10n('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
        }

        // log user and redirect
        $user_id = functions_user::get_userid($_POST['login']);
        functions_user::log_user($user_id, false);
        functions::redirect(functions_url::make_index_url());
    }

    $registration_post_key = functions::get_ephemeral_key(2);
} else {
    $registration_post_key = functions::get_ephemeral_key(6);
}

$login = ! empty($_POST['login']) ? htmlspecialchars(stripslashes($_POST['login'])) : '';
$email = ! empty($_POST['mail_address']) ? htmlspecialchars(stripslashes($_POST['mail_address'])) : '';

//----------------------------------------------------- template initialization
//
// Start output of page
//
$title = functions::l10n('Registration');
$page['body_id'] = 'theRegisterPage';

$template->set_filenames([
    'register' => 'register.tpl',
]);
$template->assign([
    'U_HOME' => functions_url::make_index_url(),
    'F_KEY' => $registration_post_key,
    'F_ACTION' => 'register.php',
    'F_LOGIN' => $login,
    'F_EMAIL' => $email,
    'obligatory_user_mail_address' => $conf['obligatory_user_mail_address'],
]);

// include menubar
$themeconf = $template->get_template_vars('themeconf');

if (! isset($themeconf['hide_menu_on']) or
    ! in_array('theRegisterPage', $themeconf['hide_menu_on'])
) {
    menubar::initialize_menu();
}

include(PHPWG_ROOT_PATH . 'inc/page_header.php');
functions_plugins::trigger_notify('loc_end_register');
functions_html::flush_page_messages();
$template->parse('register');
include(PHPWG_ROOT_PATH . 'inc/page_tail.php');
