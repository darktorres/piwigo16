<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Template\Template;

// --------------------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
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

// but if the user is already identified, we redirect to gallery home
// instead of displaying the log in form
if (! is_a_guest()) {
    $gallery_home_url = get_gallery_home_url();
    redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
}

trigger_notify('loc_begin_identification');

unset($_SESSION['reset_password_code']);

// -------------------------------------------------------------- identification

// security (level 1): the redirect must occur within Piwigo, so the
// redirect param must start with the relative home url
if (isset($_POST['redirect']) && is_string($_POST['redirect'])) {
    $_POST['redirect_decoded'] = urldecode($_POST['redirect']);
}
check_input_parameter('redirect_decoded', $_POST, false, '{^' . preg_quote(cookie_path()) . '}');

$redirect_to = '';
if (! empty($_GET['redirect']) && is_string($_GET['redirect'])) {
    $redirect_to = urldecode($_GET['redirect']);
    if ((bool) $conf['guest_access'] and ! isset($_GET['hide_redirect_error'])) {
        $page['errors']['login_page_error'] = l10n('You are not authorized to access the requested page');
    }
}

if (isset($_POST['login'])) {
    if (! isset($_COOKIE[session_name()])) {
        $page['errors']['login_page_error'] = l10n('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
    } else {
        // $_POST['username'] is required to be a string for try_log_user();
        // an unset/non-string value falls back to '' which will simply not
        // match any account. $_POST['password'] is allowed to be null (both
        // this and ws_session_login() are try_log_user()'s only real
        // callers, and both can genuinely omit the field).
        $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : null;

        if ($conf['insensitive_case_logon'] == true) {
            $username = search_case_username($username);
        }

        $redirect_to = is_string($_POST['redirect'] ?? null) ? urldecode($_POST['redirect']) : '';
        $remember_me = isset($_POST['remember_me']) and $_POST['remember_me'] == 1;

        if (try_log_user($username, $password, $remember_me)) {
            // security (level 2): force redirect within Piwigo. We redirect to
            // absolute root url, including http(s)://, without the cookie path,
            // concatenated with $_POST['redirect'] param.
            //
            // example:
            // {redirect (raw) = /piwigo/git/admin.php}
            // {get_absolute_root_url = http://localhost/piwigo/git/}
            // {cookie_path = /piwigo/git/}
            // {host = http://localhost}
            // {redirect (final) = http://localhost/piwigo/git/admin.php}
            $root_url = get_absolute_root_url();

            $_SESSION['connected_with'] = 'pwg_ui';

            $gallery_home_url = get_gallery_home_url();

            redirect(
                empty($redirect_to)
                ? (is_string($gallery_home_url) ? $gallery_home_url : '')
                : substr($root_url, 0, strlen($root_url) - strlen(cookie_path())) . $redirect_to
            );
        } else {
            $page['errors']['login_form_error'] = l10n('Invalid username or password!');
        }
    }
}

// ----------------------------------------------------- template initialization
//
// Start output of page
//
$title = l10n('Identification');
$page['body_id'] = 'theIdentificationPage';

$template->set_filenames([
    'identification' => 'identification.tpl',
]);

$template->assign(
    [
        'U_REDIRECT' => $redirect_to,

        'F_LOGIN_ACTION' => get_root_url() . 'identification.php',
        'authorize_remembering' => $conf['authorize_remembering'],
    ]
);

if (! (bool) $conf['gallery_locked'] && (bool) $conf['allow_user_registration']) {
    $template->assign('U_REGISTER', get_root_url() . 'register.php');
}

if (! (bool) $conf['gallery_locked']) {
    $template->assign('U_LOST_PASSWORD', get_root_url() . 'password.php');
}

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
$hide_menu_on = $themeconf['hide_menu_on'] ?? null;
if (! (bool) $conf['gallery_locked'] && (! is_array($hide_menu_on) or ! in_array('theIdentificationPage', $hide_menu_on))) {
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

// ----------------------------------------------------------- html code display
include PHPWG_ROOT_PATH . 'include/page_header.php';
trigger_notify('loc_end_identification');
flush_page_messages();
$template->pparse('identification');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
