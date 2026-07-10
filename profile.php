<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// customize appearance of the site for a user
// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $template, $user;

if (! defined('PHPWG_ROOT_PATH')) {// direct script access
    define('PHPWG_ROOT_PATH', './');
    include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

    // +-----------------------------------------------------------------------+
    // | Check Access and exit when user status is not ok                      |
    // +-----------------------------------------------------------------------+
    check_status(ACCESS_CLASSIC);

    if (! empty($_POST)) {
        check_pwg_token();
    }

    $userdata = $user;

    trigger_notify('loc_begin_profile');

    $fields = [
        'nb_image_page', 'expand',
        'show_nb_comments', 'show_nb_hits', 'recent_period', 'show_nb_hits',
    ];

    // Get the Guest custom settings
    // $conf['default_user_id'] is always an int (see
    // include/config_default.inc.php: derived from the int guest_id).
    $default_user_id = is_numeric($conf['default_user_id']) ? (int) $conf['default_user_id'] : 0;
    $query = '
SELECT ' . implode(',', $fields) . '
  FROM ' . USER_INFOS_TABLE . '
  WHERE user_id = ' . $default_user_id . '
;';
    $result = pwg_query($query);
    $default_user = pwg_db_fetch_assoc($result);
    // The guest user_infos row can plausibly be missing (deleted directly in
    // DB, broken migration, ...); fall back to an empty array rather than
    // trusting a no-op assert() (zend.assertions=-1 in this environment --
    // see getuserdata() in functions_user.inc.php for the same "fetch may
    // fail" invariant handled with a real guard instead of assert()).
    $default_user = is_array($default_user) ? $default_user : [];
    $template->assign('DEFAULT_USER_VALUES', $default_user);

    // Reset to default (Guest) custom settings
    if (isset($_POST['reset_to_default'])) {
        $userdata = array_merge($userdata, $default_user);
    }

    /** @var array<string, mixed> $page */
    $page_errors = is_array($page['errors']) ? array_values(array_filter($page['errors'], 'is_string')) : [];
    save_profile_from_post($userdata, $page_errors);
    $page['errors'] = $page_errors;

    $title = l10n('Your Gallery Customization');
    $page['body_id'] = 'theProfilePage';
    $template->set_filename('profile', 'profile.tpl');
    $template->set_filename('profile_content', 'profile_content.tpl');

    load_profile_in_template(
        get_root_url() . 'profile.php', // action
        make_index_url(), // for redirect
        $userdata
    );
    $template->assign_var_from_handle('PROFILE_CONTENT', 'profile_content');

    // include menubar
    $themeconf = $template->get_template_vars('themeconf');
    $themeconf = is_array($themeconf) ? $themeconf : [];
    if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theProfilePage', $themeconf['hide_menu_on'])) {
        if ($themeconf['id'] !== 'standard_pages') {
            include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }
    }

    include PHPWG_ROOT_PATH . 'include/page_header.php';

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
        single_update(
            USER_INFOS_TABLE,
            [
                'language' => $lang_cookie,
            ],
            [
                'user_id' => $user['id'],
            ]
        );

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
        'language_selection' => $user['language'],
    ]);

    // Get link to doc
    // $user['language'] is always a language code string (from
    // get_default_language(), a cookie value already validated against
    // get_languages(), or a DB-persisted value); a non-string means no
    // language could be determined, so it degrades to the default link.
    $user_language = $user['language'];
    if (is_string($user_language) and str_starts_with($user_language, 'fr')) {
        $help_link = 'https://upstream.example.invalid/help/fr/';
    } else {
        $help_link = 'https://upstream.example.invalid/help/';
    }

    $template->assign('HELP_LINK', $help_link);

    trigger_notify('loc_end_profile');
    flush_page_messages();
    $template->pparse('profile');
    include PHPWG_ROOT_PATH . 'include/page_tail.php';
}

// ------------------------------------------------------ update & customization
/**
 * @param array<string, mixed> $userdata
 * @param array<int, string> $errors
 */
