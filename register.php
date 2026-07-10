<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// ----------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;

// $page['errors'] is always initialized to an array by common.inc.php, but
// that isn't visible across the include() boundary -- narrow it once here
// so every top-level $page['errors'][...] = ... write below type-checks.
$page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_FREE);

// ----------------------------------------------------------- user registration

if (! (bool) $conf['allow_user_registration']) {
    page_forbidden('User registration closed');
}

trigger_notify('loc_begin_register');

if (isset($_POST['submit'])) {
    $post_key = $_POST['key'] ?? null;
    if (! is_string($post_key)) {
        $post_key = '';
    }
    if (! verify_ephemeral_key($post_key)) {
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

    $post_login = is_string($_POST['login'] ?? null) ? $_POST['login'] : '';
    $post_password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $post_mail_address = is_string($_POST['mail_address'] ?? null) ? $_POST['mail_address'] : null;

    // register_user()'s by-ref $errors is a plain array<int, string> list of
    // validation messages -- it reindexes it internally via
    // array_values(array_filter(...)) before returning (see
    // include/functions_user.inc.php). That is a different shape than
    // $page['errors'], which register.tpl reads by the specific keys
    // 'register_page_error'/'register_form_error' set above. Passing
    // $page['errors'] directly as the by-ref argument used to let
    // register_user() silently overwrite it with its own reindexed list,
    // erasing those keys so the form-key/password error messages stopped
    // rendering whenever register_user() also ran. Use a separate list and
    // fold it into 'register_form_error' instead.
    $registration_errors = [];
    register_user(
        $post_login,
        $post_password,
        $post_mail_address,
        true,
        $registration_errors,
        isset($_POST['send_password_by_mail'])
    );

    if ($registration_errors !== []) {
        $existing_form_error = $page['errors']['register_form_error'] ?? null;
        $form_error_messages = is_string($existing_form_error)
            ? [$existing_form_error, ...$registration_errors]
            : $registration_errors;
        $page['errors']['register_form_error'] = implode(' ', $form_error_messages);
    }

    if (count($page['errors']) == 0) {
        // email notification
        if (isset($_POST['send_password_by_mail']) and email_check_format($post_mail_address)) {
            if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                $_SESSION['page_infos'] = [];
            }
            $_SESSION['page_infos'][] = l10n('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
        }

        // log user and redirect
        $user_id = get_userid($post_login);
        log_user($user_id, false);
        redirect(make_index_url());
    }
    $registration_post_key = get_ephemeral_key(2);
} else {
    $registration_post_key = get_ephemeral_key(6);
}

$login_raw = $_POST['login'] ?? null;
$login = ! empty($login_raw) && is_string($login_raw) ? htmlspecialchars(stripslashes($login_raw)) : '';

$mail_raw = $_POST['mail_address'] ?? null;
$email = ! empty($mail_raw) && is_string($mail_raw) ? htmlspecialchars(stripslashes($mail_raw)) : '';

// ----------------------------------------------------- template initialization
//
// Start output of page
//
$title = l10n('Registration');
$page['body_id'] = 'theRegisterPage';

$template->set_filenames([
    'register' => 'register.tpl',
]);
$template->assign([
    'U_HOME' => make_index_url(),
    'F_KEY' => $registration_post_key,
    'F_ACTION' => 'register.php',
    'F_LOGIN' => $login,
    'F_EMAIL' => $email,
    'obligatory_user_mail_address' => $conf['obligatory_user_mail_address'],
]);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$hide_menu_on = is_array($themeconf) ? ($themeconf['hide_menu_on'] ?? null) : null;
if (! is_array($hide_menu_on) or ! in_array('theRegisterPage', $hide_menu_on)) {
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

// Load language if cookie is set from login/register/password pages
if (isset($_COOKIE['lang']) and $user['language'] != $_COOKIE['lang']) {
    $lang_cookie = $_COOKIE['lang'];
    if (! is_string($lang_cookie)) {
        fatal_error('[Hacking attempt] the input parameter "lang" is not valid');
    }
    if (! array_key_exists($lang_cookie, get_languages())) {
        fatal_error('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
    }

    $user['language'] = $lang_cookie;
    load_language('common.lang', '', [
        'language' => $user['language'],
    ]);
}

// Get list of languages
$language_options = [];
foreach (get_languages() as $language_code => $language_name) {
    $language_options[$language_code] = $language_name;
}

$template->assign([
    'language_options' => $language_options,
    'current_language' => $user['language'],
]);

// Get link to doc
$user_language_for_help = $user['language'] ?? '';
$user_language_for_help = is_string($user_language_for_help) ? $user_language_for_help : '';
if (str_starts_with($user_language_for_help, 'fr')) {
    $help_link = 'https://upstream.example.invalid/help/fr/';
} else {
    $help_link = 'https://upstream.example.invalid/help/';
}

$template->assign('HELP_LINK', $help_link);

include PHPWG_ROOT_PATH . 'include/page_header.php';
trigger_notify('loc_end_register');
flush_page_messages();
$template->parse('register');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
