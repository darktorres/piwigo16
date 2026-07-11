<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\Tables;
use Piwigo\Template\Template;

// Bootstrap global, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;

// $conf['nbm_max_treatment_timeout_percent']/'nbm_treatment_timeout_default'
// are always numeric (config_default.inc.php: 0.8 and 20 respectively), but
// that isn't visible through $conf's own array<string, mixed> type.
$nbm_max_treatment_timeout_percent = $conf['nbm_max_treatment_timeout_percent'] ?? null;
$nbm_max_treatment_timeout_percent = is_numeric($nbm_max_treatment_timeout_percent) ? (float) $nbm_max_treatment_timeout_percent : 0.8;

/* nbm_global_var */
$env_nbm =
          [
            'start_time' => get_moment(),
              'sendmail_timeout' => (intval(ini_get('max_execution_time')) * $nbm_max_treatment_timeout_percent),
              'is_sendmail_timeout' => false,
        ];

if ($env_nbm['sendmail_timeout'] <= 0) {
    $nbm_treatment_timeout_default = $conf['nbm_treatment_timeout_default'] ?? null;
    $env_nbm['sendmail_timeout'] = is_numeric($nbm_treatment_timeout_default) ? (int) $nbm_treatment_timeout_default : 20;
}

/**
 * Search an available check_key
 * It's a copy of function find_available_feed_id
 * @return string nbm identifier
 */
function find_available_check_key(): string
{
    while (true) {
        $key = generate_key(16);
        $query = '
select
  count(*)
from
  ' . Tables::userMailNotification() . '
where
  check_key = \'' . $key . '\';';

        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$count] = $row;
        if ($count == 0) {
            return $key;
        }
    }
}

/**
 * Check sendmail timeout state
 * @return bool true if it's a timeout
 */
function check_sendmail_timeout()
{
    /** @var array<string, mixed> $env_nbm */
    global $env_nbm;

    $start_time = $env_nbm['start_time'] ?? null;
    $start_time = is_numeric($start_time) ? (float) $start_time : 0.0;
    $sendmail_timeout = $env_nbm['sendmail_timeout'] ?? null;
    $sendmail_timeout = is_numeric($sendmail_timeout) ? (float) $sendmail_timeout : 0.0;

    $is_timeout = ((get_moment() - $start_time) > $sendmail_timeout);
    $env_nbm['is_sendmail_timeout'] = $is_timeout;

    return $is_timeout;
}

/**
 * Add quote to all elements of check_key_list
 * @param array<int, mixed> $check_key_list
 * @return string[] quoted check key list
 */
function quote_check_key_list($check_key_list = []): array
{
    // $check_key_list may come straight from $_POST (see
    // admin/notification_by_mail.php), so its elements are only provably
    // string-castable once filtered.
    $string_check_keys = array_filter($check_key_list, is_string(...));

    return array_map(fn (string $s): string => '\'' . $s . '\'', $string_check_keys);
}

/**
 * Append a message to a $page message bucket (e.g. 'infos'/'errors'),
 * narrowing it to an array first if it isn't provably one yet ($page itself
 * is only known as array<string, mixed>, so $page[$key] is still mixed).
 *
 * @param array<string, mixed> $page
 */
function push_page_message(array &$page, string $key, string $message): void
{
    $list = $page[$key] ?? [];
    $list = is_array($list) ? $list : [];
    $list[] = $message;
    $page[$key] = $list;
}

/*
 * Execute all main queries to get list of user
 *
 * Type are the type of list 'subscribe', 'send'
 *
 * return array of users
 */
/**
 * @param string $action
 * @param array<int, mixed> $check_key_list
 * @param bool|string $enabled_filter_value
 * @return array<int, array<string, string|null>> nbm user rows, as fetched
 *         by pwg_db_fetch_assoc() (every column comes back as string|null)
 */
