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
    $query = '
SELECT ' . implode(',', $fields) . '
  FROM ' . USER_INFOS_TABLE . '
  WHERE user_id = ' . $conf['default_user_id'] . '
;';
    $result = pwg_query($query);
    $default_user = pwg_db_fetch_assoc($result);
    $template->assign('DEFAULT_USER_VALUES', $default_user);

    // Reset to default (Guest) custom settings
    if (isset($_POST['reset_to_default'])) {
        $userdata = array_merge($userdata, $default_user);
    }

    save_profile_from_post($userdata, $page['errors']);

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
    if (! isset($themeconf['hide_menu_on']) or ! in_array('theProfilePage', $themeconf['hide_menu_on'])) {
        if ($themeconf['id'] !== 'standard_pages') {
            include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }
    }

    include PHPWG_ROOT_PATH . 'include/page_header.php';

    // Load language if cookie is set from login/register/password pages
    if (isset($_COOKIE['lang']) and $user['language'] != $_COOKIE['lang']) {
        if (! array_key_exists((string) $_COOKIE['lang'], get_languages())) {
            fatal_error('[Hacking attempt] the input parameter "' . $_COOKIE['lang'] . '" is not valid');
        }

        $user['language'] = $_COOKIE['lang'];
        single_update(
            USER_INFOS_TABLE,
            [
                'language' => $_COOKIE['lang'],
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
    if (str_starts_with((string) $user['language'], 'fr')) {
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
function save_profile_from_post(array $userdata, &$errors): bool
{
    global $conf, $page;
    $errors = [];

    if (! isset($_POST['validate'])) {
        return false;
    }

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
        if (empty($_POST['nb_image_page'])
            or (! preg_match($int_pattern, (string) $_POST['nb_image_page']))) {
            $errors[] = l10n('The number of photos per page must be a not null scalar');
        }

        // periods must be integer values, they represents number of days
        if (! preg_match($int_pattern, (string) $_POST['recent_period'])
            or $_POST['recent_period'] < 0) {
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
        $mail_error = validate_mail_address($userdata['id'], $_POST['mail_address']);
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
  SELECT ' . $conf['user_fields']['password'] . ' AS password
    FROM ' . USERS_TABLE . '
    WHERE ' . $conf['user_fields']['id'] . ' = \'' . $userdata['id'] . '\'
  ;';
            [$current_password] = pwg_db_fetch_row(pwg_query($query));

            if (! pwg_password_verify($_POST['password'], $current_password)) {
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
            $fields = [$conf['user_fields']['email']];

            $data = [];
            $data[$conf['user_fields']['id']] = $userdata['id'];
            $data[$conf['user_fields']['email']] = $_POST['mail_address'];

            // password is updated only if filled
            if (! empty($_POST['use_new_pwd'])) {
                $fields[] = $conf['user_fields']['password'];
                $data[$conf['user_fields']['password']] = pwg_password_hash($_POST['use_new_pwd']);

                deactivate_user_auth_keys($userdata['id']);
            }

            // username is updated only if allowed
            if (! empty($_POST['username'])) {
                if ($_POST['username'] != $userdata['username'] and get_userid($_POST['username'])) {
                    $page['errors'][] = l10n('this login is already used');
                    unset($_POST['redirect']);
                } else {
                    $fields[] = $conf['user_fields']['username'];
                    $data[$conf['user_fields']['username']] = $_POST['username'];

                    // send email to the user
                    if ($_POST['username'] != $userdata['username']) {
                        include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
                        switch_lang_to($userdata['language']);

                        $keyargs_content = [
                            get_l10n_args('Hello', ''),
                            get_l10n_args('Your username has been successfully changed to : %s', $_POST['username']),
                        ];

                        pwg_mail(
                            $_POST['mail_address'],
                            [
                                'subject' => '[' . $conf['gallery_title'] . '] ' . l10n('Username modification'),
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
                    'primary' => [$conf['user_fields']['id']],
                    'update' => $fields,
                ],
                [$data]
            );

            if ($_POST['mail_address'] != $userdata['email']) {
                deactivate_password_reset_key($userdata['id']);
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
        pwg_activity('user', $userdata['id'], 'edit', [
            'function' => __FUNCTION__,
            'tables' => implode(',', $activity_details_tables),
        ]);

        if (! empty($_POST['redirect'])) {
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
 */
function load_profile_in_template($url_action, $url_redirect, array $userdata, $template_prefixe = null): void
{
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
            $template_prefixe . 'USERNAME' => stripslashes((string) $userdata['username']),
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
    [$dbnow] = pwg_db_fetch_row(pwg_query('SELECT ADDDATE(NOW(), INTERVAL 1 DAY);'));
    $template->assign('API_CURRENT_DATE', explode(' ', (string) $dbnow)[0]);

    $duration = [];
    $display_duration = [];
    $has_custom = false;
    foreach ($conf['api_key_duration'] as $day) {
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
        $display_duration[$day] = l10n('%d days', $day) . ' (' . format_date($date, ['day', 'month', 'year']) . ')';
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
