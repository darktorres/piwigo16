<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// | include                                                               |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var Template $template
 */
global $conf, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions_notification_by_mail.inc.php';
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_notification.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Administrator);

check_input_parameter('mode', $_GET, false, '/^(param|subscribe|send)$/');

// +-----------------------------------------------------------------------+
// | Initialization                                                        |
// +-----------------------------------------------------------------------+
$base_url = get_root_url() . 'admin.php';
$must_repost = false;

// +-----------------------------------------------------------------------+
// | functions                                                             |
// +-----------------------------------------------------------------------+

/**
 * Do timeout treatment in order to finish to send mails
 * @param $post_keyname: key of check_key post array
 * @param array<int, mixed> $check_key_treated: array of check_key treated
 * @return bool whether treatment timed out and must be reposted
 */
function do_timeout_treatment(string $post_keyname, array $check_key_treated = []): bool
{
    /**
     * @var array<string, mixed> $env_nbm
     * @var array<string, mixed> $page
     */
    global $env_nbm, $page;

    if ((bool) $env_nbm['is_sendmail_timeout']) {
        if (isset($_POST[$post_keyname]) and is_array($_POST[$post_keyname])) {
            $post_count = count($_POST[$post_keyname]);
            $treated_count = count($check_key_treated);
            if ($treated_count != 0) {
                // start_time is set (via get_moment(), always float) by the
                // nbm_global_var bootstrap in
                // admin/include/functions_notification_by_mail.inc.php.
                $start_time = $env_nbm['start_time'];
                $start_time_num = is_numeric($start_time) ? (float) $start_time : 0.0;
                $time_refresh = (int) ceil((get_moment() - $start_time_num) * $post_count / $treated_count);
            } else {
                $time_refresh = 0;
            }
            // $check_key_treated is check_key strings returned by the nbm
            // helpers (typed mixed[] there); keep only the string ones so
            // array_diff() gets values it can actually compare.
            $check_key_treated_strings = array_filter($check_key_treated, is_string(...));
            $_POST[$post_keyname] = array_diff(array_filter($_POST[$post_keyname], is_string(...)), $check_key_treated_strings);

            push_page_message($page, 'errors', l10n_dec(
                'Execution time is out, treatment must be continue [Estimated time: %d second].',
                'Execution time is out, treatment must be continue [Estimated time: %d seconds].',
                $time_refresh
            ));
            return true;
        }
    }

    return false;
}

/*
 * Get the authorized_status for each tab
 * return corresponding status
 */
function get_tab_status(string $mode): int
{
    $result = AccessLevel::Webmaster;
    $result = match ($mode) {
        'param', 'subscribe' => AccessLevel::Webmaster,
        'send' => AccessLevel::Administrator,
        default => AccessLevel::Webmaster,
    };
    return $result;
}

/*
 * Inserting News users
 */
function insert_new_data_user_mail_notification(): void
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     * @var array<string, mixed> $env_nbm
     * @var string $base_url set at the top of this file
     */
    global $conf, $page, $env_nbm, $base_url;

    // user_fields maps generic field names to table-specific column names
    // (see include/config_default.inc.php); every value is a plain string.
    $user_fields_raw = $conf['user_fields'];
    $user_fields = [];
    if (is_array($user_fields_raw)) {
        foreach ($user_fields_raw as $field_key => $field_value) {
            if (is_string($field_key) and is_string($field_value)) {
                $user_fields[$field_key] = $field_value;
            }
        }
    }
    $user_field_email = $user_fields['email'] ?? 'mail_address';
    $user_field_id = $user_fields['id'] ?? 'id';
    $user_field_username = $user_fields['username'] ?? 'username';

    // Set null mail_address empty
    $query = '
update
  ' . Tables::users() . '
set
  ' . $user_field_email . ' = null
where
  trim(' . $user_field_email . ') = \'\';';
    pwg_query($query);

    // null mail_address are not selected in the list
    $query = '
