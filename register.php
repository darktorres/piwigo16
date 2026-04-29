<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// ----------------------------------------------------------- include
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

const PHPWG_ROOT_PATH = './';
require_once __DIR__.'/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_FREE);

// ----------------------------------------------------------- user registration

if (! $conf->allow_user_registration) {
    functions_html::page_forbidden('User registration closed');
}

functions_plugins::trigger_notify('loc_begin_register');

if (input_string('submit', null, $_POST) !== null) {
    $post_key = input_string('key', null, $_POST);
    $post_login = input_string('login', null, $_POST);
    $post_password = input_string('password', null, $_POST);
    $post_password_conf = input_string('password_conf', null, $_POST);
    $post_mail_address = input_string('mail_address', null, $_POST);
    $post_send_password_by_mail = input_string('send_password_by_mail', null, $_POST) !== null;

    if (! functions::verify_ephemeral_key($post_key)) {
        functions_html::set_status_header(403);
        $page['errors'][] = functions::l10n('Invalid/expired form key');
    }

    if (empty($post_password)) {
        $page['errors'][] = functions::l10n('Password is missing. Please enter the password.');
    } elseif (empty($post_password_conf)) {
        $page['errors'][] = functions::l10n('Password confirmation is missing. Please confirm the chosen password.');
    } elseif ($post_password != $post_password_conf) {
        $page['errors'][] = functions::l10n('The passwords do not match');
    }

    functions_user::register_user(
        $post_login,
        $post_password,
        $post_mail_address,
        true,
        $page['errors'],
        $post_send_password_by_mail
    );

    if ($page['errors'] === []) {
        // email notification
        if ($post_send_password_by_mail &&
            functions::email_check_format($post_mail_address)
        ) {
            $_SESSION['page_infos'][] = functions::l10n('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
        }

        // log user and redirect
        $user_id = functions_user::get_userid($post_login);
        functions_user::log_user($user_id, false);
        functions::redirect(functions_url::make_index_url());
    }

    $registration_post_key = functions::get_ephemeral_key(2);
} else {
    $registration_post_key = functions::get_ephemeral_key(6);
    $post_login = null;
    $post_mail_address = null;
}

$login = empty($post_login) ? '' : htmlspecialchars(stripslashes($post_login));
$email = empty($post_mail_address) ? '' : htmlspecialchars(stripslashes($post_mail_address));

// ----------------------------------------------------- template initialization
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
    'obligatory_user_mail_address' => $conf->obligatory_user_mail_address,
]);

// include menubar
$themeconf = $template->get_template_vars('themeconf');

if (! isset($themeconf['hide_menu_on']) ||
    ! in_array('theRegisterPage', $themeconf['hide_menu_on'])
) {
    menubar::initialize_menu();
}

require __DIR__.'/inc/page_header.php';
functions_plugins::trigger_notify('loc_end_register');
functions_html::flush_page_messages();
$template->parse('register');
require __DIR__.'/inc/page_tail.php';
