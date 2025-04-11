<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_notification_by_mail;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

// +-----------------------------------------------------------------------+
// | include                                                               |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

require_once __DIR__ . '/../admin/inc/functions_notification_by_mail.php';
require_once __DIR__ . '/../inc/common.php';
require_once __DIR__ . '/../inc/functions_mail.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('mode', $_GET, false, '/^(param|subscribe|send)$/');

// +-----------------------------------------------------------------------+
// | Initialization                                                        |
// +-----------------------------------------------------------------------+
$base_url = functions_url::get_root_url() . 'admin.php';
$must_repost = false;

// +-----------------------------------------------------------------------+
// | Main                                                                  |
// +-----------------------------------------------------------------------+
$page['mode'] = $_GET['mode'] ?? 'send';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(functions_admin::get_tab_status($page['mode']));

// +-----------------------------------------------------------------------+
// | Add event handler                                                     |
// +-----------------------------------------------------------------------+
functions_plugins::add_event_handler('nbm_render_global_customize_mail_content', functions_admin::render_global_customize_mail_content(...));
functions_plugins::trigger_notify('nbm_event_handler_added');

// +-----------------------------------------------------------------------+
// | Insert new users with mails                                           |
// +-----------------------------------------------------------------------+
if (! isset($_POST) ||
    count($_POST) == 0
) {
    // No insert data in post mode
    functions_admin::insert_new_data_user_mail_notification();
}

// +-----------------------------------------------------------------------+
// | Treatment of tab post                                                 |
// +-----------------------------------------------------------------------+

if (! empty($_POST)) {
    functions::check_pwg_token();
}

switch ($page['mode']) {
    case 'param':
        if (isset($_POST['param_submit'])) {
            $_POST['nbm_send_mail_as'] = strip_tags($_POST['nbm_send_mail_as']);

            functions::check_input_parameter('nbm_send_html_mail', $_POST, false, '/^(true|false)$/');
            functions::check_input_parameter('nbm_send_detailed_content', $_POST, false, '/^(true|false)$/');
            functions::check_input_parameter('nbm_send_recent_post_dates', $_POST, false, '/^(true|false)$/');

            $updated_param_count = 0;
            // Update param
            $query = <<<SQL
                SELECT param, value FROM config WHERE param LIKE 'nbm\_%';
                SQL;
            $result = $conf->sql_backend::pwg_query($query);

            while ($nbm_user = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                if (isset($_POST[$nbm_user['param']])) {
                    functions::conf_update_param($nbm_user['param'], $_POST[$nbm_user['param']], true);
                    $updated_param_count++;
                }
            }

            $page['infos'][] = functions::l10n_dec(
                '%d parameter was updated.',
                '%d parameters were updated.',
                $updated_param_count
            );
        }

        // no break

    case 'subscribe':
        if (isset($_POST['falsify']) &&
            isset($_POST['cat_true'])
        ) {
            $check_key_treated = functions_notification_by_mail::unsubscribe_notification_by_mail(true, $_POST['cat_true']);
            functions_admin::do_timeout_treatment('cat_true', $check_key_treated);
        } elseif (isset($_POST['truthify']) &&
                  isset($_POST['cat_false'])
        ) {
            $check_key_treated = functions_notification_by_mail::subscribe_notification_by_mail(true, $_POST['cat_false']);
            functions_admin::do_timeout_treatment('cat_false', $check_key_treated);
        }

        break;

    case 'send':
        if (isset($_POST['send_submit']) &&
            isset($_POST['send_selection']) &&
            isset($_POST['send_customize_mail_content'])
        ) {
            $check_key_treated = functions_admin::do_action_send_mail_notification('send', $_POST['send_selection'], stripslashes($_POST['send_customize_mail_content']));
            functions_admin::do_timeout_treatment('send_selection', $check_key_treated);
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
        'PWG_TOKEN' => functions::get_pwg_token(),
        'U_HELP' => functions_url::get_root_url() . 'admin/popuphelp.php?page=notification_by_mail',
        'F_ACTION' => $base_url . functions_url::get_query_string_diff(),
    ]
);