select
  u.' . $user_field_id . ' as user_id,
  u.' . $user_field_username . ' as username,
  u.' . $user_field_email . ' as mail_address
from
  ' . Tables::users() . ' as u left join ' . Tables::userMailNotification() . ' as m on u.' . $user_field_id . ' = m.user_id
where
  u.' . $user_field_email . ' is not null and
  m.user_id is null
order by
  user_id;';

    $result = pwg_query($query);

    if (pwg_db_num_rows($result) > 0) {
        $inserts = [];
        $check_key_list = [];

        while ((bool) ($nbm_user = pwg_db_fetch_assoc($result))) {
            // Calculate key
            $nbm_user['check_key'] = find_available_check_key();

            // Save key
            $check_key_list[] = $nbm_user['check_key'];

            // Insert new nbm_users
            $inserts[] = [
                'user_id' => $nbm_user['user_id'],
                'check_key' => $nbm_user['check_key'],
                'enabled' => 'false', // By default if false, set to true with specific functions
            ];

            push_page_message($page, 'infos', l10n(
                'User %s [%s] added.',
                stripslashes((string) $nbm_user['username']),
                $nbm_user['mail_address']
            ));
        }

        // Insert new nbm_users
        mass_inserts(Tables::userMailNotification(), ['user_id', 'check_key', 'enabled'], $inserts);
        // Update field enabled with specific function
        $check_key_treated = do_subscribe_unsubscribe_notification_by_mail(
            true,
            (bool) $conf['nbm_default_value_user_enabled'],
            $check_key_list
        );

        // On timeout simulate like tabsheet send
        if ((bool) $env_nbm['is_sendmail_timeout']) {
            // do_subscribe_unsubscribe_notification_by_mail() returns mixed[]
            // of check_key strings; narrow before array_diff() needs values
            // castable to string.
            $check_key_treated_strings = array_filter($check_key_treated, is_string(...));
            $quoted_check_key_list = quote_check_key_list(array_diff($check_key_list, $check_key_treated_strings));
            if (count($quoted_check_key_list) != 0) {
                $query = 'delete from ' . Tables::userMailNotification() . ' where check_key in (' . implode(',', $quoted_check_key_list) . ');';
                $result = pwg_query($query);

                redirect($base_url . get_query_string_diff([], false), l10n('Operation in progress') . "\n" . l10n('Please wait...'));
            }
        }
    }
}

/*
 * Apply global functions to mail content
 * return customize mail content rendered
 */
function render_global_customize_mail_content(mixed $customize_mail_content): mixed
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // Real callers always pass a string (config value or POSTed content);
    // this handler is registered as a generic event filter (mixed in/out),
    // so narrow defensively rather than blindly casting a possibly
    // non-scalar value.
    $customize_mail_content_str = is_string($customize_mail_content) ? $customize_mail_content : '';

    if ((bool) $conf['nbm_send_html_mail'] and ! str_starts_with($customize_mail_content_str, '<')) {
        // On HTML mail, detects if the content are HTML format.
        // If it's plain text format, convert content to readable HTML
        return nl2br(htmlspecialchars($customize_mail_content_str));
    } else {
        return $customize_mail_content;
    }
}

/*
 * Send mail for notification to all users
 * Return list of "selected" users for 'list_to_send'
 * Return list of "treated" check_key for 'send'
 */
/**
 * @param array<int, mixed> $check_key_list
 * @param mixed $customize_mail_content
 * @return array<int, mixed>
 */
