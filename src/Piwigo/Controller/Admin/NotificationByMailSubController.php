<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\NotificationByMailFramePageContext;
use Piwigo\Controller\Admin\Projection\NotificationByMailParamPageContext;
use Piwigo\Controller\Admin\Projection\NotificationByMailSendPageContext;
use Piwigo\Controller\Admin\Projection\NotificationByMailSubscribePageContext;
use Piwigo\Controller\Admin\Request\NotificationByMailRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Lang\Translator;
use Piwigo\Mail\Event\NbmRenderGlobalCustomizeMailContent;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\Projection\NotificationInsertRow;
use Piwigo\Notification\UserMailNotificationEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/notification_by_mail.php (page slug "notification_by_mail").
 *
 * `Piwigo\Mail\NotificationByMailSender` is constructed once as `$nbmSender`
 * near the top of `handle()` and threaded explicitly into the private
 * methods below that need it. It lives in `Piwigo\Mail` (L3Presentation)
 * rather than `Piwigo\Notification` (L2bExtendedDomain, which may not
 * depend on `Piwigo\Template\Template`) -- see its own docblock for why.
 *
 * admin.php already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so this
 * controller does not repeat that check. It does call
 * `checkStatus(self::getTabStatus($page_mode))`: `getTabStatus()` requires
 * `AccessLevel::Webmaster` (not just Administrator) for the "param"/
 * "subscribe" tabs, a higher bar than admin.php's own gate for those two of
 * the three tabs.
 *
 * The CSRF check before the `$page_mode` switch covers every mutation
 * uniformly across all 3 tabs. `insertNewDataUserMailNotification()` runs
 * unconditionally on every GET page load and can send mail via
 * `pwg_mail()` for any user missing a notification-subscription row; this
 * is not a CSRF gap since nothing attacker-controlled is written and the
 * outcome is fully determined by server config and existing DB state.
 *
 * `renderGlobalCustomizeMailContent()` is registered as a typed event
 * handler via
 * `EventDispatcher::addTypedHandler(NbmRenderGlobalCustomizeMailContent::class, ...)`,
 * which requires a `Closure`; it's passed via first-class callable syntax
 * since a bare string can't resolve to a private method from outside the
 * class.
 */
