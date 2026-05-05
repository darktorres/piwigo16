<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Core\Kernel;

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//----------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
Kernel::boot();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_FREE);

//----------------------------------------------------------- user registration

if (!Config::allowUserRegistration()) {
    page_forbidden('User registration closed');
}

trigger_notify('loc_begin_register');

$post_login = input_string('login', null, $_POST);
$post_mail = input_string('mail_address', null, $_POST);
$post_key = input_string('key', null, $_POST) ?? '';
$post_send_mail = input_string('send_password_by_mail', null, $_POST) !== null;

if (input_string('submit', null, $_POST) !== null) {
    if (!verify_ephemeral_key($post_key)) {
        set_status_header(403);
        $page['errors']['register_page_error'] = l10n('Invalid/expired form key');
    }

    if (empty($_POST['password'])) {
        $page['errors']['register_form_error'] = l10n('Password is missing. Please enter the password.');
    } elseif (empty($_POST['password_conf'])) {
        $page['errors']['register_form_error'] = l10n('Password confirmation is missing. Please confirm the chosen password.');
    } elseif ($_POST['password'] != $_POST['password_conf']) {
        $page['errors']['register_form_error'] = l10n('The passwords do not match');
    }

    $post_password = is_string($_POST['password']) ? $_POST['password'] : '';
    register_user(
        $post_login ?? '',
        $post_password,
        $post_mail ?? '',
        true,
        $page['errors'],
        $post_send_mail
    );

    if (count($page['errors']) == 0) {
        // email notification
        if ($post_send_mail and email_check_format($post_mail ?? '')) {
            if (!is_array($_SESSION['page_infos'])) {
                $_SESSION['page_infos'] = [];
            }
            $_SESSION['page_infos'][] = l10n('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
        }

        // log user and redirect
        $user_id = get_userid($post_login ?? '');
        if ($user_id !== false) {
            log_user((int) $user_id, false);
        }
        redirect(make_index_url());
    }
    $registration_post_key = get_ephemeral_key(2);
} else {
    $registration_post_key = get_ephemeral_key(6);
}

$login = !empty($post_login) ? htmlspecialchars(stripslashes($post_login)) : '';
$email = !empty($post_mail) ? htmlspecialchars(stripslashes($post_mail)) : '';

//----------------------------------------------------- template initialization
//
// Start output of page
//
$title = l10n('Registration');
$page['body_id'] = 'theRegisterPage';

$template->set_filenames(['register' => 'register.tpl']);
$template->assign([
  'U_HOME' => make_index_url(),
    'F_KEY' => $registration_post_key,
  'F_ACTION' => 'register.php',
  'F_LOGIN' => $login,
  'F_EMAIL' => $email,
  'obligatory_user_mail_address' => Config::obligatoryUserMailAddress(),
]);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
if (!isset($themeconf['hide_menu_on']) or !in_array('theRegisterPage', $themeconf['hide_menu_on'])) {
    require(PHPWG_ROOT_PATH.'include/menubar.inc.php');
}

//Load language if cookie is set from login/register/password pages
$cookie_lang = input_string('lang', null, $_COOKIE);
if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
    if (!array_key_exists($cookie_lang, get_languages())) {
        fatal_error('[Hacking attempt] the input parameter "'.$cookie_lang.'" is not valid');
    }

    $user['language'] = $cookie_lang;
    load_language('common.lang', '', ['language' => $user['language']]);
}

//Get list of languages
$language_options = [];
foreach (get_languages() as $language_code => $language_name) {
    $language_options[$language_code] = $language_name;
}

$template->assign([
  'language_options' => $language_options,
  'current_language' => $user['language'],
]);

$template->assign('page_data_json', json_encode([
    'selected_language' => $language_options[$user['language']] ?? '',
    'url_logo_light' => get_root_url() . 'themes/standard_pages/images/piwigo_logo.svg',
    'url_logo_dark'  => get_root_url() . 'themes/standard_pages/images/piwigo_logo_dark.svg',
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

//Get link to doc
if (str_starts_with((string) $user['language'], 'fr')) {
    $help_link = 'https://doc-fr.piwigo.org/les-utilisateurs/se-connecter-a-piwigo';
} else {
    $help_link = 'https://doc.piwigo.org/managing-users/log-in-to-piwigo';
}

$template->assign('HELP_LINK', $help_link);

require(PHPWG_ROOT_PATH.'include/page_header.php');
trigger_notify('loc_end_register');
flush_page_messages();
$template->parse('register');
require(PHPWG_ROOT_PATH.'include/page_tail.php');