function do_action_send_mail_notification(string $action = 'list_to_send', array $check_key_list = [], $customize_mail_content = ''): array
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     * @var array<string, mixed> $env_nbm
     */
    global $conf, $page, $user, $lang_info, $lang, $env_nbm;
    $return_list = [];

    if (in_array($action, ['list_to_send', 'send'])) {
        $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
        assert($row !== null);
        [$dbnow] = $row;

        $is_action_send = ($action == 'send');

        // disabled and null mail_address are not selected in the list
        $data_users = get_user_notifications('send', $check_key_list);

        // List all if it's define on options or on timeout
        $is_list_all_without_test = ((bool) $env_nbm['is_sendmail_timeout'] or (bool) $conf['nbm_list_all_enabled_users_to_send']);

        // Check if exist news to list user or send mails
        if ((! $is_list_all_without_test) or ($is_action_send)) {
            if (count($data_users) > 0) {
                $datas = [];

                if (! isset($customize_mail_content)) {
                    $customize_mail_content = $conf['nbm_complementary_mail_content'];
                }

                $customize_mail_content =
                  trigger_change('nbm_render_global_customize_mail_content', $customize_mail_content);

                // Prepare message after change language
                if ($is_action_send) {
                    $msg_break_timeout = l10n('Time to send mail is limited. Others mails are skipped.');
                } else {
                    $msg_break_timeout = l10n('Prepared time for list of users to send mail is limited. Others users are not listed.');
                }

                // Begin nbm users environment
                begin_users_env_nbm($is_action_send);

                foreach ($data_users as $nbm_user) {
                    if ((! $is_action_send) and check_sendmail_timeout()) {
                        // Stop fill list on 'list_to_send', if the quota is override
                        push_page_message($page, 'infos', $msg_break_timeout);
                        break;
                    }
                    if (($is_action_send) and check_sendmail_timeout()) {
                        // Stop fill list on 'send', if the quota is override
                        push_page_message($page, 'errors', $msg_break_timeout);
                        break;
                    }

                    // set env nbm user
                    set_user_on_env_nbm($nbm_user, $is_action_send);
                    // set_user_on_env_nbm()'s by-ref $nbm_user param is only
                    // typed array<string, mixed>, so the narrowing above is
                    // lost across the call -- restate it.
                    /** @var array<string, string|null> $nbm_user */
                    if ($is_action_send) {
                        $auth = null;
                        $add_url_params = [];

                        // user_id is the joined users.id column, always a
                        // non-null numeric DB value.
                        $nbm_user_id_raw = $nbm_user['user_id'];
                        assert(is_string($nbm_user_id_raw) && is_numeric($nbm_user_id_raw));
                        $auth_key = create_user_auth_key((int) $nbm_user_id_raw, $nbm_user['status']);

                        if ($auth_key !== false and is_string($auth_key['auth_key'])) {
                            $auth = $auth_key['auth_key'];
                            $add_url_params['auth'] = $auth;
                        }

                        set_make_full_url();
                        // Fill return list of "treated" check_key for 'send'
                        $return_list[] = $nbm_user['check_key'];

                        // These nbm_* flags are plain booleans in
                        // include/config_default.inc.php; (bool) is always a
                        // safe, non-failing cast for a mixed config value.
                        $nbm_send_detailed_content = (bool) $conf['nbm_send_detailed_content'];
                        $nbm_send_html_mail = (bool) $conf['nbm_send_html_mail'];

                        if ($nbm_send_detailed_content) {
                            $news = news($nbm_user['last_send'], $dbnow, false, $nbm_send_html_mail, $auth);
                            $exist_data = count($news) > 0;
                        } else {
                            $exist_data = news_exists($nbm_user['last_send'], $dbnow);
                        }

                        if ($exist_data) {
                            // gallery_title is always a configured string
                            // (see include/config_default.inc.php).
                            $gallery_title = is_string($conf['gallery_title']) ? $conf['gallery_title'] : '';
                            $subject = '[' . $gallery_title . '] ' . l10n('New photos added');

                            // email_format/mail_template were set by
                            // begin_users_env_nbm($is_action_send) and
                            // set_user_on_env_nbm($nbm_user, true) a few lines
                            // above, which are always called (in that order)
                            // before this branch can be reached; the
                            // instanceof/is_string fallback mirrors the same
                            // pattern used in assign_vars_nbm_mail_content()
                            // for the same kind of mixed-valued cached-object
                            // array slot.
                            $mail_email_format = $env_nbm['email_format'] ?? null;
                            $mail_email_format = is_string($mail_email_format) ? $mail_email_format : get_str_email_format($nbm_send_html_mail);
                            $mail_template = $env_nbm['mail_template'] ?? null;
                            $mail_template = $mail_template instanceof Template ? $mail_template : get_mail_template($mail_email_format);

                            // Assign current var for nbm mail
                            assign_vars_nbm_mail_content($nbm_user);

                            if ($nbm_user['last_send'] !== null) {
                                $mail_template->assign(
                                    'content_new_elements_between',
                                    [
                                        'DATE_BETWEEN_1' => $nbm_user['last_send'],
                                        'DATE_BETWEEN_2' => $dbnow,
                                    ]
                                );
                            } else {
                                $mail_template->assign(
                                    'content_new_elements_single',
                                    [
                                        'DATE_SINGLE' => $dbnow,
                                    ]
                                );
                            }

                            if ($nbm_send_detailed_content) {
                                $mail_template->assign('global_new_lines', $news);
                            }

                            $nbm_user_customize_mail_content =
                              trigger_change(
                                  'nbm_render_user_customize_mail_content',
                                  $customize_mail_content,
                                  $nbm_user
                              );
                            if (! empty($nbm_user_customize_mail_content)) {
                                $mail_template->assign(
                                    'custom_mail_content',
                                    $nbm_user_customize_mail_content
                                );
                            }

                            $nbm_send_recent_post_dates = (bool) $conf['nbm_send_recent_post_dates'];
                            if ($nbm_send_html_mail and $nbm_send_recent_post_dates) {
                                // recent_post_dates is a config array of
                                // per-notification-kind int settings (see
                                // include/config_default.inc.php); narrow the
                                // 'NBM' entry to the array<string, int> shape
                                // get_recent_post_dates_array() expects.
                                $recent_post_dates_conf = $conf['recent_post_dates'];
                                $nbm_recent_post_dates_raw = (is_array($recent_post_dates_conf) and is_array($recent_post_dates_conf['NBM'] ?? null))
                                    ? $recent_post_dates_conf['NBM']
                                    : [];
                                $nbm_recent_post_dates_args = [];
                                foreach ($nbm_recent_post_dates_raw as $arg_key => $arg_value) {
                                    if (is_string($arg_key) and is_int($arg_value)) {
                                        $nbm_recent_post_dates_args[$arg_key] = $arg_value;
                                    }
                                }

                                $recent_post_dates = get_recent_post_dates_array($nbm_recent_post_dates_args);
                                foreach ($recent_post_dates as $date_detail) {
                                    // get_recent_post_dates_array() is typed to
                                    // return array<int|string, mixed>; each
                                    // element is really one get_recent_post_dates()
                                    // date-detail row (array<string, mixed>).
                                    assert(is_array($date_detail));
                                    /** @var array<string, mixed> $date_detail */
                                    $mail_template->append(
                                        'recent_posts',
                                        [
                                            'TITLE' => get_title_recent_post_date($date_detail),
                                            'HTML_DATA' => get_html_description_recent_post_date($date_detail, $auth),
                                        ]
                                    );
                                }
                            }

                            $gallery_home_url = get_gallery_home_url();
                            $mail_template->assign(
                                [
                                    'GOTO_GALLERY_TITLE' => $gallery_title,
                                    'GOTO_GALLERY_URL' => add_url_params(is_string($gallery_home_url) ? $gallery_home_url : '', $add_url_params),
                                    'SEND_AS_NAME' => $env_nbm['send_as_name'],
                                ]
                            );

                            $mail_args = [
                                'from' => $env_nbm['send_as_mail_formated'],
                                'subject' => $subject,
                                'email_format' => $mail_email_format,
                                'content' => $mail_template->parse('notification_by_mail', true),
                                'content_format' => $mail_email_format,
                            ];
                            if (is_string($auth)) {
                                $mail_args['auth_key'] = $auth;
                            }

                            $ret = pwg_mail(
                                [
                                    'name' => stripslashes((string) $nbm_user['username']),
                                    'email' => $nbm_user['mail_address'],
                                ],
                                $mail_args
                            );

                            if ($ret) {
                                inc_mail_sent_success($nbm_user);

                                $datas[] = [
                                    'user_id' => $nbm_user['user_id'],
                                    'last_send' => $dbnow,
                                ];
                            } else {
                                inc_mail_sent_failed($nbm_user);
                            }

                            unset_make_full_url();
                        }
                    } else {
                        if (news_exists($nbm_user['last_send'], $dbnow)) {
                            // Fill return list of "selected" users for 'list_to_send'
                            $return_list[] = $nbm_user;
                        }
                    }

                    // unset env nbm user
                    unset_user_on_env_nbm();
                }

                // Restore nbm environment
                end_users_env_nbm();

                if ($is_action_send) {
                    mass_updates(
                        Tables::userMailNotification(),
                        [
                            'primary' => ['user_id'],
                            'update' => ['last_send'],
                        ],
                        $datas
                    );

                    display_counter_info();
                }
            } else {
                if ($is_action_send) {
                    push_page_message($page, 'errors', l10n('No user to send notifications by mail.'));
                }
            }
        } else {
            // Quick List, don't check news
            // Fill return list of "selected" users for 'list_to_send'
            $return_list = $data_users;
        }
    }

    // Return list of "selected" users for 'list_to_send'
    // Return list of "treated" check_key for 'send'
    return $return_list;
}