function get_user_notifications($action, $check_key_list = [], $enabled_filter_value = ''): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $data_users = [];

    if (in_array($action, ['subscribe', 'send'])) {
        $quoted_check_key_list = quote_check_key_list($check_key_list);
        if (count($quoted_check_key_list) != 0) {
            $query_and_check_key = ' and
    check_key in (' . implode(',', $quoted_check_key_list) . ') ';
        } else {
            $query_and_check_key = '';
        }

        // $conf['user_fields'] maps generic field names to table-specific
        // column names (see include/config_default.inc.php); its own value
        // type is only known as mixed, so each column name is narrowed to a
        // string before being concatenated into the query.
        $user_fields = $conf['user_fields'] ?? [];
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $username_field = $user_fields['username'] ?? 'username';
        $username_field = is_string($username_field) ? $username_field : 'username';
        $email_field = $user_fields['email'] ?? 'email';
        $email_field = is_string($email_field) ? $email_field : 'email';
        $id_field = $user_fields['id'] ?? 'id';
        $id_field = is_string($id_field) ? $id_field : 'id';

        $query = '
select
  N.user_id,
  N.check_key,
  U.' . $username_field . ' as username,
  U.' . $email_field . ' as mail_address,
  N.enabled,
  N.last_send,
  UI.status
from ' . Tables::userMailNotification() . ' as N
  JOIN ' . Tables::users() . ' as U on N.user_id =  U.' . $id_field . '
  JOIN ' . Tables::userInfos() . ' as UI on UI.user_id = N.user_id
where 1=1';

        if ($action == 'send') {
            // No mail empty and all users enabled
            $query .= ' and
  N.enabled = \'true\' and
  U.' . $email_field . ' is not null';
        }

        $query .= $query_and_check_key;

        if ($enabled_filter_value != '') {
            // boolean_to_string() is declared to return mixed (it echoes
            // back non-bool input unchanged); $enabled_filter_value is
            // bool|string here, so the result is always a string, but that
            // isn't visible through the helper's own signature.
            $enabled_filter_string = boolean_to_string($enabled_filter_value);
            $query .= ' and
        N.enabled = \'' . (is_string($enabled_filter_string) ? $enabled_filter_string : '') . '\'';
        }

        $query .= '
order by';

        if ($action == 'send') {
            $query .= '
  last_send, username;';
        } else {
            $query .= '
  username';
        }

        $query .= ';';

        $result = pwg_query($query);
        if (! empty($result)) {
            while ((bool) ($nbm_user = pwg_db_fetch_assoc($result))) {
                $data_users[] = $nbm_user;
            }
        }
    }
    return $data_users;
}

/*
 * Begin of use nbm environment
 * Prepare and save current environment and initialize data in order to send mail
 *
 * Return none
 */
function begin_users_env_nbm(bool $is_to_send_mail = false): void
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $env_nbm
     */
    global $user, $lang, $lang_info, $conf, $env_nbm;

    // Save $user, $lang_info and $lang arrays (include/user.inc.php has been executed)
    $env_nbm['save_user'] = $user;
    // Save current language to stack, necessary because $user change during NBM
    $user_language = $user['language'] ?? null;
    switch_lang_to(is_string($user_language) ? $user_language : get_default_language());

    $env_nbm['is_to_send_mail'] = $is_to_send_mail;

    if ($is_to_send_mail) {
        // Init mail configuration
        $env_nbm['email_format'] = get_str_email_format(get_boolean($conf['nbm_send_html_mail'] ?? false));

        // $conf['nbm_send_mail_as'] is admin-submitted free text (see
        // admin/notification_by_mail.php), always a string when set.
        $nbm_send_mail_as = $conf['nbm_send_mail_as'] ?? null;
        $send_as_name = (isset($nbm_send_mail_as) and ! empty($nbm_send_mail_as) and is_string($nbm_send_mail_as))
            ? $nbm_send_mail_as
            : get_mail_sender_name();
        $env_nbm['send_as_name'] = $send_as_name;

        $send_as_mail_address = get_webmaster_mail_address();
        $env_nbm['send_as_mail_address'] = $send_as_mail_address;

        $env_nbm['send_as_mail_formated'] = format_email($send_as_name, $send_as_mail_address);
        // Init mail counter
        $env_nbm['error_on_mail_count'] = 0;
        $env_nbm['sent_mail_count'] = 0;
        // Save sendmail message info and error in the original language
        $env_nbm['msg_info'] = l10n('Mail sent to %s [%s].');
        $env_nbm['msg_error'] = l10n('Error when sending email to %s [%s].');
    }
}

/*
 * End of use nbm environment
 * Restore environment
 *
 * Return none
 */
