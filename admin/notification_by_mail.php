<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// | include                                                               |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions_notification_by_mail.inc.php');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
include_once(PHPWG_ROOT_PATH.'include/functions_notification.inc.php');
include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('mode', $_GET, false, '/^(param|subscribe|send)$/');

// +-----------------------------------------------------------------------+
// | Initialization                                                        |
// +-----------------------------------------------------------------------+
$base_url = get_root_url().'admin.php';
$must_repost = false;

// +-----------------------------------------------------------------------+
// | functions                                                             |
// +-----------------------------------------------------------------------+

/*
 * Do timeout treatment in order to finish to send mails
 *
 * @param $post_keyname: key of check_key post array
 * @param check_key_treated: array of check_key treated
 * @return none
 */
/** @param string[] $check_key_treated */
function do_timeout_treatment(string $post_keyname, array $check_key_treated = []): bool
{
    global $env_nbm, $base_url, $must_repost;
    if ($env_nbm['is_sendmail_timeout']) {
        if (isset($_POST[$post_keyname])) {
            $post_keyname_val = is_array($_POST[$post_keyname]) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST[$post_keyname]) : [];
            $post_count = count($post_keyname_val);
            $treated_count = count($check_key_treated);
            if ($treated_count != 0) {
                $time_refresh = ceil((get_moment() - $env_nbm['start_time']) * $post_count / $treated_count);
            } else {
                $time_refresh = 0;
            }
            $_POST[$post_keyname] = array_diff($post_keyname_val, $check_key_treated);

            $must_repost = true;
            \Piwigo\Core\PageState::current()->addError(l10n_dec(
                'Execution time is out, treatment must be continue [Estimated time: %d second].',
                'Execution time is out, treatment must be continue [Estimated time: %d seconds].',
                (int) $time_refresh
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
    $result = ACCESS_WEBMASTER;
    $result = match ($mode) {
        'param', 'subscribe' => ACCESS_WEBMASTER,
        'send' => ACCESS_ADMINISTRATOR,
        default => ACCESS_WEBMASTER,
    };
    return $result;
}

/*
 * Inserting News users
 */
function insert_new_data_user_mail_notification(): void
{
    global $env_nbm, $base_url;
    // Set null mail_address empty
    $query = '
update
  '.USERS_TABLE.'
set
  '.\Piwigo\Config\Config::userFields()['email'].' = null
where
  trim('.\Piwigo\Config\Config::userFields()['email'].') = \'\';';
    pwg_query($query);

    // null mail_address are not selected in the list
    $query = '
select
  u.'.\Piwigo\Config\Config::userFields()['id'].' as user_id,
  u.'.\Piwigo\Config\Config::userFields()['username'].' as username,
  u.'.\Piwigo\Config\Config::userFields()['email'].' as mail_address
from
  '.USERS_TABLE.' as u left join '.USER_MAIL_NOTIFICATION_TABLE.' as m on u.'.\Piwigo\Config\Config::userFields()['id'].' = m.user_id
where
  u.'.\Piwigo\Config\Config::userFields()['email'].' is not null and
  m.user_id is null
order by
  user_id;';

    $result = pwg_query($query);

    if (pwg_db_num_rows($result) > 0) {
        $inserts = [];
        $check_key_list = [];

        while ($nbm_user = pwg_db_fetch_assoc($result)) {
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

            \Piwigo\Core\PageState::current()->addInfo(l10n(
                'User %s [%s] added.',
                stripslashes((string) $nbm_user['username']),
                $nbm_user['mail_address']
            ));
        }

        // Insert new nbm_users
        mass_inserts(USER_MAIL_NOTIFICATION_TABLE, ['user_id', 'check_key', 'enabled'], $inserts);
        // Update field enabled with specific function
        $check_key_treated = do_subscribe_unsubscribe_notification_by_mail(
            true,
            \Piwigo\Config\Config::nbmDefaultValueUserEnabled(),
            $check_key_list
        );

        // On timeout simulate like tabsheet send
        if ($env_nbm['is_sendmail_timeout']) {
            $quoted_check_key_list = quote_check_key_list(array_diff($check_key_list, $check_key_treated));
            if (count($quoted_check_key_list) != 0) {
                $query = 'delete from '.USER_MAIL_NOTIFICATION_TABLE.' where check_key in ('.implode(',', $quoted_check_key_list).');';
                $result = pwg_query($query);

                redirect($base_url.get_query_string_diff([], false), l10n('Operation in progress')."\n".l10n('Please wait...'));
            }
        }
    }
}

/*
 * Apply global functions to mail content
 * return customize mail content rendered
 */
/** @param string|array<mixed> $customize_mail_content */
function render_global_customize_mail_content(string|array $customize_mail_content): string
{
    if (is_array($customize_mail_content)) {
        return '';
    }
    if (\Piwigo\Config\Config::nbmSendHtmlMail() and !(str_starts_with($customize_mail_content, '<'))) {
        // On HTML mail, detects if the content are HTML format.
        // If it's plain text format, convert content to readable HTML
        return nl2br(htmlspecialchars($customize_mail_content));
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
 * @return mixed[]
 */
/**
 * @param string[] $check_key_list
 * @return array<mixed>
 */
function do_action_send_mail_notification(string $action = 'list_to_send', array $check_key_list = [], string $customize_mail_content = ''): array
{
    global $lang_info, $env_nbm;
    $return_list = [];

    if (in_array($action, ['list_to_send', 'send'])) {
        [$dbnow] = pwg_db_fetch_row(pwg_query('SELECT NOW();')) ?? [null];
        $dbnow = $dbnow ? (string)$dbnow : null;

        $is_action_send = ($action == 'send');

        // disabled and null mail_address are not selected in the list
        $data_users = get_user_notifications('send', $check_key_list);

        // List all if it's define on options or on timeout
        $is_list_all_without_test = ($env_nbm['is_sendmail_timeout'] or \Piwigo\Config\Config::nbmListAllEnabledUsersToSend());

        // Check if exist news to list user or send mails
        if ((!$is_list_all_without_test) or ($is_action_send)) {
            if (count($data_users) > 0) {
                $datas = [];

                if (empty($customize_mail_content)) {
                    $customize_mail_content = \Piwigo\Config\Config::nbmComplementaryMailContent();
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
                    if ((!$is_action_send) and check_sendmail_timeout()) {
                        // Stop fill list on 'list_to_send', if the quota is override
                        \Piwigo\Core\PageState::current()->addInfo($msg_break_timeout);
                        break;
                    }
                    if (($is_action_send) and check_sendmail_timeout()) {
                        // Stop fill list on 'send', if the quota is override
                        \Piwigo\Core\PageState::current()->addError($msg_break_timeout);
                        break;
                    }

                    // set env nbm user
                    set_user_on_env_nbm($nbm_user, $is_action_send);

                    if ($is_action_send) {
                        $auth = null;
                        $add_url_params = [];

                        $auth_key = create_user_auth_key(is_numeric($nbm_user['user_id']) ? (int) $nbm_user['user_id'] : 0, is_string($nbm_user['status']) ? $nbm_user['status'] : null);

                        if (is_array($auth_key) && is_string($auth_key['auth_key'] ?? null)) {
                            $auth = $auth_key['auth_key'];
                            $add_url_params['auth'] = $auth;
                        }

                        set_make_full_url();
                        // Fill return list of "treated" check_key for 'send'
                        $return_list[] = (string) $nbm_user['check_key'];

                        $last_send = is_string($nbm_user['last_send']) || is_null($nbm_user['last_send']) ? $nbm_user['last_send'] : (string) $nbm_user['last_send'];
                        $dbnow_str = is_scalar($dbnow) ? (string) $dbnow : null;
                        if (\Piwigo\Config\Config::nbmSendDetailedContent()) {
                            $news = news($last_send, $dbnow_str, false, \Piwigo\Config\Config::nbmSendHtmlMail(), $auth);
                            $exist_data = count($news) > 0;
                        } else {
                            $exist_data = news_exists($last_send, $dbnow_str);
                        }

                        if ($exist_data) {
                            $subject = '['.\Piwigo\Config\Config::galleryTitle().'] '.l10n('New photos added');

                            // Assign current var for nbm mail
                            assign_vars_nbm_mail_content($nbm_user);

                            if (!is_null($nbm_user['last_send'])) {
                                $env_nbm['mail_template']->assign(
                                    'content_new_elements_between',
                                    [
                                    'DATE_BETWEEN_1' => $nbm_user['last_send'],
                                    'DATE_BETWEEN_2' => $dbnow,
                  ]
                                );
                            } else {
                                $env_nbm['mail_template']->assign(
                                    'content_new_elements_single',
                                    [
                                    'DATE_SINGLE' => $dbnow,
                  ]
                                );
                            }

                            if (\Piwigo\Config\Config::nbmSendDetailedContent()) {
                                $env_nbm['mail_template']->assign('global_new_lines', $news);
                            }

                            $nbm_user_customize_mail_content =
                              trigger_change(
                                  'nbm_render_user_customize_mail_content',
                                  $customize_mail_content,
                                  $nbm_user
                              );
                            if (!empty($nbm_user_customize_mail_content)) {
                                $env_nbm['mail_template']->assign(
                                    'custom_mail_content',
                                    $nbm_user_customize_mail_content
                                );
                            }

                            if (\Piwigo\Config\Config::nbmSendHtmlMail() and \Piwigo\Config\Config::nbmSendRecentPostDates()) {
                                $recent_post_dates = get_recent_post_dates_array(\Piwigo\Config\Config::recentPostDates()['NBM']);
                                foreach ($recent_post_dates as $date_detail) {
                                    $date_detail_arr = is_array($date_detail) ? $date_detail : [];
                                    $env_nbm['mail_template']->append(
                                        'recent_posts',
                                        [
                                        'TITLE' => get_title_recent_post_date($date_detail_arr),
                                        'HTML_DATA' => get_html_description_recent_post_date($date_detail_arr, is_string($auth) ? $auth : null),
                    ]
                                    );
                                }
                            }

                            $env_nbm['mail_template']->assign(
                                [
                                'GOTO_GALLERY_TITLE' => \Piwigo\Config\Config::galleryTitle(),
                                'GOTO_GALLERY_URL' => add_url_params(get_gallery_home_url(), $add_url_params),
                                'SEND_AS_NAME'      => $env_nbm['send_as_name'],
                ]
                            );

                            $ret = pwg_mail(
                                [
                                'name' => stripslashes(is_scalar($nbm_user['username']) ? (string) $nbm_user['username'] : ''),
                                'email' => is_scalar($nbm_user['mail_address']) ? (string) $nbm_user['mail_address'] : '',
                                ],
                                [
                                'from' => $env_nbm['send_as_mail_formated'],
                                'subject' => $subject,
                                'email_format' => $env_nbm['email_format'],
                                'content' => $env_nbm['mail_template']->parse('notification_by_mail', true),
                                'content_format' => $env_nbm['email_format'],
                                'auth_key' => $auth,
                                ]
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
                        $last_send = isset($nbm_user['last_send']) ? (string)$nbm_user['last_send'] : null;
                        if (news_exists($last_send, $dbnow)) {
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
                        USER_MAIL_NOTIFICATION_TABLE,
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
                    \Piwigo\Core\PageState::current()->addError(l10n('No user to send notifications by mail.'));
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
if (!isset($_GET['mode']) || !is_string($_GET['mode'])) {
    $page['mode'] = 'send';
} else {
    $page['mode'] = $_GET['mode'];
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(get_tab_status($page['mode']));


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

if (!empty($_POST)) {
    check_pwg_token();
}

switch ($page['mode']) {
    case 'param':
        {
            if (isset($_POST['param_submit'])) {
                $_POST['nbm_send_mail_as'] = strip_tags(is_scalar($_POST['nbm_send_mail_as'] ?? null) ? (string)$_POST['nbm_send_mail_as'] : '');

                check_input_parameter('nbm_send_html_mail', $_POST, false, '/^(true|false)$/');
                check_input_parameter('nbm_send_detailed_content', $_POST, false, '/^(true|false)$/');
                check_input_parameter('nbm_send_recent_post_dates', $_POST, false, '/^(true|false)$/');

                $updated_param_count = 0;
                // Update param
                $result = pwg_query('select param, value from '.CONFIG_TABLE.' where param like \'nbm\\_%\'');
                while ($nbm_user = pwg_db_fetch_assoc($result)) {
                    $param = (string)$nbm_user['param'];
                    if (isset($_POST[$param])) {
                        conf_update_param($param, $_POST[$param], true);
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
        }
    case 'subscribe':
        {
            if (isset($_POST['falsify']) and isset($_POST['cat_true'])) {
                $cat_true = is_array($_POST['cat_true']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['cat_true']) : [];
                $check_key_treated = unsubscribe_notification_by_mail(true, $cat_true);
                if (do_timeout_treatment('cat_true', $check_key_treated)) {
                    $must_repost = true;
                }
            } elseif (isset($_POST['trueify']) and isset($_POST['cat_false'])) {
                $cat_false = is_array($_POST['cat_false']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['cat_false']) : [];
                $check_key_treated = subscribe_notification_by_mail(true, $cat_false);
                if (do_timeout_treatment('cat_false', $check_key_treated)) {
                    $must_repost = true;
                }
            }
            break;
        }

    case 'send':
        {
            if (isset($_POST['send_submit']) and isset($_POST['send_selection']) and isset($_POST['send_customize_mail_content'])) {
                $send_selection = is_array($_POST['send_selection']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['send_selection']) : [];
                $check_key_treated = do_action_send_mail_notification('send', $send_selection, stripslashes(is_scalar($_POST['send_customize_mail_content']) ? (string)$_POST['send_customize_mail_content'] : ''));
                $check_key_treated_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $check_key_treated);
                if (do_timeout_treatment('send_selection', $check_key_treated_str)) {
                    $must_repost = true;
                }
            }
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
    'U_HELP' => get_root_url().'admin/popuphelp.php?page=notification_by_mail',
    'F_ACTION' => $base_url.get_query_string_diff([]),
  ]
);

if (is_autorize_status(ACCESS_WEBMASTER)) {
    // TabSheet
    $tabsheet = new Tabsheet();
    $tabsheet->set_id('nbm');
    $tabsheet->select($page['mode']);
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

switch ($page['mode']) {
    case 'param':
        {
            $template->assign(
                $page['mode'],
                [
                'SEND_HTML_MAIL' => \Piwigo\Config\Config::nbmSendHtmlMail(),
                'SEND_MAIL_AS' => \Piwigo\Config\Config::nbmSendMailAs(),
                'SEND_DETAILED_CONTENT' => \Piwigo\Config\Config::nbmSendDetailedContent(),
                'COMPLEMENTARY_MAIL_CONTENT' => \Piwigo\Config\Config::nbmComplementaryMailContent(),
                'SEND_RECENT_POST_DATES' => \Piwigo\Config\Config::nbmSendRecentPostDates(),
                ]
            );
            break;
        }

    case 'subscribe':
        {
            $template->assign($page['mode'], true);

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
            $cat_true_post = is_array($_POST['cat_true'] ?? null) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['cat_true']) : [];
            $cat_false_post = is_array($_POST['cat_false'] ?? null) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['cat_false']) : [];
            foreach ($data_users as $nbm_user) {
                $ck = (string) $nbm_user['check_key'];
                if (get_boolean($nbm_user['enabled'])) {
                    $opt_true[$ck] = stripslashes((string) $nbm_user['username']).'['.(string)$nbm_user['mail_address'].']';
                    if (isset($_POST['falsify']) and in_array($ck, $cat_true_post)) {
                        $opt_true_selected[] = $ck;
                    }
                } else {
                    $opt_false[$ck] = stripslashes((string) $nbm_user['username']).'['.(string)$nbm_user['mail_address'].']';
                    if (isset($_POST['trueify']) and in_array($ck, $cat_false_post)) {
                        $opt_false_selected[] = $ck;
                    }
                }
            }
            $template->assign(
                [
                'category_option_true'          => $opt_true,
                'category_option_true_selected' => $opt_true_selected,
                'category_option_false'         => $opt_false,
                'category_option_false_selected' => $opt_false_selected,
                ]
            );
            $template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
            break;
        }

    case 'send':
        {
            $tpl_var = ['users' => [] ];

            $data_users = do_action_send_mail_notification('list_to_send');

            $tpl_var['CUSTOMIZE_MAIL_CONTENT'] =
              isset($_POST['send_customize_mail_content'])
                ? stripslashes(is_scalar($_POST['send_customize_mail_content']) ? (string)$_POST['send_customize_mail_content'] : '')
                : \Piwigo\Config\Config::nbmComplementaryMailContent();

            $send_sel_post = is_array($_POST['send_selection'] ?? null) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $_POST['send_selection']) : [];
            if (count($data_users)) {
                foreach ($data_users as $nbm_user_raw) {
                    if (!is_array($nbm_user_raw)) {
                        continue;
                    }
                    $checkKey = is_scalar($nbm_user_raw['check_key'] ?? null) ? (string) $nbm_user_raw['check_key'] : '';
                    if (
                        !$must_repost or // Not timeout, normal treatment
                        in_array($checkKey, $send_sel_post)  // Must be repost, show only user to send
                    ) {
                        $tpl_var['users'][] =
                          [
                            'ID' => $checkKey,
                            'CHECKED' =>  ( // not check if not selected,  on init select<all
                                isset($_POST['send_selection']) and // not init
                                !in_array($checkKey, $send_sel_post) // not selected
                            ) ? '' : 'checked="checked"',
                            'USERNAME' => stripslashes(is_scalar($nbm_user_raw['username'] ?? null) ? (string) $nbm_user_raw['username'] : ''),
                            'EMAIL' => $nbm_user_raw['mail_address'] ?? '',
                            'LAST_SEND' => $nbm_user_raw['last_send'] ?? null,
                            ];
                    }
                }
            }
            $template->assign($page['mode'], $tpl_var);

            if (\Piwigo\Config\Config::authKeyDuration() > 0) {
                $template->assign(
                    'auth_key_duration',
                    time_since(
                        strtotime('now -'.\Piwigo\Config\Config::authKeyDuration().' second') ?: null,
                        'second',
                        null,
                        false
                    )
                );
            }

            break;
        }
}

$template->assign('ADMIN_PAGE_TITLE', l10n('Send mail to users'));

// +-----------------------------------------------------------------------+
// | Sending html code                                                     |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'notification_by_mail');