// +-----------------------------------------------------------------------+
// | Main                                                                  |
// +-----------------------------------------------------------------------+
if (! isset($_GET['mode']) or ! is_string($_GET['mode'])) {
    // check_input_parameter() above already fatal_error()s on a non-scalar
    // or non-matching value; $_GET entries are always strings in practice.
    $page_mode = 'send';
} else {
    $page_mode = $_GET['mode'];
}
/** @var array<string, mixed> $page */
$page['mode'] = $page_mode;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(get_tab_status($page_mode));

// +-----------------------------------------------------------------------+
// | Add event handler                                                     |
// +-----------------------------------------------------------------------+
add_event_handler('nbm_render_global_customize_mail_content', 'render_global_customize_mail_content');
trigger_notify('nbm_event_handler_added');

// +-----------------------------------------------------------------------+
// | Insert new users with mails                                           |
// +-----------------------------------------------------------------------+
if (count($_POST) == 0) {
    // No insert data in post mode
    insert_new_data_user_mail_notification();
}

// +-----------------------------------------------------------------------+
// | Treatment of tab post                                                 |
// +-----------------------------------------------------------------------+

if (! empty($_POST)) {
    check_pwg_token();
}

switch ($page_mode) {
    case 'param':

        if (isset($_POST['param_submit'])) {
            $nbm_send_mail_as = $_POST['nbm_send_mail_as'] ?? null;
            $_POST['nbm_send_mail_as'] = strip_tags(is_string($nbm_send_mail_as) ? $nbm_send_mail_as : '');

            check_input_parameter('nbm_send_html_mail', $_POST, false, '/^(true|false)$/');
            check_input_parameter('nbm_send_detailed_content', $_POST, false, '/^(true|false)$/');
            check_input_parameter('nbm_send_recent_post_dates', $_POST, false, '/^(true|false)$/');

            $updated_param_count = 0;
            // Update param
            $result = pwg_query('select param, value from ' . Tables::config() . ' where param like \'nbm\\_%\'');
            while ((bool) ($nbm_user = pwg_db_fetch_assoc($result))) {
                // 'param' is the config table's primary key, never null.
                if (! is_string($nbm_user['param'])) {
                    continue;
                }
                if (isset($_POST[$nbm_user['param']])) {
                    conf_update_param($nbm_user['param'], $_POST[$nbm_user['param']], true);
                    $updated_param_count++;
                }
            }

            $template->assign(
                [
                    'save_success' => l10n_dec(
                        '%d parameter was updated.',
                        '%d parameters were updated.',
                        $updated_param_count
                    ),
                ]
            );
        }

        // no break
    case 'subscribe':

        if (isset($_POST['falsify']) and isset($_POST['cat_true']) and is_array($_POST['cat_true'])) {
            // unsubscribe_notification_by_mail() is declared to return
            // mixed[] (a list, always sequentially int-keyed in practice via
            // $check_key_treated[] appends), but PHPStan reads that shorthand
            // as array<mixed> (key type not pinned to int); array_values()
            // re-keys it to the array<int, mixed> do_timeout_treatment()
            // actually expects.
            $check_key_treated = array_values(unsubscribe_notification_by_mail(true, array_values($_POST['cat_true'])));
            $must_repost = do_timeout_treatment('cat_true', $check_key_treated);
        } elseif (isset($_POST['trueify']) and isset($_POST['cat_false']) and is_array($_POST['cat_false'])) {
            $check_key_treated = array_values(subscribe_notification_by_mail(true, array_values($_POST['cat_false'])));
            $must_repost = do_timeout_treatment('cat_false', $check_key_treated);
        }
        break;

    case 'send':

        if (
            isset($_POST['send_submit'])
            and isset($_POST['send_selection']) and is_array($_POST['send_selection'])
            and isset($_POST['send_customize_mail_content']) and is_string($_POST['send_customize_mail_content'])
        ) {
            $check_key_treated = do_action_send_mail_notification(
                'send',
                array_values($_POST['send_selection']),
                stripslashes($_POST['send_customize_mail_content'])
            );
            $must_repost = do_timeout_treatment('send_selection', $check_key_treated);
        }

}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+
$template->set_filenames(
    [
        'double_select' => 'double_select.tpl',
        'notification_by_mail' => 'notification_by_mail.tpl',
    ]
);