function save_profile_from_post(array $userdata, &$errors): bool
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     */
    global $conf, $page;
    $errors = [];

    if (! isset($_POST['validate'])) {
        return false;
    }

    // $userdata['id'] is always the current session user's numeric id
    // (built in include/user.inc.php from $conf['guest_id'] or
    // $_SESSION['pwg_uid'], never a raw untyped value); narrow once here
    // for reuse below.
    $user_id = is_numeric($userdata['id']) ? (int) $userdata['id'] : 0;

    // $conf['user_fields'] maps generic field names to table-specific DB
    // column names (see include/config_default.inc.php); always a
    // string=>string map at runtime (same invariant documented in
    // validate_mail_address(), functions_user.inc.php).
    /** @var array<string, string> $user_fields */
    $user_fields = $conf['user_fields'];

    $special_user = in_array($userdata['id'], [$conf['guest_id'], $conf['default_user_id']]);
    if ($special_user) {
        unset(
            $_POST['username'],
            $_POST['mail_address'],
            $_POST['password'],
            $_POST['use_new_pwd'],
            $_POST['passwordConf'],
            $_POST['theme'],
            $_POST['language']
        );
        $_POST['theme'] = get_default_theme();
        $_POST['language'] = get_default_language();
    }

    if (! defined('IN_ADMIN')) {
        unset($_POST['username']);
    }

    if ($conf['allow_user_customization'] or defined('IN_ADMIN')) {
        $int_pattern = '/^\d+$/';
        $nb_image_page = $_POST['nb_image_page'] ?? null;
        if (empty($nb_image_page)
            or (! is_scalar($nb_image_page))
            or (! preg_match($int_pattern, (string) $nb_image_page))) {
            $errors[] = l10n('The number of photos per page must be a not null scalar');
        }

        // periods must be integer values, they represents number of days
        $recent_period = $_POST['recent_period'] ?? null;
        if (! is_scalar($recent_period)
            or ! preg_match($int_pattern, (string) $recent_period)
            or $recent_period < 0) {
            $errors[] = l10n('Recent period must be a positive integer value');
        }

        if (! in_array($_POST['language'], array_keys(get_languages()))) {
            die('Hacking attempt, incorrect language value');
        }

        if (! in_array($_POST['theme'], array_keys(get_pwg_themes()))) {
            die('Hacking attempt, incorrect theme value');
        }
    }

    if (isset($_POST['mail_address'])) {
        // if $_POST and $userdata have are same email
        // validate_mail_address allows, however, to check email
        $mail_address_input = is_string($_POST['mail_address']) ? $_POST['mail_address'] : null;
        $mail_error = validate_mail_address($user_id, $mail_address_input);
        if (! empty($mail_error)) {
            $errors[] = $mail_error;
        }
    }

    if (! empty($_POST['use_new_pwd'])) {
        // password must be the same as its confirmation
        if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
            $errors[] = l10n('The passwords do not match');
        }

        if (! defined('IN_ADMIN')) {// changing password requires old password
            $query = '
  SELECT ' . $user_fields['password'] . ' AS password
    FROM ' . USERS_TABLE . '
    WHERE ' . $user_fields['id'] . ' = \'' . $user_id . '\'
  ;';
            $row = pwg_db_fetch_row(pwg_query($query));
            assert($row !== null);
            [$current_password] = $row;

            // the password column allows NULL (external-authentication
            // accounts with no local password set); such an account can
            // never verify against a supplied old password
            $password_input = $_POST['password'] ?? null;
            if (! is_string($current_password)
                or ! is_string($password_input)
                or ! pwg_password_verify($password_input, $current_password)) {
                $errors[] = l10n('Current password is wrong');
            }
        }
    }

    if (count($errors) == 0) {
        // mass_updates function
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $activity_details_tables = [];

        if (isset($_POST['mail_address'])) {
            // update common user informations
            $fields = [$user_fields['email']];
            $mail_address = is_string($_POST['mail_address']) ? $_POST['mail_address'] : '';

            $data = [];
            $data[$user_fields['id']] = $userdata['id'];
            $data[$user_fields['email']] = $mail_address;

            // password is updated only if filled
            if (! empty($_POST['use_new_pwd']) and is_string($_POST['use_new_pwd'])) {
                $fields[] = $user_fields['password'];
                $data[$user_fields['password']] = pwg_password_hash($_POST['use_new_pwd']);

                deactivate_user_auth_keys($user_id);
            }

            // username is updated only if allowed
            if (! empty($_POST['username']) and is_string($_POST['username'])) {
                $username = $_POST['username'];
                if ($username != $userdata['username'] and get_userid($username)) {
                    if (! is_array($page['errors'])) {
                        $page['errors'] = [];
                    }
                    $page['errors'][] = l10n('this login is already used');
                    unset($_POST['redirect']);
                } else {
                    $fields[] = $user_fields['username'];
                    $data[$user_fields['username']] = $username;

                    // send email to the user
                    if ($username != $userdata['username']) {
                        include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
                        $notification_language = is_string($userdata['language']) ? $userdata['language'] : get_default_language();
                        switch_lang_to($notification_language);

                        $keyargs_content = [
                            get_l10n_args('Hello', ''),
                            get_l10n_args('Your username has been successfully changed to : %s', $username),
                        ];

                        $gallery_title = is_string($conf['gallery_title']) ? $conf['gallery_title'] : '';
                        pwg_mail(
                            $mail_address,
                            [
                                'subject' => '[' . $gallery_title . '] ' . l10n('Username modification'),
                                'content' => l10n_args($keyargs_content),
                                'content_format' => 'text/plain',
                            ]
                        );

                        switch_lang_back();
                    }
                }
            }

            mass_updates(
                USERS_TABLE,
                [
                    'primary' => [$user_fields['id']],
                    'update' => $fields,
                ],
                [$data]
            );

            if ($mail_address != $userdata['email']) {
                deactivate_password_reset_key($user_id);
            }

            $activity_details_tables[] = 'users';
        }

        if ($conf['allow_user_customization'] or defined('IN_ADMIN')) {
            // update user "additional" informations (specific to Piwigo)
            $fields = [
                'nb_image_page', 'language',
                'expand', 'show_nb_hits', 'recent_period', 'theme',
            ];

            if ($conf['activate_comments']) {
                $fields[] = 'show_nb_comments';
            }

            $data = [];
            $data['user_id'] = $userdata['id'];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $data[$field] = $_POST[$field];
                }
            }
            mass_updates(
                USER_INFOS_TABLE,
                [
                    'primary' => ['user_id'],
                    'update' => $fields,
                ],
                [$data]
            );

            $activity_details_tables[] = 'user_infos';
        }
        trigger_notify('save_profile_from_post', $userdata['id']);
        pwg_activity('user', $user_id, 'edit', [
            'function' => __FUNCTION__,
            'tables' => implode(',', $activity_details_tables),
        ]);

        if (! empty($_POST['redirect']) and is_string($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }
    }
    return true;
}

