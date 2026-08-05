<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Lifecycle\NbmEventHandlerAdded;
use Piwigo\Event\Mail\NbmRenderGlobalCustomizeMailContent;
use Piwigo\Lang\Translator;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Session\SessionService;
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
 * other 4: it's registered as a typed event handler
 * (`addTypedHandler(NbmRenderGlobalCustomizeMailContent::class, ...)`),
 * and a bare string can't resolve to a private method from outside the
 * class. EventDispatcher::addTypedHandler()'s real signature accepts a
 * Closure -- registered via first-class callable syntax
 * (`self::renderGlobalCustomizeMailContent(...)`) instead, a real
 * Closure created from inside the class that fully preserves
 * private-method encapsulation.
 */
final class NotificationByMailSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CoreTabs $coreTabs,
        private readonly SessionService $sessionService,
        private readonly Translator $translator,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
        private readonly \Piwigo\Mail\NotificationByMailSender $notificationByMailSender,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
        private readonly \Piwigo\Validation\InputValidator $inputValidator,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;

        $nbmSender = $this->notificationByMailSender;

        $notificationByMailRequest = Request\NotificationByMailRequest::fromGlobals($this->inputValidator);
        $page_mode = $notificationByMailRequest->pageMode;
        $post = $notificationByMailRequest->post;

        // +-----------------------------------------------------------------------+
        // | Initialization                                                        |
        // +-----------------------------------------------------------------------+
        // Consumed by CoreTabs::addCoreTabs()'s own 'nbm' case (triggered
        // synchronously inside Tabsheet::select() further down -- must be
        // set before that call, not dead code) and by this method's own
        // F_ACTION assignment below.
        $base_url = $this->urlService->getRootUrl() . 'admin.php';
        $this->coreTabs->setContext(new CoreTabsContext(baseUrl: $base_url));
        $must_repost = false;

        // +-----------------------------------------------------------------------+
        // | Check Access and exit when user status is not ok                      |
        // +-----------------------------------------------------------------------+
        $this->accessControl->checkStatus(self::getTabStatus($page_mode));

        // +-----------------------------------------------------------------------+
        // | Add event handler                                                     |
        // +-----------------------------------------------------------------------+
        $this->eventDispatcher->addTypedHandler(NbmRenderGlobalCustomizeMailContent::class, $this->renderGlobalCustomizeMailContent(...));
        $this->eventDispatcher->dispatchNotify(new NbmEventHandlerAdded());

        // +-----------------------------------------------------------------------+
        // | Insert new users with mails                                           |
        // +-----------------------------------------------------------------------+
        if (count($post) === 0) {
            // No insert data in post mode
            self::insertNewDataUserMailNotification($this->lang, $nbmSender, $this->redirectService, $this->urlService, $this->sessionService, $this->currentConfig);
        }

        // +-----------------------------------------------------------------------+
        // | Treatment of tab post                                                 |
        // +-----------------------------------------------------------------------+

        if ($post !== []) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
        }

        switch ($page_mode) {
            case 'param':

                if (isset($post['param_submit'])) {
                    $nbm_send_mail_as = $post['nbm_send_mail_as'] ?? null;
                    $post['nbm_send_mail_as'] = strip_tags(is_string($nbm_send_mail_as) ? $nbm_send_mail_as : '');

                    $this->inputValidator
                        ->validate('nbm_send_html_mail', $post, false, '/^(true|false)$/');
                    $this->inputValidator
                        ->validate('nbm_send_detailed_content', $post, false, '/^(true|false)$/');
                    $this->inputValidator
                        ->validate('nbm_send_recent_post_dates', $post, false, '/^(true|false)$/');

                    $updated_param_count = 0;
                    // Update param
                    foreach ($this->configService->getParamsAndValuesLike('nbm\\_%') as $nbm_user) {
                        if ($nbm_user['param'] === '') {
                            continue;
                        }
                        if (isset($post[$nbm_user['param']])) {
                            $post_value = $post[$nbm_user['param']];
                            $value = is_string($post_value) ? $post_value : '';
                            $this->configService->confUpdateParam($nbm_user['param'], $value, true);
                            $updated_param_count++;
                        }
                    }

                    $template->assign(
                        [
                            'save_success' => $this->translator->plural(
                                '%d parameter was updated.',
                                '%d parameters were updated.',
                                $updated_param_count
                            ),
                        ]
                    );
                }

                // no break
            case 'subscribe':

                if (isset($post['falsify']) and isset($post['cat_true']) and is_array($post['cat_true'])) {
                    $check_key_treated = $nbmSender->unsubscribeNotificationByMail(true, array_values($post['cat_true']));
                    $must_repost = self::doTimeoutTreatment($nbmSender, 'cat_true', $post, $check_key_treated);
                } elseif (isset($post['trueify']) and isset($post['cat_false']) and is_array($post['cat_false'])) {
                    $check_key_treated = $nbmSender->subscribeNotificationByMail(true, array_values($post['cat_false']));
                    $must_repost = self::doTimeoutTreatment($nbmSender, 'cat_false', $post, $check_key_treated);
                }
                break;

            case 'send':

                if (
                    isset($post['send_submit'])
                    and isset($post['send_selection']) and is_array($post['send_selection'])
                    and isset($post['send_customize_mail_content']) and is_string($post['send_customize_mail_content'])
                ) {
                    $check_key_treated = $nbmSender->sendMailNotifications(
                        'send',
                        array_values($post['send_selection']),
                        stripslashes($post['send_customize_mail_content'])
                    );
                    $must_repost = self::doTimeoutTreatment($nbmSender, 'send_selection', $post, $check_key_treated);
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
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'U_HELP' => $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=notification_by_mail',
                'F_ACTION' => $base_url . $this->urlService->getQueryStringDiff([]),
            ]
        );

        if ($this->accessControl->isAuthorizeStatus(AccessLevel::Webmaster)) {
            // TabSheet
            $tabsheet = new Tabsheet();
            $tabsheet->set_id('nbm');
            $tabsheet->select($page_mode);
            $tabsheet->assign($this->currentTemplate);
        }

        if ($must_repost) {
            // Get name of submit button
            $repost_submit_name = '';
            if (isset($post['falsify'])) {
                $repost_submit_name = 'falsify';
            } elseif (isset($post['trueify'])) {
                $repost_submit_name = 'trueify';
            } elseif (isset($post['send_submit'])) {
                $repost_submit_name = 'send_submit';
            }

            $template->assign('REPOST_SUBMIT_NAME', $repost_submit_name);
        }

        switch ($page_mode) {
            case 'param':

                $template->assign(
                    $page_mode,
                    [
                        'SEND_HTML_MAIL' => $this->currentConfig->nbmSendHtmlMail(),
                        'SEND_MAIL_AS' => $this->currentConfig->nbmSendMailAs(),
                        'SEND_DETAILED_CONTENT' => $this->currentConfig->nbmSendDetailedContent(),
                        'COMPLEMENTARY_MAIL_CONTENT' => $this->currentConfig->nbmComplementaryMailContent(),
                        'SEND_RECENT_POST_DATES' => $this->currentConfig->nbmSendRecentPostDates(),
                    ]
                );
                break;

            case 'subscribe':

                $template->assign($page_mode, true);

                $template->assign(
                    [
                        'L_CAT_OPTIONS_TRUE' => $this->lang->t('Subscribed'),
                        'L_CAT_OPTIONS_FALSE' => $this->lang->t('Unsubscribed'),
                    ]
                );

                $data_users = $nbmSender->getUserNotifications('subscribe');

                $opt_true = [];
                $opt_true_selected = [];
                $opt_false = [];
                $opt_false_selected = [];
                foreach ($data_users as $nbm_user) {
                    if (SqlDialect::getBoolean($nbm_user->enabled)) {
                        $opt_true[$nbm_user->checkKey] = stripslashes($nbm_user->username) . '[' . $nbm_user->mailAddress . ']';
                        if (isset($post['falsify']) and isset($post['cat_true']) and is_array($post['cat_true']) and in_array($nbm_user->checkKey, $post['cat_true'], true)) {
                            $opt_true_selected[] = $nbm_user->checkKey;
                        }
                    } else {
                        $opt_false[$nbm_user->checkKey] = stripslashes($nbm_user->username) . '[' . $nbm_user->mailAddress . ']';
                        if (isset($post['trueify']) and isset($post['cat_false']) and is_array($post['cat_false']) and in_array($nbm_user->checkKey, $post['cat_false'], true)) {
                            $opt_false_selected[] = $nbm_user->checkKey;
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
                  (isset($post['send_customize_mail_content']) and is_string($post['send_customize_mail_content']))
                    ? stripslashes($post['send_customize_mail_content'])
                    : $this->currentConfig->nbmComplementaryMailContent();

                $post_send_selection = (isset($post['send_selection']) and is_array($post['send_selection']))
                    ? $post['send_selection']
                    : [];

                if ((bool) count($data_users)) {
                    foreach ($data_users as $nbm_user) {
                        if (
                            (! $must_repost) or // Not timeout, normal treatment
                            in_array($nbm_user->checkKey, $post_send_selection, true)  // Must be repost, show only user to send
                        ) {
                            $tpl_var['users'][] =
                              [
                                  'ID' => $nbm_user->checkKey,
                                  'CHECKED' => ( // not check if not selected,  on init select<all
                                      isset($post['send_selection']) and // not init
                                      ! in_array($nbm_user->checkKey, $post_send_selection, true) // not selected
                                  ) ? '' : 'checked="checked"',
                                  'USERNAME' => stripslashes($nbm_user->username),
                                  'EMAIL' => $nbm_user->mailAddress,
                                  'LAST_SEND' => $nbm_user->lastSend,
                              ];
                        }
                    }
                }
                $template->assign($page_mode, $tpl_var);

                // auth_key_duration is a plain int config value (see
                // include/config_default.inc.php).
                $auth_key_duration = $this->currentConfig->authKeyDuration();
                $auth_key_duration_num = $auth_key_duration;
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

        $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Send mail to users'));

        // +-----------------------------------------------------------------------+
        // | Sending html code                                                     |
        // +-----------------------------------------------------------------------+
        $template->assign_var_from_handle('ADMIN_CONTENT', 'notification_by_mail');
    }

    /**
     * Do timeout treatment in order to finish to send mails
     * @param array<int|string, mixed> $post: handle()'s own local post working
     *   copy, by reference -- the filtered-down selection must still be
     *   visible to handle()'s later display-section reads of the same key.
     * @param list<string> $check_key_treated: array of check_key treated
     * @return bool whether treatment timed out and must be reposted
     */
    private static function doTimeoutTreatment(NotificationByMailSender $nbmSender, string $post_keyname, array &$post, array $check_key_treated = []): bool
    {
        if ($nbmSender->isSendmailTimeout()) {
            if (isset($post[$post_keyname]) and is_array($post[$post_keyname])) {
                $post_count = count($post[$post_keyname]);
                $treated_count = count($check_key_treated);
                if ($treated_count !== 0) {
                    $time_refresh = (int) ceil((\Piwigo\Core\TimingHelper::getMoment() - $nbmSender->startTime()) * (float) $post_count / (float) $treated_count);
                } else {
                    $time_refresh = 0;
                }
                $post[$post_keyname] = array_diff(array_filter($post[$post_keyname], is_string(...)), $check_key_treated);

                \Piwigo\Core\PageState::current()->addError(Translator::get()->plural(
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
    private static function insertNewDataUserMailNotification(Lang $lang, NotificationByMailSender $nbmSender, RedirectServiceInterface $redirectService, UrlServiceInterface $urlService, SessionService $sessionService, \Piwigo\Config\CurrentConfig $currentConfig): void
    {
        // Recomputed rather than threaded from handle()'s own CoreTabs
        // value: this is the method's only real call site, and it already
        // receives the same $urlService instance handle() derives its own
        // base_url from.
        $base_url = $urlService->getRootUrl() . 'admin.php';

        $conn = DbConnection::build();
        $notificationByMailService = new \Piwigo\Notification\NotificationByMailService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Notification\UserMailNotificationEntity::class), $sessionService);

        // Set null mail_address empty
        $notificationByMailService->nullifyBlankEmails();

        // null mail_address are not selected in the list
        $rows = $notificationByMailService->getUsersWithoutNotificationRow();

        if ($rows !== []) {
            $inserts = [];
            $check_key_list = [];

            foreach ($rows as $nbm_user) {
                // Calculate key
                $nbm_user['check_key'] = $nbmSender->findAvailableCheckKey();

                // Save key
                $check_key_list[] = $nbm_user['check_key'];

                // Insert new nbm_users
                $inserts[] = [
                    'user_id' => $nbm_user['user_id'],
                    'check_key' => $nbm_user['check_key'],
                    'enabled' => 0, // By default if false, set to true with specific functions
                ];

                $nbm_username = $nbm_user['username'];
                $nbm_username = is_scalar($nbm_username) ? (string) $nbm_username : '';
                \Piwigo\Core\PageState::current()->addInfo($lang->t(
                    'User %s [%s] added.',
                    stripslashes($nbm_username),
                    $nbm_user['mail_address']
                ));
            }

            // Insert new nbm_users
            $notificationByMailService->insertNotifications($inserts);
            // Update field enabled with specific function
            $check_key_treated = $nbmSender->doSubscribeUnsubscribeNotificationByMail(
                true,
                $currentConfig->nbmDefaultValueUserEnabled(),
                $check_key_list
            );

            // On timeout simulate like tabsheet send
            if ($nbmSender->isSendmailTimeout()) {
                $untreated_check_key_list = array_values(array_diff($check_key_list, $check_key_treated));
                if (count($untreated_check_key_list) !== 0) {
                    $notificationByMailService->deleteByCheckKeys($untreated_check_key_list);

                    $redirectService->redirect($base_url . $urlService->getQueryStringDiff([], false), $lang->t('Operation in progress') . "\n" . $lang->t('Please wait...'));
                }
            }
        }
    }

    /**
     * Apply global functions to mail content
     * return customize mail content rendered
     */
    private function renderGlobalCustomizeMailContent(NbmRenderGlobalCustomizeMailContent $event): NbmRenderGlobalCustomizeMailContent
    {
        if ($this->currentConfig->nbmSendHtmlMail() and ! str_starts_with($event->customizeMailContent, '<')) {
            // On HTML mail, detects if the content are HTML format.
            // If it's plain text format, convert content to readable HTML
            $event->customizeMailContent = nl2br(htmlspecialchars($event->customizeMailContent));
        }

        return $event;
    }
}
