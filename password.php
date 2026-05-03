<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();
include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_FREE);

trigger_notify('loc_begin_password');

check_input_parameter('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');

// +-----------------------------------------------------------------------+
// | Functions                                                             |
// +-----------------------------------------------------------------------+
/**
 * checks the validity of input parameters, fills $page['errors'] and
 * $page['infos'] and send an email with the verification code
 */
function process_verification_code(): bool
{
    global $page, $logger;

    if (isset($_SESSION['reset_password_code'])) {
        return true;
    }

    // empty param
    $username_or_email = input_string('username_or_email', '', $_POST);
    if (empty($username_or_email)) {
        $page['errors']['password_form_error'] = l10n('Invalid username or email');
        return false;
    }

    // retrievies user by email is not try by username
    $user_id = get_userid_by_email($username_or_email);

    if (!is_numeric($user_id)) {
        $user_id = get_userid($username_or_email);
    }

    // when no user is found, we assign guest_id instead of stopping.
    // this lets the function behave identically for unknown users,
    // preventing username/email enumeration through timing or responses.
    $is_user_found = is_numeric($user_id);
    if (!$is_user_found) {
        $user_id = \Piwigo\Config\Config::guestId();
    }

    $userdata = getuserdata($user_id, false);
    if ($userdata === false) {
        $userdata = ['status' => 'guest', 'language' => get_default_language(), 'email' => ''];
    }

    $status = isset($userdata['status']) && is_scalar($userdata['status']) ? (string)$userdata['status'] : 'guest';
    $userdata_language = isset($userdata['language']) && is_scalar($userdata['language']) ? (string)$userdata['language'] : get_default_language();
    $userdata_email = isset($userdata['email']) && is_scalar($userdata['email']) ? (string)$userdata['email'] : '';

    if ($is_user_found) {
        // block early for generic or guest user because
        // we don't consider theses users has sensible for username/email enumeration
        if (is_a_guest($status) or is_generic($status)) {
            $page['errors']['password_form_error'] = l10n('Password reset is not allowed for this user');
            return false;
        }

        // check lockout
        $userdata_prefs = is_array($userdata['preferences'] ?? null) ? $userdata['preferences'] : [];
        if (
            isset($userdata_prefs['reset_password_forbidden_until'])
            and is_numeric($userdata_prefs['reset_password_forbidden_until'])
            and (int)$userdata_prefs['reset_password_forbidden_until'] > time()
        ) {
            $page['errors']['password_form_error'] = l10n('Too many attempts, please try later..');
            return false;
        }
    }

    // check if we want to skip email sending
    // if user is guest, generic or doesn't have email
    $skip_mail = !$is_user_found || empty($userdata_email);

    // send mail with verification code to user
    switch_lang_to($userdata_language);
    $user_code = generate_user_code();
    $template_mail = pwg_generate_code_verification_mail(is_scalar($user_code['code'] ?? '') ? (string)($user_code['code'] ?? '') : '');
    if (!$skip_mail) {
        $mail_send = pwg_mail($userdata_email, $template_mail);
    }
    switch_lang_back();

    $_SESSION['reset_password_code'] = [
        'secret' => $user_code['secret'],
        'attempts' => 0,
        'user_id' => $is_user_found ? $user_id : null,
        'created_at' => time(),
        'ttl' => min(\Piwigo\Config\Config::passwordResetCodeDuration(), 900), // max 15 min
      ];

    return true;
}

/**
 * checks the validity of input parameters, fills $page['errors'] and
 * $page['infos']
 *
 * @return bool (true if valid, false otherwise)
 */
