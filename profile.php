<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
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

if (!defined('PHPWG_ROOT_PATH')) {//direct script access
    define('PHPWG_ROOT_PATH', './');
    include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
    \Piwigo\Core\Kernel::boot();

    // +-----------------------------------------------------------------------+
    // | Check Access and exit when user status is not ok                      |
    // +-----------------------------------------------------------------------+
    check_status(ACCESS_CLASSIC);

    if (!empty($_POST)) {
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
SELECT '.implode(',', $fields).'
  FROM '.USER_INFOS_TABLE.'
  WHERE user_id = '.\Piwigo\Core\Config::defaultUserId().'
;';
    $result = pwg_query($query);
    $default_user = pwg_db_fetch_assoc($result);
    $template->assign('DEFAULT_USER_VALUES', $default_user);

    // Reset to default (Guest) custom settings
    if (input_string('reset_to_default', null, $_POST) !== null) {
        $userdata = array_merge($userdata, $default_user ?? []);
    }

    save_profile_from_post($userdata, $page['errors']);

    $title = l10n('Your Gallery Customization');
    $page['body_id'] = 'theProfilePage';
    $template->set_filename('profile', 'profile.tpl');
    $template->set_filename('profile_content', 'profile_content.tpl');

    load_profile_in_template(
        get_root_url().'profile.php', // action
        make_index_url(), // for redirect
        $userdata
    );

    $special_user = in_array($userdata['id'], [\Piwigo\Core\Config::guestId(), \Piwigo\Core\Config::defaultUserId()]);
    $template->assign('page_data_json', json_encode([
        'canUpdatePreferences' => \Piwigo\Core\Config::allowUserCustomization(),
        'canUpdatePassword' => !$special_user,
        'can_manage_api' => 'pwg_ui' === ($_SESSION['connected_with'] ?? null),
        'user' => [
            'username' => stripslashes((string) $userdata['username']),
            'email' => (string) ($userdata['email'] ?? ''),
            'nb_image_page' => (string) ($userdata['nb_image_page'] ?? ''),
            'theme' => (string) ($userdata['theme'] ?? ''),
            'language' => (string) ($userdata['language'] ?? ''),
            'recent_period' => (string) ($userdata['recent_period'] ?? ''),
            'opt_album' => !empty($userdata['expand']),
            'opt_comment' => !empty($userdata['show_nb_comments']),
            'opt_hits' => !empty($userdata['show_nb_hits']),
        ],
        'preferencesDefaultValues' => [
            'nb_image_page' => $default_user['nb_image_page'] ?? null,
            'recent_period' => $default_user['recent_period'] ?? null,
            'opt_album' => !empty($default_user['expand'] ?? null),
            'opt_comment' => !empty($default_user['show_nb_comments'] ?? null),
            'opt_hits' => !empty($default_user['show_nb_hits'] ?? null),
        ],
        'standardSaveSelector' => [],
        'selected_date' => $template->get_template_vars('API_SELECTED_EXPIRATION') ?? '',
        'no_time_elapsed' => l10n('right now'),
        'str_handle_error' => l10n('An error has occured'),
        'str_copy_key_secret' => l10n('Secret copied. Keep it in a safe place.'),
        'str_copy_key_id' => l10n('ID copied.'),
        'str_api_edited' => l10n('API Key has been successfully edited.'),
        'str_api_revoked' => l10n('API Key has been successfully revoked.'),
        'str_api_added' => l10n('The api key has been successfully created.'),
        'str_revoke_key' => l10n('Do you really want to revoke the "%s" API key?'),
        'str_cant_copy' => l10n('Impossible to copy automatically. Please copy manually.'),
        'str_show_expired' => l10n('Show expired keys'),
        'str_hide_expired' => l10n('Hide expired keys'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

    $template->assign_var_from_handle('PROFILE_CONTENT', 'profile_content');



    // include menubar
    $themeconf = $template->get_template_vars('themeconf');
    if (!isset($themeconf['hide_menu_on']) or !in_array('theProfilePage', $themeconf['hide_menu_on'])) {
        if ($themeconf['id'] !== 'standard_pages') {
            include(PHPWG_ROOT_PATH.'include/menubar.inc.php');
        }
    }

    include(PHPWG_ROOT_PATH.'include/page_header.php');

    //Load language if cookie is set from login/register/password pages
    $cookie_lang = input_string('lang', null, $_COOKIE);
    if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
        if (!array_key_exists($cookie_lang, get_languages())) {
            fatal_error('[Hacking attempt] the input parameter "'.$cookie_lang.'" is not valid');
        }

        $user['language'] = $cookie_lang;
        single_update(
            USER_INFOS_TABLE,
            [
            'language' => $cookie_lang,
      ],
            [
            'user_id' => $user['id'],
      ]
        );

        load_language('common.lang', '', ['language' => $user['language']]);
    }

    //Get list of languages
    $language_options = [];
    foreach (get_languages() as $language_code => $language_name) {
        $language_options[$language_code] = $language_name;
    }

    $template->assign([
      'language_options' => $language_options,
      'language_selection' => $user['language'],
    ]);

    $template->assign('std_pages_data_json', json_encode([
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

    trigger_notify('loc_end_profile');
    flush_page_messages();
    $template->pparse('profile');
    include(PHPWG_ROOT_PATH.'include/page_tail.php');
}

//------------------------------------------------------ update & customization
/**
 * @param array<string,mixed> $userdata
 * @param string[] $errors
 */
function save_profile_from_post(array $userdata, array &$errors): bool
{
    global $page;
    $errors = [];

    if (!isset($_POST['validate'])) {
        return false;
    }

    $special_user = in_array($userdata['id'], [\Piwigo\Core\Config::guestId(), \Piwigo\Core\Config::defaultUserId()]);
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

    if (!defined('IN_ADMIN')) {
        unset($_POST['username']);
    }

    if (\Piwigo\Core\Config::allowUserCustomization() or defined('IN_ADMIN')) {
        $int_pattern = '/^\d+$/';
        if (empty($_POST['nb_image_page'])
            or (!preg_match($int_pattern, is_scalar($_POST['nb_image_page']) ? (string) $_POST['nb_image_page'] : ''))) {
            $errors[] = l10n('The number of photos per page must be a not null scalar');
        }

        // periods must be integer values, they represents number of days
        if (!preg_match($int_pattern, is_scalar($_POST['recent_period'] ?? null) ? (string) $_POST['recent_period'] : '')
            or (is_numeric($_POST['recent_period'] ?? null) ? $_POST['recent_period'] : 0) < 0) {
            $errors[] = l10n('Recent period must be a positive integer value') ;
        }

        if (!in_array($_POST['language'], array_keys(get_languages()))) {
            die('Hacking attempt, incorrect language value');
        }

        if (!in_array($_POST['theme'], array_keys(get_pwg_themes()))) {
            die('Hacking attempt, incorrect theme value');
        }
    }

    if (isset($_POST['mail_address'])) {
        // if $_POST and $userdata have are same email
        // validate_mail_address allows, however, to check email
        $mail_error = validate_mail_address(is_int($userdata['id'] ?? null) ? $userdata['id'] : null, is_string($_POST['mail_address']) ? $_POST['mail_address'] : null);
        if (!empty($mail_error)) {
            $errors[] = $mail_error;
        }
    }

    if (!empty($_POST['use_new_pwd'])) {
        // password must be the same as its confirmation
        if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
            $errors[] = l10n('The passwords do not match');
        }

        if (!defined('IN_ADMIN')) {// changing password requires old password
            $query = '
  SELECT '.\Piwigo\Core\Config::userFields()['password'].' AS password
    FROM '.USERS_TABLE.'
    WHERE '.\Piwigo\Core\Config::userFields()['id'].' = \''.(is_scalar($userdata['id'] ?? null) ? (int) $userdata['id'] : 0).'\'
  ;';
            [$current_password] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

            if (!\Piwigo\Core\Config::passwordVerify()($_POST['password'], $current_password)) {
                $errors[] = l10n('Current password is wrong');
            }
        }
    }

    if (count($errors) == 0) {
        // mass_updates function
        include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

        $activity_details_tables = [];

        if (isset($_POST['mail_address'])) {
            // update common user informations
            $fields = [\Piwigo\Core\Config::userFields()['email']];

            $data = [];
            $data[ \Piwigo\Core\Config::userFields()['id'] ] = $userdata['id'];
            $data[ \Piwigo\Core\Config::userFields()['email'] ] = $_POST['mail_address'];

            // password is updated only if filled
            if (!empty($_POST['use_new_pwd'])) {
                $fields[] = \Piwigo\Core\Config::userFields()['password'];
                // password is hashed with function \Piwigo\Core\Config::passwordHash()
                $data[ \Piwigo\Core\Config::userFields()['password'] ] = \Piwigo\Core\Config::passwordHash()($_POST['use_new_pwd']);

                deactivate_user_auth_keys(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
            }

            // username is updated only if allowed
            if (!empty($_POST['username'])) {
                if ($_POST['username'] != $userdata['username'] and get_userid(is_string($_POST['username']) ? $_POST['username'] : '')) {
                    \Piwigo\Core\PageState::current()->addError(l10n('this login is already used'));
                    unset($_POST['redirect']);
                } else {
                    $fields[] = \Piwigo\Core\Config::userFields()['username'];
                    $data[ \Piwigo\Core\Config::userFields()['username'] ] = $_POST['username'];

                    // send email to the user
                    if ($_POST['username'] != $userdata['username']) {
                        include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');
                        switch_lang_to(is_string($userdata['language'] ?? null) ? $userdata['language'] : '');

                        $keyargs_content = [
                          get_l10n_args('Hello', ''),
                          get_l10n_args('Your username has been successfully changed to : %s', $_POST['username']),
                          ];

                        pwg_mail(
                            is_string($_POST['mail_address']) ? $_POST['mail_address'] : '',
                            [
                            'subject' => '['.\Piwigo\Core\Config::galleryTitle().'] '.l10n('Username modification'),
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
                          'primary' => [\Piwigo\Core\Config::userFields()['id']],
                          'update' => $fields,
                          ],
                [$data]
            );

            if ($_POST['mail_address'] != $userdata['email']) {
                deactivate_password_reset_key(is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0);
            }

            $activity_details_tables[] = 'users';
        }

        if (\Piwigo\Core\Config::allowUserCustomization() or defined('IN_ADMIN')) {
            // update user "additional" informations (specific to Piwigo)
            $fields = [
              'nb_image_page', 'language',
              'expand', 'show_nb_hits', 'recent_period', 'theme',
              ];

            if (\Piwigo\Core\Config::activateComments()) {
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
                ['primary' => ['user_id'], 'update' => $fields],
                [$data]
            );

            $activity_details_tables[] = 'user_infos';
        }
        $userId = is_numeric($userdata['id'] ?? null) ? (int) $userdata['id'] : 0;
        trigger_notify('save_profile_from_post', $userId);
        pwg_activity('user', $userId, 'edit', ['function' => __FUNCTION__, 'tables' => implode(',', $activity_details_tables)]);

        if (!empty($_POST['redirect'])) {
            redirect(is_string($_POST['redirect']) ? $_POST['redirect'] : get_root_url());
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
/** @param array<string,mixed> $userdata */
function load_profile_in_template(string $url_action, string $url_redirect, array $userdata, ?string $template_prefixe = null): void
{
    global $template, $user;

    $template->assign(
        'radio_options',
        [
        'true' => l10n('Yes'),
        'false' => l10n('No')]
    );

    $template->assign(
        [
        $template_prefixe.'USERNAME' => stripslashes(is_scalar($userdata['username'] ?? null) ? (string) $userdata['username'] : ''),
        $template_prefixe.'EMAIL' => @$userdata['email'],
        $template_prefixe.'ALLOW_USER_CUSTOMIZATION' => \Piwigo\Core\Config::allowUserCustomization(),
        $template_prefixe.'ACTIVATE_COMMENTS' => \Piwigo\Core\Config::activateComments(),
        $template_prefixe.'NB_IMAGE_PAGE' => $userdata['nb_image_page'],
        $template_prefixe.'RECENT_PERIOD' => $userdata['recent_period'],
        $template_prefixe.'EXPAND' => $userdata['expand'] ? 'true' : 'false',
        $template_prefixe.'NB_COMMENTS' => $userdata['show_nb_comments'] ? 'true' : 'false',
        $template_prefixe.'NB_HITS' => $userdata['show_nb_hits'] ? 'true' : 'false',
        $template_prefixe.'REDIRECT' => $url_redirect,
        $template_prefixe.'F_ACTION' => $url_action,
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

    $special_user = in_array($userdata['id'], [\Piwigo\Core\Config::guestId(), \Piwigo\Core\Config::defaultUserId()]);
    $template->assign('SPECIAL_USER', $special_user);
    $template->assign('IN_ADMIN', defined('IN_ADMIN'));

    // api key expiration choice
    [$dbnow] = pwg_db_fetch_row(pwg_query('SELECT ADDDATE(NOW(), INTERVAL 1 DAY);')) ?? [null];
    $template->assign('API_CURRENT_DATE', explode(' ', (string) $dbnow)[0]);

    $duration = [];
    $display_duration = [];
    $has_custom = false;
    foreach (\Piwigo\Core\Config::apiKeyDuration() as $day) {
        if ('custom' === $day) {
            $has_custom = true;
            continue;
        }
        $dayStr = is_scalar($day) ? (string) $day : '0';
        $duration[] = 'ADDDATE(NOW(), INTERVAL '.$dayStr.' DAY) as `'.$dayStr.'`';
    }

    $query = '
SELECT
  '.implode(', ', $duration).'
;';
    $result = query2array($query)[0];
    foreach ($result as $day => $date) {
        $display_duration[ $day ] = l10n('%d days', $day) . ' (' . format_date((string)$date, ['day', 'month', 'year']) . ')';
    }

    if ($has_custom) {
        $display_duration['custom'] = l10n('Custom date');
    }
    $template->assign('API_EXPIRATION', $display_duration);
    $template->assign('API_SELECTED_EXPIRATION', array_key_first($display_duration));
    $template->assign('API_CAN_MANAGE', 'pwg_ui' ===  ($_SESSION['connected_with'] ?? null));

    $email_notifications_infos = $user['email'] ?
      l10n('The email <em>%s</em> will be used to notify you when your API key is about to expire.', $user['email'])
      : l10n('You have no email address, so you will not be notified when your API key is about to expire.');
    $template->assign('API_EMAIL_INFOS', $email_notifications_infos);


    // allow plugins to add their own form data to content
    trigger_notify('load_profile_in_template', $userdata);

    $template->assign('PWG_TOKEN', get_pwg_token());
}
