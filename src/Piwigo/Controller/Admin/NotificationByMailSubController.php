<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\tabsheet;
use Piwigo\Cache\PersistentCache;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/notification_by_mail.php (page slug "notification_by_mail"),
 * folded directly into this controller -- same shape as every prior P23
 * batch 6 sub-batch's shell folding.
 *
 * `admin/include/functions_notification_by_mail.inc.php` (P23 batch 8b-7)
 * is now folded into `Piwigo\Mail\NotificationByMailSender`, constructed
 * once as `$nbmSender` near the top of `handle()` and threaded explicitly
 * into the private static methods below that need it -- replacing the
 * former `include_once` + implicit `global $env_nbm;` state-threading with
 * a real constructed dependency. `doActionSendMailNotification()`'s own
 * body moved with it (as `NotificationByMailSender::sendMailNotifications()`)
 * since it read the sender's internal `$env_nbm`-equivalent state
 * (email format, mail template, sender address) directly rather than just
 * calling the sender's own public methods -- keeping it here would have
 * meant leaking that internal state back out through new getters, when the
 * method is really part of the same mail-sending pipeline the sender
 * already owns. See `NotificationByMailSender`'s own docblock for why it
 * lives in `Piwigo\Mail` (L3Presentation), not `Piwigo\Notification`
 * (L2bExtendedDomain, which may not depend on `Piwigo\Template\Template`).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original file's own first (redundant, same level) check_status()
 * call is dropped here -- same precedent as MaintenanceSubController/
 * ConfigurationSubController. Its SECOND check_status(get_tab_status(...))
 * call is kept unchanged: get_tab_status() requires AccessLevel::Webmaster
 * (not just Administrator) for the "param"/"subscribe" tabs, a genuinely
 * higher bar than admin.php's own gate for 2 of the 3 tabs.
 *
 * No CSRF gap: the single `if (! empty($_POST)) { check_pwg_token(); }`
 * gate (before the 3-way $page_mode switch) already covers every real
 * mutation across all 3 tabs uniformly. insertNewDataUserMailNotification()
 * runs unconditionally on every GET page load and can send real mail via
 * pwg_mail() if any users are missing their own notification-subscription
 * row -- reviewed, not a CSRF gap (nothing attacker-controlled is written;
 * the outcome is fully determined by server config and existing DB state,
 * matching the delete_orphans/sync_md5sum "real work, no attacker-
 * controlled outcome" precedent from P23 batch 6g).
 *
 * do_timeout_treatment()/get_tab_status()/insertNewDataUserMailNotification()/
 * renderGlobalCustomizeMailContent() were top-level functions in the
 * original file with zero external callers (confirmed via a direct grep --
 * tools/triggers_list.php mentions 2 of them in a documentation string
 * only, not executable code) -- folded into private static methods here,
 * removing the "cannot redeclare function on double-include" risk every
 * prior sub-batch with this shape has already converted away from.
 * doActionSendMailNotification() moved to NotificationByMailSender instead,
 * see above.
 *
 * renderGlobalCustomizeMailContent() needed different handling than the
 * other 4: it's registered as an event handler
 * (`add_event_handler('nbm_render_global_customize_mail_content', ...)`),
 * and a bare string can't resolve to a private method from outside the
 * class. EventDispatcher::addEventHandler()'s real signature is
 * `string|array|object $func`, which accepts a Closure -- registered via
 * first-class callable syntax (`self::renderGlobalCustomizeMailContent(...)`)
 * instead, a real Closure created from inside the class that fully
 * preserves private-method encapsulation.
 */
final class NotificationByMailSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var Template $template
         */
        global $conf, $template;

        include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

        /** @var mixed $persistent_cache */
        global $persistent_cache;

        $htmlRenderer = new HtmlService();

        if (! $persistent_cache instanceof PersistentCache) {
            $htmlRenderer->fatalError('persistent cache not initialized');
        }

        $conn = DbConnection::build();
        $nbmSender = new NotificationByMailSender(
            new NotificationByMailService(new NotificationByMailRepository($conn)),
            new NotificationService(
                new NotificationRepository($conn),
                new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
                $persistent_cache,
                $htmlRenderer
            )
        );

        (new \Piwigo\Validation\InputValidator())->validate('mode', $_GET, false, '/^(param|subscribe|send)$/');

        // +-----------------------------------------------------------------------+
        // | Initialization                                                        |
        // +-----------------------------------------------------------------------+
        // insertNewDataUserMailNotification() below reads this back via
        // `global $base_url;` -- this method is always invoked from inside
        // an AdminSubControllerInterface::handle() call frame
        // (Piwigo\Bootstrap\AdminDispatcher), so a bare assignment here
        // would only be local to this call frame, invisible to that
        // method's own `global` read.
        global $base_url;
        $base_url = get_root_url() . 'admin.php';
        $must_repost = false;

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
        global $page;
        $page['mode'] = $page_mode;

        // +-----------------------------------------------------------------------+
        // | Check Access and exit when user status is not ok                      |
        // +-----------------------------------------------------------------------+
        \Piwigo\Auth\AccessControl::checkStatus(self::getTabStatus($page_mode));

        // +-----------------------------------------------------------------------+
        // | Add event handler                                                     |
        // +-----------------------------------------------------------------------+
        add_event_handler('nbm_render_global_customize_mail_content', self::renderGlobalCustomizeMailContent(...));
        trigger_notify('nbm_event_handler_added');

        // +-----------------------------------------------------------------------+
        // | Insert new users with mails                                           |
        // +-----------------------------------------------------------------------+
        if (count($_POST) == 0) {
            // No insert data in post mode
            self::insertNewDataUserMailNotification($nbmSender);
        }

        // +-----------------------------------------------------------------------+
        // | Treatment of tab post                                                 |
        // +-----------------------------------------------------------------------+

        if (! empty($_POST)) {
            new \Piwigo\Csrf\CsrfService()->checkOrFail($htmlRenderer);
        }

        switch ($page_mode) {
            case 'param':

                if (isset($_POST['param_submit'])) {
                    $nbm_send_mail_as = $_POST['nbm_send_mail_as'] ?? null;
                    $_POST['nbm_send_mail_as'] = strip_tags(is_string($nbm_send_mail_as) ? $nbm_send_mail_as : '');

                    (new \Piwigo\Validation\InputValidator())->validate('nbm_send_html_mail', $_POST, false, '/^(true|false)$/');
                    (new \Piwigo\Validation\InputValidator())->validate('nbm_send_detailed_content', $_POST, false, '/^(true|false)$/');
                    (new \Piwigo\Validation\InputValidator())->validate('nbm_send_recent_post_dates', $_POST, false, '/^(true|false)$/');

                    $updated_param_count = 0;
                    // Update param
                    $result = \Piwigo\Db\MysqliDb::query('select param, value from ' . Tables::config() . ' where param like \'nbm\\_%\'');
                    while ((bool) ($nbm_user = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                        // 'param' is the config table's primary key, never null.
                        if (! is_string($nbm_user['param'])) {
                            continue;
                        }
                        if (isset($_POST[$nbm_user['param']])) {
                            \Piwigo\Config\ConfigDb::confUpdateParam($nbm_user['param'], $_POST[$nbm_user['param']], true);
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
                    // unsubscribeNotificationByMail() is declared to return
                    // mixed[] (a list, always sequentially int-keyed in practice via
                    // $check_key_treated[] appends), but PHPStan reads that shorthand
                    // as array<mixed> (key type not pinned to int); array_values()
                    // re-keys it to the array<int, mixed> doTimeoutTreatment()
                    // actually expects.
                    $check_key_treated = array_values($nbmSender->unsubscribeNotificationByMail(true, array_values($_POST['cat_true'])));
                    $must_repost = self::doTimeoutTreatment($nbmSender, 'cat_true', $check_key_treated);
                } elseif (isset($_POST['trueify']) and isset($_POST['cat_false']) and is_array($_POST['cat_false'])) {
                    $check_key_treated = array_values($nbmSender->subscribeNotificationByMail(true, array_values($_POST['cat_false'])));
                    $must_repost = self::doTimeoutTreatment($nbmSender, 'cat_false', $check_key_treated);
                }
                break;

            case 'send':

                if (
                    isset($_POST['send_submit'])
                    and isset($_POST['send_selection']) and is_array($_POST['send_selection'])
                    and isset($_POST['send_customize_mail_content']) and is_string($_POST['send_customize_mail_content'])
                ) {
                    $check_key_treated = $nbmSender->sendMailNotifications(
                        'send',
                        array_values($_POST['send_selection']),
                        stripslashes($_POST['send_customize_mail_content'])
                    );
                    $must_repost = self::doTimeoutTreatment($nbmSender, 'send_selection', $check_key_treated);
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
                'PWG_TOKEN' => (new \Piwigo\Csrf\CsrfService())->getToken(),
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=notification_by_mail',
                'F_ACTION' => $base_url . get_query_string_diff([]),
            ]
        );

        if (\Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Webmaster)) {
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

                $data_users = $nbmSender->getUserNotifications('subscribe');

                $opt_true = [];
                $opt_true_selected = [];
                $opt_false = [];
                $opt_false_selected = [];
                foreach ($data_users as $nbm_user) {
                    if (! is_string($nbm_user['check_key'])) {
                        // check_key is the table's unique key, never null in practice.
                        continue;
                    }
                    if (\Piwigo\Db\MysqliDb::getBoolean($nbm_user['enabled'])) {
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

                $data_users = $nbmSender->sendMailNotifications('list_to_send');

                $tpl_var['CUSTOMIZE_MAIL_CONTENT'] =
                  (isset($_POST['send_customize_mail_content']) and is_string($_POST['send_customize_mail_content']))
                    ? stripslashes($_POST['send_customize_mail_content'])
                    : $conf['nbm_complementary_mail_content'];

                $post_send_selection = (isset($_POST['send_selection']) and is_array($_POST['send_selection']))
                    ? $_POST['send_selection']
                    : [];

                if ((bool) count($data_users)) {
                    foreach ($data_users as $nbm_user) {
                        // sendMailNotifications('list_to_send') returns
                        // getUserNotifications() rows unchanged.
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
                        \Piwigo\Core\DateHelper::timeSince($auth_key_since, 'second', null, false)
                    );
                }

                break;

        }

        $template->assign('ADMIN_PAGE_TITLE', l10n('Send mail to users'));

        // +-----------------------------------------------------------------------+
        // | Sending html code                                                     |
        // +-----------------------------------------------------------------------+
        $template->assign_var_from_handle('ADMIN_CONTENT', 'notification_by_mail');
    }

    /**
     * Do timeout treatment in order to finish to send mails
     * @param array<int, mixed> $check_key_treated: array of check_key treated
     * @return bool whether treatment timed out and must be reposted
     */
    private static function doTimeoutTreatment(NotificationByMailSender $nbmSender, string $post_keyname, array $check_key_treated = []): bool
    {
        /** @var array<string, mixed> $page */
        global $page;

        if ($nbmSender->isSendmailTimeout()) {
            if (isset($_POST[$post_keyname]) and is_array($_POST[$post_keyname])) {
                $post_count = count($_POST[$post_keyname]);
                $treated_count = count($check_key_treated);
                if ($treated_count != 0) {
                    $time_refresh = (int) ceil((\Piwigo\Core\TimingHelper::getMoment() - $nbmSender->startTime()) * $post_count / $treated_count);
                } else {
                    $time_refresh = 0;
                }
                // $check_key_treated is check_key strings returned by the nbm
                // sender (typed mixed[] there); keep only the string ones so
                // array_diff() gets values it can actually compare.
                $check_key_treated_strings = array_filter($check_key_treated, is_string(...));
                $_POST[$post_keyname] = array_diff(array_filter($_POST[$post_keyname], is_string(...)), $check_key_treated_strings);

                NotificationByMailSender::pushPageMessage($page, 'errors', l10n_dec(
                    'Execution time is out, treatment must be continue [Estimated time: %d second].',
                    'Execution time is out, treatment must be continue [Estimated time: %d seconds].',
                    $time_refresh
                ));
                return true;
            }
        }

        return false;
    }

    /**
     * Get the authorized_status for each tab
     * return corresponding status
     */
    private static function getTabStatus(string $mode): int
    {
        return match ($mode) {
            'param', 'subscribe' => AccessLevel::Webmaster,
            'send' => AccessLevel::Administrator,
            default => AccessLevel::Webmaster,
        };
    }

    /**
     * Inserting News users
     */
    private static function insertNewDataUserMailNotification(NotificationByMailSender $nbmSender): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var string $base_url set at the top of handle()
         */
        global $conf, $page, $base_url;

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
        \Piwigo\Db\MysqliDb::query($query);

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

        $result = \Piwigo\Db\MysqliDb::query($query);

        if (\Piwigo\Db\MysqliDb::numRows($result) > 0) {
            $inserts = [];
            $check_key_list = [];

            while ((bool) ($nbm_user = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                // Calculate key
                $nbm_user['check_key'] = $nbmSender->findAvailableCheckKey();

                // Save key
                $check_key_list[] = $nbm_user['check_key'];

                // Insert new nbm_users
                $inserts[] = [
                    'user_id' => $nbm_user['user_id'],
                    'check_key' => $nbm_user['check_key'],
                    'enabled' => 'false', // By default if false, set to true with specific functions
                ];

                NotificationByMailSender::pushPageMessage($page, 'infos', l10n(
                    'User %s [%s] added.',
                    stripslashes((string) $nbm_user['username']),
                    $nbm_user['mail_address']
                ));
            }

            // Insert new nbm_users
            \Piwigo\Db\MysqliDb::massInserts(Tables::userMailNotification(), ['user_id', 'check_key', 'enabled'], $inserts);
            // Update field enabled with specific function
            $check_key_treated = $nbmSender->doSubscribeUnsubscribeNotificationByMail(
                true,
                (bool) $conf['nbm_default_value_user_enabled'],
                $check_key_list
            );

            // On timeout simulate like tabsheet send
            if ($nbmSender->isSendmailTimeout()) {
                // doSubscribeUnsubscribeNotificationByMail() returns mixed[]
                // of check_key strings; narrow before array_diff() needs values
                // castable to string.
                $check_key_treated_strings = array_filter($check_key_treated, is_string(...));
                $quoted_check_key_list = NotificationByMailSender::quoteCheckKeyList(array_diff($check_key_list, $check_key_treated_strings));
                if (count($quoted_check_key_list) != 0) {
                    $query = 'delete from ' . Tables::userMailNotification() . ' where check_key in (' . implode(',', $quoted_check_key_list) . ');';
                    $result = \Piwigo\Db\MysqliDb::query($query);

                    redirect($base_url . get_query_string_diff([], false), l10n('Operation in progress') . "\n" . l10n('Please wait...'));
                }
            }
        }
    }

    /**
     * Apply global functions to mail content
     * return customize mail content rendered
     */
    private static function renderGlobalCustomizeMailContent(mixed $customize_mail_content): mixed
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
}