function process_password_request(): bool
{
    global $page, $user;

    /** @var array{secret: string, attempts: int, user_id: int|null, created_at: int, ttl: int}|null $state */
    $state = is_array($_SESSION['reset_password_code'] ?? null) ? $_SESSION['reset_password_code'] : null;
    if (!$state) {
        return true;
    }

    // check expired
    if (time() > $state['created_at'] + $state['ttl']) {
        unset($_SESSION['reset_password_code']);
        $page['errors']['password_form_error'] = l10n('Code expired');
        return false;
    }

    /** @var array<string, mixed> $session_code */
    $session_code = is_array($_SESSION['reset_password_code'] ?? null) ? $_SESSION['reset_password_code'] : [];
    $current_attempts = is_numeric($session_code['attempts'] ?? 0) ? (int)($session_code['attempts'] ?? 0) : 0;
    $current_attempts++;
    $session_code['attempts'] = $current_attempts;
    $_SESSION['reset_password_code'] = $session_code;

    $is_valid = true;
    $user_code = input_string('user_code', '', $_POST);

    if (
        empty($user_code) // empty user code
        || !preg_match('/^\d{6}$/', $user_code) // check digit 6
        || !verify_user_code($state['secret'], $user_code)) { // verify user code
        $is_valid = false;
    }

    if (!$is_valid) {
        if ($current_attempts >= 3) {
            unset($_SESSION['reset_password_code']);
            // lockout account for 1hour
            if (!empty($state['user_id'])) {
                $state_user_id = (int)$state['user_id'];
                $save_user = $user;
                $user = build_user($state_user_id, false);
                userprefs_update_param('reset_password_forbidden_until', time() + 60 * 60);
                $user = $save_user;

                pwg_activity('user', $state_user_id, 'reset_password_failure_too_many');
            }
            $page['errors']['login_page_error'] = l10n('Too many attempts, please try later..');
            return false;
        }

        if (!empty($state['user_id'])) {
            pwg_activity('user', (int)$state['user_id'], 'reset_password_failure_code');
        }
        $page['errors']['password_form_error'] = l10n('Invalid verification code');
        return false;
    }

    // verify code success
    $user_id = $state['user_id'];
    unset($_SESSION['reset_password_code']);

    if (empty($user_id)) {
        $page['errors']['password_form_error'] = l10n('Invalid verification code');
        return false;
    }

    $save_user = $user;
    $user = build_user((int)$user_id, false);
    userprefs_delete_param('reset_password_forbidden_until');

    $_SESSION['valid_reset_password_code'] = [
      'user_id' => $user_id,
      'username' => $user['username'],
      'email' => $user['email'],
      'language' => $user['language'],
    ];
    $status = isset($user['status']) && is_scalar($user['status']) ? (string)$user['status'] : '';
    $has_no_email = empty($user['email']);
    $page['username'] = isset($user['username']) && is_scalar($user['username']) ? (string)$user['username'] : '';
    $user = $save_user;

    // fallback check: don't send mail when user is guest, generic or doesn't have email
    if (is_a_guest($status) || is_generic($status) || $has_no_email) {
        $page['errors']['password_form_error'] = l10n('Password reset is not allowed for this user');
        return false;
    }

    return true;
}

/**
 *  checks the activation key: does it match the expected pattern? is it
 *  linked to a user? is this user allowed to reset his password?
 *
 * @return int|false
 */
function check_password_reset_key(string $reset_key): int|false
{
    global $page;

    $key = $reset_key;
    if (!preg_match('/^[a-z0-9]{20}$/i', (string) $key)) {
        $page['errors']['password_page_error'] = l10n('Invalid key');
        return false;
    }

    $query = '
SELECT
    user_id,
    status,
    activation_key
  FROM '.USER_INFOS_TABLE.'
  WHERE activation_key IS NOT NULL
    AND activation_key_expire > NOW()
;';
    $result = pwg_query($query);
    $user_id = null;
    while ($row = pwg_db_fetch_assoc($result)) {
        $activation_key = isset($row['activation_key']) ? (string)$row['activation_key'] : '';
        $row_status = isset($row['status']) ? (string)$row['status'] : '';
        if (password_verify($key, $activation_key)) {
            if (is_a_guest($row_status) or is_generic($row_status)) {
                $page['errors']['password_page_error'] = l10n('Password reset is not allowed for this user');
                return false;
            }

            $user_id = is_numeric($row['user_id']) ? (int)$row['user_id'] : null;
            break;
        }
    }

    if (empty($user_id)) {
        $page['errors']['password_page_error'] = l10n('Invalid key');
        return false;
    }

    return $user_id;
}

/**
 * checks the passwords, checks that user is allowed to reset his password,
 * update password, fills $page['errors'] and $page['infos'].
 *
 * @return bool (true if password was reset, false otherwise)
 */
function reset_password(): bool
{
    global $page;

    if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
        $page['errors']['password_form_error'] = l10n('The passwords do not match');
        return false;
    }

    $user_id = reset_password_key() ?: reset_password_code();

    if (!is_numeric($user_id)) {
        $page['errors']['password_form_error'] = l10n('Invalid key or code');
        return false;
    }

    single_update(
        USERS_TABLE,
        [\Piwigo\Config\Config::userFields()['password'] => password_hash(is_scalar($_POST['use_new_pwd']) ? (string) $_POST['use_new_pwd'] : '', PASSWORD_BCRYPT)],
        [\Piwigo\Config\Config::userFields()['id'] => $user_id]
    );

    /** @var array{user_id: int|null, username: string, email: string, language: string}|null $valid_reset_code */
    $valid_reset_code = is_array($_SESSION['valid_reset_password_code'] ?? null)
        ? $_SESSION['valid_reset_password_code']
        : null;
    if ($valid_reset_code !== null && !empty($valid_reset_code['email'])) {
        $reset_user_language = $valid_reset_code['language'];
        $reset_user_id = $valid_reset_code['user_id'] !== null ? (string)$valid_reset_code['user_id'] : '';
        $reset_user_username = $valid_reset_code['username'];
        $reset_user_email = $valid_reset_code['email'];
        switch_lang_to($reset_user_language);

        $api_keys = get_available_api_key($reset_user_id);
        $nb_of_apikeys = $api_keys ? count($api_keys) : 0;
        $template_mail = pwg_generate_success_reset_password_mail($reset_user_username, $nb_of_apikeys);
        pwg_mail($reset_user_email, $template_mail);

        switch_lang_back();
    }
    unset($_SESSION['valid_reset_password_code']);

    pwg_activity('user', $user_id, 'reset_password_success');

    \Piwigo\Core\PageState::current()->addInfo(l10n('Your password has been reset'));
    \Piwigo\Core\PageState::current()->addInfo('<a href="'.get_root_url().'identification.php">'.l10n('Login').'</a>');

    return true;
}

