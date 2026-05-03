<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//--------------------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_FREE);

// but if the user is already identified, we redirect to gallery home
// instead of displaying the log in form
if (!is_a_guest()) {
    redirect(get_gallery_home_url());
}

trigger_notify('loc_begin_identification');

unset($_SESSION['reset_password_code']);

//-------------------------------------------------------------- identification

// security (level 1): the redirect must occur within Piwigo, so the
// redirect param must start with the relative home url
$post_redirect = input_string('redirect', null, $_POST);
if ($post_redirect !== null) {
    $_POST['redirect_decoded'] = urldecode($post_redirect);
}
check_input_parameter('redirect_decoded', $_POST, false, '{^'.preg_quote((string) cookie_path()).'}');

$redirect_to = '';
$get_redirect = input_string('redirect', null, $_GET);
if (!empty($get_redirect)) {
    $redirect_to = urldecode($get_redirect);
    if (\Piwigo\Config\Config::guestAccess() and input_string('hide_redirect_error', null, $_GET) === null) {
        $page['errors']['login_page_error'] = l10n('You are not authorized to access the requested page');
    }
}

if (input_string('login', null, $_POST) !== null) {
    if (!isset($_COOKIE[session_name()])) {
        $page['errors']['login_page_error'] = l10n('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
    } else {
        $username = input_string('username', null, $_POST) ?? '';
        if (\Piwigo\Config\Config::insensitiveCaseLogon() == true) {
            $username = search_case_username($username);
        }

        $redirect_to = $post_redirect !== null ? urldecode($post_redirect) : '';
        $remember_me_raw = input_string('remember_me', null, $_POST);
        $remember_me = $remember_me_raw !== null && $remember_me_raw == 1;

        $post_password = is_string($_POST['password']) ? $_POST['password'] : '';
        if (try_log_user($username, $post_password, $remember_me)) {
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

            redirect(
                empty($redirect_to)
                ? get_gallery_home_url()
                : substr((string) $root_url, 0, strlen((string) $root_url) - strlen((string) cookie_path())).$redirect_to
            );
        } else {
            $page['errors']['login_form_error'] = l10n('Invalid username or password!');
        }
    }
}

//----------------------------------------------------- template initialization
//
// Start output of page
//
$title = l10n('Identification');
$page['body_id'] = 'theIdentificationPage';

$template->set_filenames(['identification' => 'identification.tpl']);

$template->assign(
    [
    'U_REDIRECT' => $redirect_to,

    'F_LOGIN_ACTION' => get_root_url().'identification.php',
    'authorize_remembering' => \Piwigo\Config\Config::authorizeRemembering(),
    ]
);

if (!\Piwigo\Config\Config::galleryLocked() && \Piwigo\Config\Config::allowUserRegistration()) {
    $template->assign('U_REGISTER', get_root_url().'register.php');
}

if (!\Piwigo\Config\Config::galleryLocked()) {
    $template->assign('U_LOST_PASSWORD', get_root_url().'password.php');
}

// include menubar
$themeconf = $template->get_template_vars('themeconf');
if (!\Piwigo\Config\Config::galleryLocked() && (!isset($themeconf['hide_menu_on']) or !in_array('theIdentificationPage', $themeconf['hide_menu_on']))) {
    include(PHPWG_ROOT_PATH.'include/menubar.inc.php');
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

//----------------------------------------------------------- html code display
include(PHPWG_ROOT_PATH.'include/page_header.php');
trigger_notify('loc_end_identification');
flush_page_messages();
$template->pparse('identification');
include(PHPWG_ROOT_PATH.'include/page_tail.php');