/**
 * Assign template variables, from arguments
 * Used to build profile edition pages
 *
 * @param string $url_action
 * @param string $url_redirect
 * @param array<string, mixed> $userdata
 */
function load_profile_in_template($url_action, $url_redirect, array $userdata, ?string $template_prefixe = null): void
{
    /**
     * @var \Template $template
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     */
    global $template, $conf, $user;

    $template->assign(
        'radio_options',
        [
            'true' => l10n('Yes'),
            'false' => l10n('No'),
        ]
    );

    $template->assign(
        [
            $template_prefixe . 'USERNAME' => stripslashes(is_string($userdata['username']) ? $userdata['username'] : ''),
            $template_prefixe . 'EMAIL' => @$userdata['email'],
            $template_prefixe . 'ALLOW_USER_CUSTOMIZATION' => $conf['allow_user_customization'],
            $template_prefixe . 'ACTIVATE_COMMENTS' => $conf['activate_comments'],
            $template_prefixe . 'NB_IMAGE_PAGE' => $userdata['nb_image_page'],
            $template_prefixe . 'RECENT_PERIOD' => $userdata['recent_period'],
            $template_prefixe . 'EXPAND' => $userdata['expand'] ? 'true' : 'false',
            $template_prefixe . 'NB_COMMENTS' => $userdata['show_nb_comments'] ? 'true' : 'false',
            $template_prefixe . 'NB_HITS' => $userdata['show_nb_hits'] ? 'true' : 'false',
            $template_prefixe . 'REDIRECT' => $url_redirect,
            $template_prefixe . 'F_ACTION' => $url_action,
        ]
    );

    $template->assign('template_selection', $userdata['theme']);
    $template->assign('template_options', get_pwg_themes());

    $language_options = [];
    foreach (get_languages() as $language_code => $language_name) {
        if (isset($_POST['submit']) or $userdata['language'] == $language_code) {
            $template->assign('language_selection', $language_code);
        }
        $language_options[$language_code] = $language_name;
    }

    $template->assign('language_options', $language_options);

    $special_user = in_array($userdata['id'], [$conf['guest_id'], $conf['default_user_id']]);
    $template->assign('SPECIAL_USER', $special_user);
    $template->assign('IN_ADMIN', defined('IN_ADMIN'));

    // api key expiration choice
    $row = pwg_db_fetch_row(pwg_query('SELECT ADDDATE(NOW(), INTERVAL 1 DAY);'));
    assert($row !== null);
    [$dbnow] = $row;
    $template->assign('API_CURRENT_DATE', explode(' ', (string) $dbnow)[0]);

    $duration = [];
    $display_duration = [];
    $has_custom = false;
    // $conf['api_key_duration'] is a plain list of day-count strings (plus
    // the literal 'custom' sentinel) -- see include/config_default.inc.php.
    $api_key_duration = is_array($conf['api_key_duration']) ? array_filter($conf['api_key_duration'], 'is_string') : [];
    foreach ($api_key_duration as $day) {
        if ($day === 'custom') {
            $has_custom = true;
            continue;
        }
        $duration[] = 'ADDDATE(NOW(), INTERVAL ' . $day . ' DAY) as `' . $day . '`';
    }

    $query = '
SELECT
  ' . implode(', ', $duration) . '
;';
    $result = query2array($query)[0];
    foreach ($result as $day => $date) {
        $display_duration[$day] = l10n('%d days', $day) . ' (' . format_date($date ?? false, ['day', 'month', 'year']) . ')';
    }

    if ($has_custom) {
        $display_duration['custom'] = l10n('Custom date');
    }
    $template->assign('API_EXPIRATION', $display_duration);
    $template->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
    $template->assign('API_CAN_MANAGE', 'pwg_ui' === ($_SESSION['connected_with'] ?? null));

    $email_notifications_infos = $user['email'] ?
      l10n('The email <em>%s</em> will be used to notify you when your API key is about to expire.', $user['email'])
      : l10n('You have no email address, so you will not be notified when your API key is about to expire.');
    $template->assign('API_EMAIL_INFOS', $email_notifications_infos);

    // allow plugins to add their own form data to content
    trigger_notify('load_profile_in_template', $userdata);

    $template->assign('PWG_TOKEN', get_pwg_token());
}