function end_users_env_nbm(): void
{
    /** @var array<string, mixed> $env_nbm */
    global $user, $lang, $lang_info, $env_nbm;

    // Restore $user, $lang_info and $lang arrays (include/user.inc.php has been executed)
    // save_user was set from $user (array<string, mixed>) in begin_users_env_nbm().
    $save_user = $env_nbm['save_user'] ?? [];
    $user = is_array($save_user) ? $save_user : [];
    // Restore current language to stack, necessary because $user change during NBM
    switch_lang_back();

    if ((bool) $env_nbm['is_to_send_mail']) {
        unset($env_nbm['email_format']);
        unset($env_nbm['send_as_name']);
        unset($env_nbm['send_as_mail_address']);
        unset($env_nbm['send_as_mail_formated']);
        // Don t unset counter
        // unset($env_nbm['error_on_mail_count']);
        // unset($env_nbm['sent_mail_count']);
        unset($env_nbm['msg_info']);
        unset($env_nbm['msg_error']);
    }

    unset($env_nbm['save_user']);
    unset($env_nbm['is_to_send_mail']);
}

/*
 * Set user on nbm enviromnent
 *
 * Return none
 */
/**
 * @param array<string, string|null> $nbm_user a get_user_notifications() row
 */
function set_user_on_env_nbm(array &$nbm_user, bool $is_action_send): void
{
    /** @var array<string, mixed> $env_nbm */
    global $user, $lang, $lang_info, $env_nbm;

    // user_id is Tables::userMailNotification()'s primary key (NOT NULL per
    // install/piwigo_structure-mysql.sql), always a non-null numeric value.
    $nbm_user_id_raw = $nbm_user['user_id'];
    assert(is_string($nbm_user_id_raw) && is_numeric($nbm_user_id_raw));
    $user = build_user((int) $nbm_user_id_raw, true);

    switch_lang_to(is_string($user['language']) ? $user['language'] : get_default_language());

    if ($is_action_send) {
        // email_format was set by begin_users_env_nbm(true), always a
        // string (get_str_email_format()'s own return type); that isn't
        // visible through $env_nbm's array<string, mixed> type.
        $email_format = $env_nbm['email_format'] ?? null;
        $email_format = is_string($email_format) ? $email_format : get_str_email_format(false);
        $mail_template = get_mail_template($email_format);
        $env_nbm['mail_template'] = $mail_template;
        $mail_template->set_filename('notification_by_mail', 'notification_by_mail.tpl');
    }
}

/*
 * Unset user on nbm enviromnent
 *
 * Return none
 */
function unset_user_on_env_nbm(): void
{
    /** @var array<string, mixed> $env_nbm */
    global $env_nbm;

    switch_lang_back();
    unset($env_nbm['mail_template']);
}

/*
 * Inc Counter success
 *
 * Return none
 */
/**
 * @param array<string, string|null> $nbm_user a get_user_notifications() row
 */