final readonly class NotificationByMailSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private CoreTabs $coreTabs,
        private SessionService $sessionService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private CurrentTemplate $currentTemplate,
        private HtmlRenderingInterface $htmlRenderer,
        private NotificationByMailSender $notificationByMailSender,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private PageState $pageState,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;

        $nbmSender = $this->notificationByMailSender;

        $notificationByMailRequest = NotificationByMailRequest::fromGlobals($this->inputValidator);
        $page_mode = $notificationByMailRequest->pageMode;
        $post = $notificationByMailRequest->post;

        // Consumed by CoreTabs::addCoreTabs()'s own 'nbm' case (triggered
        // synchronously inside Tabsheet::select() further down -- must be
        // set before that call, not dead code) and by this method's own
        // F_ACTION assignment below.
        $base_url = $this->urlService->getRootUrl() . 'admin.php';
        $this->coreTabs->setContext(new CoreTabsContext(baseUrl: $base_url));
        $must_repost = false;
        $save_success = null;

        $this->accessControl->checkStatus(self::getTabStatus($page_mode));

        $this->eventDispatcher->addTypedHandler(NbmRenderGlobalCustomizeMailContent::class, $this->renderGlobalCustomizeMailContent(...));

        if (count($post) === 0) {
            // No insert data in post mode
            $this->insertNewDataUserMailNotification($this->lang, $nbmSender, $this->redirectService, $this->urlService, $this->sessionService, $this->currentConfig);
        }

        if ($post !== []) {
            $this->csrfService
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
                        if ($nbm_user->param === '') {
                            continue;
                        }
                        if (isset($post[$nbm_user->param])) {
                            $post_value = $post[$nbm_user->param];
                            $value = is_string($post_value) ? $post_value : '';
                            $this->configService->confUpdateParam($nbm_user->param, $value, true);
                            $updated_param_count++;
                        }
                    }

                    $save_success = $this->translator->plural(
                        '%d parameter was updated.',
                        '%d parameters were updated.',
                        $updated_param_count
                    );
                }

                // no break
            case 'subscribe':

                if (isset($post['falsify']) and isset($post['cat_true']) and is_array($post['cat_true'])) {
                    $check_key_treated = $nbmSender->unsubscribeNotificationByMail(true, array_values($post['cat_true']));
                    $must_repost = $this->doTimeoutTreatment($nbmSender, 'cat_true', $post, $check_key_treated);
                } elseif (isset($post['trueify']) and isset($post['cat_false']) and is_array($post['cat_false'])) {
                    $check_key_treated = $nbmSender->subscribeNotificationByMail(true, array_values($post['cat_false']));
                    $must_repost = $this->doTimeoutTreatment($nbmSender, 'cat_false', $post, $check_key_treated);
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
                        $post['send_customize_mail_content']
                    );
                    $must_repost = $this->doTimeoutTreatment($nbmSender, 'send_selection', $post, $check_key_treated);
                }

        }

        if ($this->accessControl->isAuthorizeStatus(AccessLevel::Webmaster)) {
            // TabSheet
            $tabsheet = new Tabsheet();
            $tabsheet->setId('nbm');
            $tabsheet->select($page_mode, $this->eventDispatcher);
            $tabsheet->assign($this->currentTemplate);
        }

        $repost_submit_name_value = null;
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

            $repost_submit_name_value = $repost_submit_name;
        }

        switch ($page_mode) {
            case 'param':

                $template->assignContext(new NotificationByMailParamPageContext(
                    sendHtmlMail: $this->currentConfig->nbmSendHtmlMail,
                    sendMailAs: $this->currentConfig->nbmSendMailAs,
                    sendDetailedContent: $this->currentConfig->nbmSendDetailedContent,
                    complementaryMailContent: $this->currentConfig->nbmComplementaryMailContent,
                    sendRecentPostDates: $this->currentConfig->nbmSendRecentPostDates,
                ));
                break;

            case 'subscribe':

                $data_users = $nbmSender->getUserNotifications('subscribe');

                $opt_true = [];
                $opt_true_selected = [];
                $opt_false = [];
                $opt_false_selected = [];
                foreach ($data_users as $nbm_user) {
                    if ($nbm_user->enabled) {
                        $opt_true[$nbm_user->checkKey] = $nbm_user->username . '[' . $nbm_user->mailAddress . ']';
                        if (isset($post['falsify']) and isset($post['cat_true']) and is_array($post['cat_true']) and in_array($nbm_user->checkKey, $post['cat_true'], true)) {
                            $opt_true_selected[] = $nbm_user->checkKey;
                        }
                    } else {
                        $opt_false[$nbm_user->checkKey] = $nbm_user->username . '[' . $nbm_user->mailAddress . ']';
                        if (isset($post['trueify']) and isset($post['cat_false']) and is_array($post['cat_false']) and in_array($nbm_user->checkKey, $post['cat_false'], true)) {
                            $opt_false_selected[] = $nbm_user->checkKey;
                        }
                    }
                }
                $template->assignContext(new NotificationByMailSubscribePageContext(
                    lCatOptionsTrue: $this->lang->t('Subscribed'),
                    lCatOptionsFalse: $this->lang->t('Unsubscribed'),
                    categoryOptionTrue: $opt_true,
                    categoryOptionTrueSelected: $opt_true_selected,
                    categoryOptionFalse: $opt_false,
                    categoryOptionFalseSelected: $opt_false_selected,
                ));
                $template->assignVarFromTemplate('DOUBLE_SELECT', 'double_select.latte');
                break;

            case 'send':

                $tpl_var = [
                    'users' => [],
                ];

                $data_users = $nbmSender->sendMailNotifications('list_to_send');

                $tpl_var['CUSTOMIZE_MAIL_CONTENT'] =
                  (isset($post['send_customize_mail_content']) and is_string($post['send_customize_mail_content']))
                    ? $post['send_customize_mail_content']
                    : $this->currentConfig->nbmComplementaryMailContent;

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
                                  'USERNAME' => $nbm_user->username,
                                  'EMAIL' => $nbm_user->mailAddress,
                                  'LAST_SEND' => $nbm_user->lastSend,
                              ];
                        }
                    }
                }
                // auth_key_duration is a plain int config value (see
                // include/config_default.inc.php).
                $auth_key_duration = $this->currentConfig->authKeyDuration;
                $auth_key_duration_num = $auth_key_duration;
                $auth_key_duration_value = null;
                if ($auth_key_duration_num > 0) {
                    $auth_key_since = strtotime('now -' . $auth_key_duration_num . ' second');
                    // the relative time expression above is always syntactically valid
                    assert($auth_key_since !== false);
                    $auth_key_duration_value = DateHelper::timeSince($auth_key_since, 'second', null, false);
                }

                $template->assignContext(new NotificationByMailSendPageContext(
                    users: $tpl_var['users'],
                    customizeMailContent: $tpl_var['CUSTOMIZE_MAIL_CONTENT'],
                    authKeyDuration: $auth_key_duration_value,
                ));

                break;

        }

        $template->assignContext(new NotificationByMailFramePageContext(
            saveSuccess: $save_success,
            pwgToken: $this->csrfService
                ->getToken(),
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=notification_by_mail',
            fAction: $base_url . $this->urlService->getQueryStringDiff([]),
            repostSubmitName: $repost_submit_name_value,
            adminPageTitle: $this->lang->t('Send mail to users'),
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'notification_by_mail.latte');
    }

    /**
     * Do timeout treatment in order to finish to send mails
     * @param array<int|string, mixed> $post: handle()'s own local post working
     *   copy, by reference -- the filtered-down selection must still be
     *   visible to handle()'s later display-section reads of the same key.
     * @param list<string> $check_key_treated: array of check_key treated
     * @return bool whether treatment timed out and must be reposted
     */
    private function doTimeoutTreatment(NotificationByMailSender $nbmSender, string $post_keyname, array &$post, array $check_key_treated = []): bool
    {
        if ($nbmSender->isSendmailTimeout()) {
            if (isset($post[$post_keyname]) and is_array($post[$post_keyname])) {
                $post_count = count($post[$post_keyname]);
                $treated_count = count($check_key_treated);
                if ($treated_count !== 0) {
                    $time_refresh = (int) ceil((TimingHelper::getMoment() - $nbmSender->startTime()) * (float) $post_count / (float) $treated_count);
                } else {
                    $time_refresh = 0;
                }
                $post[$post_keyname] = array_diff(array_filter($post[$post_keyname], is_string(...)), $check_key_treated);

                $this->pageState->addError($this->translator->plural(
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
    private function insertNewDataUserMailNotification(Lang $lang, NotificationByMailSender $nbmSender, RedirectServiceInterface $redirectService, UrlServiceInterface $urlService, SessionService $sessionService, CurrentConfig $currentConfig): void
    {
        // Recomputed rather than threaded from handle()'s own CoreTabs
        // value: this is the method's only real call site, and it already
        // receives the same $urlService instance handle() derives its own
        // base_url from.
        $base_url = $urlService->getRootUrl() . 'admin.php';

        $notificationByMailService = new NotificationByMailService($this->entityManager->getRepository(UserMailNotificationEntity::class), $sessionService);

        // Set null mail_address empty
        $notificationByMailService->nullifyBlankEmails();

        // null mail_address are not selected in the list
        $rows = $notificationByMailService->getUsersWithoutNotificationRow();

        if ($rows !== []) {
            $inserts = [];
            $check_key_list = [];

            foreach ($rows as $nbm_user) {
                // Calculate key
                $checkKey = $nbmSender->findAvailableCheckKey();

                // Save key
                $check_key_list[] = $checkKey;

                // Insert new nbm_users
                $inserts[] = new NotificationInsertRow(
                    userId: $nbm_user->userId,
                    checkKey: $checkKey,
                    enabled: 0, // By default if false, set to true with specific functions
                );

                $this->pageState->addInfo($lang->t(
                    'User %s [%s] added.',
                    $nbm_user->username ?? '',
                    $nbm_user->mailAddress
                ));
            }

            // Insert new nbm_users
            $notificationByMailService->insertNotifications($inserts);
            // Update field enabled with specific function
            $check_key_treated = $nbmSender->doSubscribeUnsubscribeNotificationByMail(
                true,
                $currentConfig->nbmDefaultValueUserEnabled,
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
        if ($this->currentConfig->nbmSendHtmlMail and ! str_starts_with($event->customizeMailContent, '<')) {
            // On HTML mail, detects if the content are HTML format.
            // If it's plain text format, convert content to readable HTML
            $event->customizeMailContent = nl2br(htmlspecialchars($event->customizeMailContent));
        }

        return $event;
    }
}