function reset_password_key(): int|false
{
    $key = input_string('key', null, $_GET);
    if ($key === null) {
        return false;
    }

    $user_id = check_password_reset_key($key);

    if (!is_numeric($user_id)) {
        return false;
    }

    deactivate_password_reset_key((int)$user_id);
    deactivate_user_auth_keys((int)$user_id);
    return (int)$user_id;
}

function reset_password_code(): int|false
{
    if (!isset($_SESSION['valid_reset_password_code'])) {
        return false;
    }

    /** @var array<string, mixed> $code */
    $code = is_array($_SESSION['valid_reset_password_code']) ? $_SESSION['valid_reset_password_code'] : [];
    $user_id = $code['user_id'] ?? false;
    if ($user_id === false || !is_numeric($user_id)) {
        return false;
    }
    return (int)$user_id;
}

// +-----------------------------------------------------------------------+
// | Process form                                                          |
// +-----------------------------------------------------------------------+
$get_action = input_string('action', null, $_GET);

if (input_string('submit', null, $_POST) !== null) {
    check_pwg_token();

    if ('lost' == $get_action) {
        if (process_verification_code()) {
            \Piwigo\Core\PageState::current()->addInfo(l10n('If your account exists, a verification code has been sent to your email address.'));
            $page['action'] = 'lost_code';
        }
    }

    if ('lost_code' == $get_action) {
        if (process_password_request()) {
            \Piwigo\Core\PageState::current()->addInfo(l10n('Verification successful! You can now choose a new password.'));
            $page['action'] = 'reset';
        }
    }

    if ('reset' == $get_action) {
        if (reset_password()) {
            $page['action'] = 'reset_end';
        }
    }
}

// +-----------------------------------------------------------------------+
// | key and action                                                        |
// +-----------------------------------------------------------------------+

// a connected user can't reset the password from a mail
if (input_string('key', null, $_GET) !== null && !is_a_guest()) {
    unset($_GET['key']);
}

// read key after potential unset above (input_string reads $_GET live)
$get_key = input_string('key', null, $_GET);
if ($get_key !== null && input_string('submit', null, $_POST) === null) {
    $first_login = false;
    $user_id = check_password_reset_key($get_key);
    if (is_numeric($user_id)) {
        $userdata = getuserdata($user_id, false);
        $page['username'] = $userdata !== false ? $userdata['username'] : '';
        $template->assign('key', $get_key);
        $first_login = has_already_logged_in($user_id);

        if (!isset($page['action'])) {
            $page['action'] = 'reset';
        }
    } else {
        $page['action'] = 'none';
    }
}

if (!isset($page['action'])) {
    if ($get_action === null) {
        $page['action'] = 'lost';
    } elseif (in_array($get_action, ['lost', 'lost_code', 'reset', 'none'])) {
        $page['action'] = $get_action;
    }
}

if ('reset' == $page['action']) {
    if (($get_key === null and (is_a_guest() or is_generic())) and !isset($_SESSION['valid_reset_password_code'])) {
        redirect(get_gallery_home_url());
    }
}

if ('lost' == $page['action'] and !is_a_guest()) {
    redirect(get_gallery_home_url());
}

if ('lost_code' == $page['action'] and !isset($_SESSION['reset_password_code'])) {
    redirect(get_gallery_home_url(). 'identification.php');
}

if ('lost' == $page['action'] and isset($_SESSION['reset_password_code'])) {
    $page['action'] = 'lost_code';
}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+
$title = l10n('Password Reset');
if ('lost' == $page['action']) {
    $title = l10n('Forgot your password?');

    $post_uoe = input_string('username_or_email', null, $_POST);
    if ($post_uoe !== null) {
        $template->assign('username_or_email', htmlspecialchars(stripslashes($post_uoe)));
    }
} elseif ('reset' == $page['action'] and isset($first_login) and $first_login) {
    $title = l10n('Welcome');
    $template->assign('is_first_login', true);
}

$page['body_id'] = 'thePasswordPage';

$template->set_filenames(['password' => 'password.tpl']);
$template->assign(
    [
    'title' => $title,
    'form_action' => get_root_url().'password.php',
    'action' => $page['action'],
    'username' => $page['username'] ?? $user['username'],
    'PWG_TOKEN' => get_pwg_token(),
    ]
);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
if (!isset($themeconf['hide_menu_on']) or !in_array('thePasswordPage', $themeconf['hide_menu_on'])) {
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


// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

include(PHPWG_ROOT_PATH.'include/page_header.php');
trigger_notify('loc_end_password');
flush_page_messages();
$template->pparse('password');
include(PHPWG_ROOT_PATH.'include/page_tail.php');