function inc_mail_sent_success(array $nbm_user): void
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $env_nbm
     */
    global $page, $env_nbm;

    $sent_mail_count = $env_nbm['sent_mail_count'] ?? 0;
    $sent_mail_count = is_numeric($sent_mail_count) ? (int) $sent_mail_count : 0;
    $env_nbm['sent_mail_count'] = $sent_mail_count + 1;

    // msg_info was set by begin_users_env_nbm(true) as a real l10n() string.
    $msg_info = $env_nbm['msg_info'] ?? null;
    $msg_info = is_string($msg_info) ? $msg_info : 'Mail sent to %s [%s].';
    push_page_message($page, 'infos', sprintf($msg_info, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
}

/*
 * Inc Counter failed
 *
 * Return none
 */
/**
 * @param array<string, string|null> $nbm_user a get_user_notifications() row
 */
function inc_mail_sent_failed(array $nbm_user): void
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $env_nbm
     */
    global $page, $env_nbm;

    $error_on_mail_count = $env_nbm['error_on_mail_count'] ?? 0;
    $error_on_mail_count = is_numeric($error_on_mail_count) ? (int) $error_on_mail_count : 0;
    $env_nbm['error_on_mail_count'] = $error_on_mail_count + 1;

    // msg_error was set by begin_users_env_nbm(true) as a real l10n() string.
    $msg_error = $env_nbm['msg_error'] ?? null;
    $msg_error = is_string($msg_error) ? $msg_error : 'Error when sending email to %s [%s].';
    push_page_message($page, 'errors', sprintf($msg_error, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
}

/*
 * Display Counter Info
 *
 * Return none
 */
function display_counter_info(): void
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $env_nbm
     */
    global $page, $env_nbm;

    $error_on_mail_count_raw = $env_nbm['error_on_mail_count'] ?? 0;
    $error_on_mail_count = is_numeric($error_on_mail_count_raw) ? (int) $error_on_mail_count_raw : 0;
    $sent_mail_count_raw = $env_nbm['sent_mail_count'] ?? 0;
    $sent_mail_count = is_numeric($sent_mail_count_raw) ? (int) $sent_mail_count_raw : 0;

    if ($error_on_mail_count != 0) {
        push_page_message($page, 'errors', l10n_dec(
            '%d mail was not sent.',
            '%d mails were not sent.',
            $error_on_mail_count
        ));

        if ($sent_mail_count != 0) {
            push_page_message($page, 'infos', l10n_dec(
                '%d mail was sent.',
                '%d mails were sent.',
                $sent_mail_count
            ));
        }
    } else {
        if ($sent_mail_count == 0) {
            push_page_message($page, 'infos', l10n('No mail to send.'));
        } else {
            push_page_message($page, 'infos', l10n_dec(
                '%d mail was sent.',
                '%d mails were sent.',
                $sent_mail_count
            ));
        }
    }
}

/**
 * @param array<string, string|null> $nbm_user a get_user_notifications() row
 */
function assign_vars_nbm_mail_content(array $nbm_user): void
{
    /** @var array<string, mixed> $env_nbm */
    global $env_nbm;

    set_make_full_url();

    // get_gallery_home_url() is declared to return mixed; real callers
    // always get back a string URL, but that isn't visible through its own
    // signature.
    $gallery_home_url = get_gallery_home_url();
    $gallery_home_url_str = is_string($gallery_home_url) ? $gallery_home_url : '';

    // mail_template was set by set_user_on_env_nbm(true), which is always
    // called right before this function in do_subscribe_unsubscribe_notification_by_mail();
    // the instanceof fallback mirrors the same pattern already used in
    // include/functions_mail.inc.php for the same kind of mixed-valued
    // cached-object array slot.
    $email_format = $env_nbm['email_format'] ?? null;
    $email_format = is_string($email_format) ? $email_format : get_str_email_format(false);
    $mail_template = $env_nbm['mail_template'] ?? null;
    $mail_template = $mail_template instanceof Template ? $mail_template : get_mail_template($email_format);

    $mail_template->assign(
        [
            'USERNAME' => stripslashes((string) $nbm_user['username']),

            'SEND_AS_NAME' => $env_nbm['send_as_name'],

            'UNSUBSCRIBE_LINK' => add_url_params($gallery_home_url_str . '/nbm.php', [
                'unsubscribe' => $nbm_user['check_key'],
            ]),
            'SUBSCRIBE_LINK' => add_url_params($gallery_home_url_str . '/nbm.php', [
                'subscribe' => $nbm_user['check_key'],
            ]),
            'CONTACT_EMAIL' => $env_nbm['send_as_mail_address'],
        ]
    );

    unset_make_full_url();
}

/**
 * Subscribe or unsubscribe notification by mail
 * is_subscribe define if action=subscribe or unsubscribe
 * check_key list where action will be done
 * @param bool $is_admin_request
 * @param bool $is_subscribe
 * @param array<int, mixed> $check_key_list
 * @return mixed[] check_key list treated
 */
function do_subscribe_unsubscribe_notification_by_mail($is_admin_request, $is_subscribe = false, $check_key_list = []): array
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     * @var array<string, mixed> $env_nbm
     */
    global $conf, $page, $env_nbm;

    set_make_full_url();

    // $conf['gallery_title'] is always a string (config_default.inc.php),
    // but that isn't visible through $conf's own array<string, mixed> type.
    $gallery_title = $conf['gallery_title'] ?? null;
    $gallery_title = is_string($gallery_title) ? $gallery_title : '';

    $check_key_treated = [];
    $updated_data_count = 0;
    $error_on_updated_data_count = 0;

    if ($is_subscribe) {
        $msg_info = l10n('User %s [%s] was added to the subscription list.');
        $msg_error = l10n('User %s [%s] was not added to the subscription list.');
    } else {
        $msg_info = l10n('User %s [%s] was removed from the subscription list.');
        $msg_error = l10n('User %s [%s] was not removed from the subscription list.');
    }

    if (count($check_key_list) != 0) {
        $updates = [];
        $enabled_value = boolean_to_string($is_subscribe);
        $data_users = get_user_notifications('subscribe', $check_key_list, ! $is_subscribe);

        // Prepare message after change language
        $msg_break_timeout = l10n('Time to send mail is limited. Others mails are skipped.');

        // Begin nbm users environment
        begin_users_env_nbm(true);

        foreach ($data_users as $nbm_user) {
            if (check_sendmail_timeout()) {
                // Stop fill list on 'send', if the quota is override
                push_page_message($page, 'errors', $msg_break_timeout);
                break;
            }

            // Fill return list
            $check_key_treated[] = $nbm_user['check_key'];

            $do_update = true;
            if ($nbm_user['mail_address'] != '') {
                // set env nbm user
                set_user_on_env_nbm($nbm_user, true);

                $subject = '[' . $gallery_title . '] ' . ($is_subscribe ? l10n('Subscribe to notification by mail') : l10n('Unsubscribe from notification by mail'));

                // Assign current var for nbm mail
                assign_vars_nbm_mail_content($nbm_user);

                $section_action_by = ($is_subscribe ? 'subscribe_by_' : 'unsubscribe_by_');
                $section_action_by .= ($is_admin_request ? 'admin' : 'himself');

                // mail_template/email_format were set by set_user_on_env_nbm(true)
                // just above; the instanceof fallback mirrors the pattern
                // already used in include/functions_mail.inc.php for the
                // same kind of mixed-valued cached-object array slot.
                $email_format = $env_nbm['email_format'] ?? null;
                $email_format = is_string($email_format) ? $email_format : get_str_email_format(false);
                $mail_template = $env_nbm['mail_template'] ?? null;
                $mail_template = $mail_template instanceof Template ? $mail_template : get_mail_template($email_format);

                $mail_template->assign(
                    [
                        $section_action_by => true,
                        'GOTO_GALLERY_TITLE' => $gallery_title,
                        'GOTO_GALLERY_URL' => get_gallery_home_url(),
                    ]
                );

                $send_as_mail_formated = $env_nbm['send_as_mail_formated'] ?? null;
                $send_as_mail_formated = is_string($send_as_mail_formated) ? $send_as_mail_formated : '';

                $ret = pwg_mail(
                    [
                        'name' => stripslashes((string) $nbm_user['username']),
                        'email' => $nbm_user['mail_address'],
                    ],
                    [
                        'from' => $send_as_mail_formated,
                        'subject' => $subject,
                        'email_format' => $email_format,
                        'content' => $mail_template->parse('notification_by_mail', true),
                        'content_format' => $email_format,
                    ]
                );

                if ($ret) {
                    inc_mail_sent_success($nbm_user);
                } else {
                    inc_mail_sent_failed($nbm_user);
                    $do_update = false;
                }

                // unset env nbm user
                unset_user_on_env_nbm();

            }

            if ($do_update) {
                $updates[] = [
                    'check_key' => $nbm_user['check_key'],
                    'enabled' => $enabled_value,
                ];
                ++$updated_data_count;
                push_page_message($page, 'infos', sprintf($msg_info, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
            } else {
                ++$error_on_updated_data_count;
                push_page_message($page, 'errors', sprintf($msg_error, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
            }

        }

        // Restore nbm environment
        end_users_env_nbm();

        display_counter_info();

        mass_updates(
            Tables::userMailNotification(),
            [
                'primary' => ['check_key'],
                'update' => ['enabled'],
            ],
            $updates
        );

    }

    push_page_message($page, 'infos', l10n_dec(
        '%d user was updated.',
        '%d users were updated.',
        $updated_data_count
    ));

    if ($error_on_updated_data_count != 0) {
        push_page_message($page, 'errors', l10n_dec(
            '%d user was not updated.',
            '%d users were not updated.',
            $error_on_updated_data_count
        ));
    }

    unset_make_full_url();

    return $check_key_treated;
}

/**
 * Unsubscribe notification by mail
 * check_key list where action will be done
 * @param bool $is_admin_request
 * @param array<int, mixed> $check_key_list
 * @return mixed[] check_key list treated
 */
function unsubscribe_notification_by_mail($is_admin_request, $check_key_list = []): array
{
    return do_subscribe_unsubscribe_notification_by_mail($is_admin_request, false, $check_key_list);
}

/**
 * Subscribe notification by mail
 * check_key list where action will be done
 * @param bool $is_admin_request
 * @param array<int, mixed> $check_key_list
 * @return mixed[] check_key list treated
 */
function subscribe_notification_by_mail($is_admin_request, $check_key_list = []): array
{
    return do_subscribe_unsubscribe_notification_by_mail($is_admin_request, true, $check_key_list);
}