if (functions_user::is_authorized_status(ACCESS_WEBMASTER)) {
    // TabSheet
    $tabsheet = new tabsheet();
    $tabsheet->set_id('nbm');
    $tabsheet->select($page['mode']);
    $tabsheet->assign();
}

if ($must_repost) {
    // Get name of submit button
    $repost_submit_name = '';
    if (isset($_POST['falsify'])) {
        $repost_submit_name = 'falsify';
    } elseif (isset($_POST['truthify'])) {
        $repost_submit_name = 'truthify';
    } elseif (isset($_POST['send_submit'])) {
        $repost_submit_name = 'send_submit';
    }

    $template->assign('REPOST_SUBMIT_NAME', $repost_submit_name);
}

switch ($page['mode']) {
    case 'param':
        $template->assign(
            $page['mode'],
            [
                'SEND_HTML_MAIL' => $conf->nbm_send_html_mail,
                'SEND_MAIL_AS' => $conf->nbm_send_mail_as,
                'SEND_DETAILED_CONTENT' => $conf->nbm_send_detailed_content,
                'COMPLEMENTARY_MAIL_CONTENT' => $conf->nbm_complementary_mail_content,
                'SEND_RECENT_POST_DATES' => $conf->nbm_send_recent_post_dates,
            ]
        );
        break;

    case 'subscribe':
        $template->assign($page['mode'], true);

        $template->assign(
            [
                'L_CAT_OPTIONS_TRUE' => functions::l10n('Subscribed'),
                'L_CAT_OPTIONS_FALSE' => functions::l10n('Unsubscribed'),
            ]
        );

        $data_users = functions_notification_by_mail::get_user_notifications('subscribe');

        $opt_true = [];
        $opt_true_selected = [];
        $opt_false = [];
        $opt_false_selected = [];

        foreach ($data_users as $nbm_user) {
            if ($conf->sql_backend::get_boolean($nbm_user['enabled'])) {
                $opt_true[$nbm_user['check_key']] = stripslashes($nbm_user['username']) . '[' . $nbm_user['mail_address'] . ']';

                if (isset($_POST['falsify']) &&
                    isset($_POST['cat_true']) &&
                    in_array($nbm_user['check_key'], $_POST['cat_true'])
                ) {
                    $opt_true_selected[] = $nbm_user['check_key'];
                }
            } else {
                $opt_false[$nbm_user['check_key']] = stripslashes($nbm_user['username']) . '[' . $nbm_user['mail_address'] . ']';

                if (isset($_POST['truthify']) &&
                    isset($_POST['cat_false']) &&
                    in_array($nbm_user['check_key'], $_POST['cat_false'])
                ) {
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

        $data_users = functions_admin::do_action_send_mail_notification();

        $tpl_var['CUSTOMIZE_MAIL_CONTENT'] =
          isset($_POST['send_customize_mail_content'])
            ? stripslashes($_POST['send_customize_mail_content'])
            : $conf->nbm_complementary_mail_content;

        if ($data_users !== []) {
            foreach ($data_users as $nbm_user) {
                if (! $must_repost || // Not timeout, normal treatment
                    ($must_repost && in_array($nbm_user['check_key'], $_POST['send_selection']))  // Must be repost, show only user to send
                ) {
                    $tpl_var['users'][] =
                      [
                          'ID' => $nbm_user['check_key'],
                          'CHECKED' => ( // not check if not selected,  on init select<all
                              isset($_POST['send_selection']) && // not init
                              ! in_array($nbm_user['check_key'], $_POST['send_selection']) // not selected
                          ) ? '' : 'checked="checked"',
                          'USERNAME' => stripslashes($nbm_user['username']),
                          'EMAIL' => $nbm_user['mail_address'],
                          'LAST_SEND' => $nbm_user['last_send'],
                      ];
                }
            }
        }

        $template->assign($page['mode'], $tpl_var);

        if ($conf->auth_key_duration > 0) {
            $template->assign(
                'auth_key_duration',
                functions::time_since(
                    strtotime('now -' . $conf->auth_key_duration . ' second'),
                    'second',
                    null,
                    false
                )
            );
        }

        break;
}

$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Send mail to users'));

// +-----------------------------------------------------------------------+
// | Sending html code                                                     |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'notification_by_mail');