$template->assign(
    [
        'PWG_TOKEN' => get_pwg_token(),
        'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=notification_by_mail',
        'F_ACTION' => $base_url . get_query_string_diff([]),
    ]
);

if (is_autorize_status(AccessLevel::Webmaster)) {
    // TabSheet
    $tabsheet = new tabsheet();
    $tabsheet->set_id('nbm');
    $tabsheet->select($page_mode);
    $tabsheet->assign();
}

if ($must_repost) {
    // Get name of submit button
    $repost_submit_name = '';
    if (isset($_POST['falsify'])) {
        $repost_submit_name = 'falsify';
    } elseif (isset($_POST['trueify'])) {
        $repost_submit_name = 'trueify';
    } elseif (isset($_POST['send_submit'])) {
        $repost_submit_name = 'send_submit';
    }

    $template->assign('REPOST_SUBMIT_NAME', $repost_submit_name);
}

switch ($page_mode) {
    case 'param':

        $template->assign(
            $page_mode,
            [
                'SEND_HTML_MAIL' => $conf['nbm_send_html_mail'],
                'SEND_MAIL_AS' => $conf['nbm_send_mail_as'],
                'SEND_DETAILED_CONTENT' => $conf['nbm_send_detailed_content'],
                'COMPLEMENTARY_MAIL_CONTENT' => $conf['nbm_complementary_mail_content'],
                'SEND_RECENT_POST_DATES' => $conf['nbm_send_recent_post_dates'],
            ]
        );
        break;

    case 'subscribe':

        $template->assign($page_mode, true);

        $template->assign(
            [
                'L_CAT_OPTIONS_TRUE' => l10n('Subscribed'),
                'L_CAT_OPTIONS_FALSE' => l10n('Unsubscribed'),
            ]
        );

        $data_users = get_user_notifications('subscribe');

        $opt_true = [];
        $opt_true_selected = [];
        $opt_false = [];
        $opt_false_selected = [];
        foreach ($data_users as $nbm_user) {
            if (! is_string($nbm_user['check_key'])) {
                // check_key is the table's unique key, never null in practice.
                continue;
            }
            if (get_boolean($nbm_user['enabled'])) {
                $opt_true[$nbm_user['check_key']] = stripslashes((string) $nbm_user['username']) . '[' . $nbm_user['mail_address'] . ']';
                if (isset($_POST['falsify']) and isset($_POST['cat_true']) and is_array($_POST['cat_true']) and in_array($nbm_user['check_key'], $_POST['cat_true'])) {
                    $opt_true_selected[] = $nbm_user['check_key'];
                }
            } else {
                $opt_false[$nbm_user['check_key']] = stripslashes((string) $nbm_user['username']) . '[' . $nbm_user['mail_address'] . ']';
                if (isset($_POST['trueify']) and isset($_POST['cat_false']) and is_array($_POST['cat_false']) and in_array($nbm_user['check_key'], $_POST['cat_false'])) {
                    $opt_false_selected[] = $nbm_user['check_key'];
                }
            }
        }
        $template->assign(
            [
                'category_option_true' => $opt_true,
                'category_option_true_selected' => $opt_true_selected,
                'category_option_false' => $opt_false,
                'category_option_false_selected' => $opt_false_selected,
            ]
        );
        $template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        break;

    case 'send':

        $tpl_var = [
            'users' => [],
        ];

        $data_users = do_action_send_mail_notification('list_to_send');

        $tpl_var['CUSTOMIZE_MAIL_CONTENT'] =
          (isset($_POST['send_customize_mail_content']) and is_string($_POST['send_customize_mail_content']))
            ? stripslashes($_POST['send_customize_mail_content'])
            : $conf['nbm_complementary_mail_content'];

        $post_send_selection = (isset($_POST['send_selection']) and is_array($_POST['send_selection']))
            ? $_POST['send_selection']
            : [];

        if ((bool) count($data_users)) {
            foreach ($data_users as $nbm_user) {
                // do_action_send_mail_notification('list_to_send') returns
                // get_user_notifications() rows unchanged.
                assert(is_array($nbm_user));
                /** @var array<string, string|null> $nbm_user */
                if (
                    (! $must_repost) or // Not timeout, normal treatment
                    in_array($nbm_user['check_key'], $post_send_selection)  // Must be repost, show only user to send
                ) {
                    $tpl_var['users'][] =
                      [
                          'ID' => $nbm_user['check_key'],
                          'CHECKED' => ( // not check if not selected,  on init select<all
                              isset($_POST['send_selection']) and // not init
                              ! in_array($nbm_user['check_key'], $post_send_selection) // not selected
                          ) ? '' : 'checked="checked"',
                          'USERNAME' => stripslashes((string) $nbm_user['username']),
                          'EMAIL' => $nbm_user['mail_address'],
                          'LAST_SEND' => $nbm_user['last_send'],
                      ];
                }
            }
        }
        $template->assign($page_mode, $tpl_var);

        // auth_key_duration is a plain int config value (see
        // include/config_default.inc.php).
        $auth_key_duration = $conf['auth_key_duration'];
        $auth_key_duration_num = is_numeric($auth_key_duration) ? (int) $auth_key_duration : 0;
        if ($auth_key_duration_num > 0) {
            $auth_key_since = strtotime('now -' . $auth_key_duration_num . ' second');
            // the relative time expression above is always syntactically valid
            assert($auth_key_since !== false);
            $template->assign(
                'auth_key_duration',
                time_since(
                    $auth_key_since,
                    'second',
                    null,
                    false
                )
            );
        }

        break;

}

$template->assign('ADMIN_PAGE_TITLE', l10n('Send mail to users'));

// +-----------------------------------------------------------------------+
// | Sending html code                                                     |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'notification_by_mail');
