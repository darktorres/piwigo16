<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\Tables;
use Piwigo\Lang\Translator;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationService;
use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\Template\Template;

/**
 * Ported from admin/include/functions_notification_by_mail.inc.php (P23
 * batch 8b-7) -- 13 free functions threading a shared `global $env_nbm;`
 * array through a subscribe/unsubscribe/send-mail pipeline with
 * language-switch stacking. This class replaces that untyped array with
 * real instance state, constructed fresh per logical "mail-sending
 * session" -- matching the original code's own `$env_nbm` lifecycle,
 * which was always reset by `begin_users_env_nbm()` per use, never truly
 * request-global despite being a `global`.
 *
 * Placed in `Piwigo\Mail` (L3Presentation in deptrac.yaml), NOT
 * `Piwigo\Notification` (L2bExtendedDomain, home of the constructor-injected
 * `NotificationByMailService` below): this class holds a real
 * `Piwigo\Template\Template` instance (`$mailTemplate`, built via
 * `MailService::getMailTemplate()`), and L2bExtendedDomain may not depend on
 * L3Presentation -- same "Template dependency forces L3/L4 placement" shape
 * already documented on `NotificationByMailService`'s own docblock and on
 * `Piwigo\Mail\MailService`'s (`deptrac.yaml`'s own comment on the Mail
 * layer). L3->L2b (this class -> NotificationByMailService) and L4->L3
 * (both real callers, NotificationByMailSubController and NbmController,
 * both L4Integration) are both allowed downward dependencies.
 *
 * P23 batch 8c: every former free-function mail call
 * (`pwg_mail()`/`get_mail_template()`/`switch_lang_to()`/`switch_lang_back()`/
 * `get_str_email_format()`/`get_mail_sender_name()`/`format_email()`) now
 * calls `MailService` directly (same namespace, no `use` import needed).
 * `news()`/`news_exists()`/`get_recent_post_dates_array()`/
 * `get_title_recent_post_date()`/`get_html_description_recent_post_date()`
 * (`include/functions_notification.inc.php`, deleted) now call the
 * constructor-injected `Piwigo\Notification\NotificationService` (also
 * P23 batch 8c) -- `Notification` is L2bExtendedDomain, this class is
 * L3Presentation, same allowed downward direction as
 * `NotificationByMailService` above.
 * `set_make_full_url()`/`unset_make_full_url()`/`get_gallery_home_url()`/
 * `add_url_params()` now call the constructor-injected
 * `Piwigo\Core\UrlServiceInterface` directly, same allowed L3->L1 direction
 * as every other Url-family retarget this phase.
 * `\Piwigo\Db\MysqliDb::massUpdates()`/`::booleanToString()`/`::getBoolean()`
 * retargeted onto `Piwigo\Db\BatchWriter`/`Piwigo\Db\SqlDialect`
 * (Legacy Coupling Retirement: DI+DBAL migration, Phase 1b).
 */
final class NotificationByMailSender
{
    private readonly float $startTime;

    private float $sendmailTimeout;

    private bool $isSendmailTimeout = false;

    private ?\Piwigo\Users\User $saveCurrentUser = null;

    private bool $isToSendMail = false;

    private ?string $emailFormat = null;

    private ?string $sendAsName = null;

    private ?string $sendAsMailAddress = null;

    private ?string $sendAsMailFormatted = null;

    private int $errorOnMailCount = 0;

    private int $sentMailCount = 0;

    private ?string $msgInfo = null;

    private ?string $msgError = null;

    private ?Template $mailTemplate = null;

    public function __construct(
        private readonly NotificationByMailService $notificationByMailService,
        private readonly NotificationService $notificationService,
        private readonly \Piwigo\Db\BatchWriter $batchWriter,
        private readonly \Piwigo\Users\UserService $userService,
        private readonly \Piwigo\Auth\AuthService $authService,
        private readonly UrlServiceInterface $urlService,
    ) {
        $nbmMaxTreatmentTimeoutPercent = \Piwigo\Config\CurrentConfig::nbmMaxTreatmentTimeoutPercent();

        $this->startTime = \Piwigo\Core\TimingHelper::getMoment();
        $this->sendmailTimeout = (float) intval(ini_get('max_execution_time')) * $nbmMaxTreatmentTimeoutPercent;

        if ($this->sendmailTimeout <= 0) {
            $this->sendmailTimeout = \Piwigo\Config\CurrentConfig::nbmTreatmentTimeoutDefault();
        }
    }

    public function findAvailableCheckKey(): string
    {
        return $this->notificationByMailService->findAvailableCheckKey();
    }

    /**
     * @param array<array-key, mixed> $checkKeyList
     * @return list<UserMailNotification>
     */
    public function getUserNotifications(string $action, array $checkKeyList = [], bool|string $enabledFilterValue = ''): array
    {
        return $this->notificationByMailService->getUserNotifications($action, $checkKeyList, $enabledFilterValue);
    }

    public function checkSendmailTimeout(): bool
    {
        $isTimeout = (\Piwigo\Core\TimingHelper::getMoment() - $this->startTime) > $this->sendmailTimeout;
        $this->isSendmailTimeout = $isTimeout;

        return $isTimeout;
    }

    /**
     * Whether the last checkSendmailTimeout() call found the session past
     * its deadline -- read by callers that need to react to a timeout
     * detected during an earlier doSubscribeUnsubscribeNotificationByMail()/
     * doActionSendMailNotification() call, once it has already returned.
     */
    public function isSendmailTimeout(): bool
    {
        return $this->isSendmailTimeout;
    }

    public function startTime(): float
    {
        return $this->startTime;
    }

    public function beginUsersEnv(bool $isToSendMail = false): void
    {
        $this->saveCurrentUser = \Piwigo\Users\CurrentUser::get();
        $userLanguage = $this->saveCurrentUser->language;
        new MailService()
            ->switchLangTo($userLanguage !== '' ? $userLanguage : $this->userService->getDefaultLanguage());

        $this->isToSendMail = $isToSendMail;

        if ($isToSendMail) {
            $this->emailFormat = new MailService()
                ->getStrEmailFormat(\Piwigo\Db\SqlDialect::getBoolean(\Piwigo\Config\CurrentConfig::nbmSendHtmlMail()));

            // \Piwigo\Config\CurrentConfig::nbmSendMailAs() is admin-submitted free text (see
            // NotificationByMailSubController), always a string when set.
            $nbmSendMailAs = \Piwigo\Config\CurrentConfig::nbmSendMailAs();
            $sendAsName = $nbmSendMailAs !== ''
                ? $nbmSendMailAs
                : new MailService()
                    ->getMailSenderName();
            $this->sendAsName = $sendAsName;

            $sendAsMailAddress = new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())->getWebmasterMailAddress();
            $this->sendAsMailAddress = $sendAsMailAddress;

            $this->sendAsMailFormatted = new MailService()
                ->formatEmail($sendAsName, $sendAsMailAddress);

            $this->errorOnMailCount = 0;
            $this->sentMailCount = 0;
            $this->msgInfo = Lang::t('Mail sent to %s [%s].');
            $this->msgError = Lang::t('Error when sending email to %s [%s].');
        }
    }

    public function endUsersEnv(): void
    {
        if ($this->saveCurrentUser instanceof \Piwigo\Users\User) {
            \Piwigo\Users\CurrentUser::set($this->saveCurrentUser);
        }
        new MailService()
            ->switchLangBack();

        if ($this->isToSendMail) {
            $this->emailFormat = null;
            $this->sendAsName = null;
            $this->sendAsMailAddress = null;
            $this->sendAsMailFormatted = null;
            // Don't reset the counters -- matches the original's own
            // "Don t unset counter" comment.
            $this->msgInfo = null;
            $this->msgError = null;
        }

        $this->saveCurrentUser = null;
        $this->isToSendMail = false;
    }

    public function setUserOnEnv(UserMailNotification $nbmUser, bool $isActionSend): void
    {
        $user = $this->userService->buildUser($nbmUser->userId, true);
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

        $currentUserLanguage = \Piwigo\Users\CurrentUser::get()->language;
        new MailService()
            ->switchLangTo($currentUserLanguage !== '' ? $currentUserLanguage : $this->userService->getDefaultLanguage());

        if ($isActionSend) {
            $emailFormat = $this->emailFormat ?? new MailService()
                ->getStrEmailFormat(false);
            $mailTemplate = new MailService()
                ->getMailTemplate($emailFormat);
            $this->mailTemplate = $mailTemplate;
            $mailTemplate->set_filename('notification_by_mail', 'notification_by_mail.tpl');
        }
    }

    public function unsetUserOnEnv(): void
    {
        new MailService()
            ->switchLangBack();
        $this->mailTemplate = null;
    }

    public function incMailSentSuccess(UserMailNotification $nbmUser): void
    {
        $this->sentMailCount++;

        $msgInfo = $this->msgInfo ?? 'Mail sent to %s [%s].';
        \Piwigo\Core\PageState::current()->addInfo(sprintf($msgInfo, stripslashes($nbmUser->username), $nbmUser->mailAddress));
    }

    public function incMailSentFailed(UserMailNotification $nbmUser): void
    {
        $this->errorOnMailCount++;

        $msgError = $this->msgError ?? 'Error when sending email to %s [%s].';
        \Piwigo\Core\PageState::current()->addError(sprintf($msgError, stripslashes($nbmUser->username), $nbmUser->mailAddress));
    }

    public function displayCounterInfo(): void
    {
        if ($this->errorOnMailCount !== 0) {
            \Piwigo\Core\PageState::current()->addError(Translator::get()->plural(
                '%d mail was not sent.',
                '%d mails were not sent.',
                $this->errorOnMailCount
            ));

            if ($this->sentMailCount !== 0) {
                \Piwigo\Core\PageState::current()->addInfo(Translator::get()->plural(
                    '%d mail was sent.',
                    '%d mails were sent.',
                    $this->sentMailCount
                ));
            }
        } else {
            if ($this->sentMailCount === 0) {
                \Piwigo\Core\PageState::current()->addInfo(Lang::t('No mail to send.'));
            } else {
                \Piwigo\Core\PageState::current()->addInfo(Translator::get()->plural(
                    '%d mail was sent.',
                    '%d mails were sent.',
                    $this->sentMailCount
                ));
            }
        }
    }

    public function assignVarsNbmMailContent(UserMailNotification $nbmUser): void
    {
        $this->urlService->setMakeFullUrl();

        // getGalleryHomeUrl() is declared to return mixed; real callers
        // always get back a string URL, but that isn't visible through its
        // own signature.
        $galleryHomeUrl = $this->urlService->getGalleryHomeUrl();
        $galleryHomeUrlStr = is_string($galleryHomeUrl) ? $galleryHomeUrl : '';

        $emailFormat = $this->emailFormat ?? new MailService()
            ->getStrEmailFormat(false);
        $mailTemplate = $this->mailTemplate ?? new MailService()
            ->getMailTemplate($emailFormat);

        $mailTemplate->assign(
            [
                'USERNAME' => stripslashes($nbmUser->username),

                'SEND_AS_NAME' => $this->sendAsName,

                'UNSUBSCRIBE_LINK' => $this->urlService->addUrlParams($galleryHomeUrlStr . '/nbm.php', [
                    'unsubscribe' => $nbmUser->checkKey,
                ]),
                'SUBSCRIBE_LINK' => $this->urlService->addUrlParams($galleryHomeUrlStr . '/nbm.php', [
                    'subscribe' => $nbmUser->checkKey,
                ]),
                'CONTACT_EMAIL' => $this->sendAsMailAddress,
            ]
        );

        $this->urlService->unsetMakeFullUrl();
    }

    /**
     * Subscribe or unsubscribe notification by mail.
     *
     * @param array<int, mixed> $checkKeyList check_key list where action will be done
     * @return mixed[] check_key list treated
     */
    public function doSubscribeUnsubscribeNotificationByMail(bool $isAdminRequest, bool $isSubscribe, array $checkKeyList): array
    {
        $this->urlService->setMakeFullUrl();

        // \Piwigo\Config\CurrentConfig::galleryTitle() is always a string (config_default.inc.php),
        // but that isn't visible through $conf's own array<string, mixed> type.
        $galleryTitle = \Piwigo\Config\CurrentConfig::galleryTitle();

        $checkKeyTreated = [];
        $updatedDataCount = 0;
        $errorOnUpdatedDataCount = 0;

        if ($isSubscribe) {
            $msgInfo = Lang::t('User %s [%s] was added to the subscription list.');
            $msgError = Lang::t('User %s [%s] was not added to the subscription list.');
        } else {
            $msgInfo = Lang::t('User %s [%s] was removed from the subscription list.');
            $msgError = Lang::t('User %s [%s] was not removed from the subscription list.');
        }

        if (count($checkKeyList) !== 0) {
            $updates = [];
            $enabledValue = \Piwigo\Db\SqlDialect::booleanToInt($isSubscribe);
            $dataUsers = $this->getUserNotifications('subscribe', $checkKeyList, ! $isSubscribe);

            // Prepare message after change language
            $msgBreakTimeout = Lang::t('Time to send mail is limited. Others mails are skipped.');

            $this->beginUsersEnv(true);

            foreach ($dataUsers as $nbmUser) {
                if ($this->checkSendmailTimeout()) {
                    // Stop fill list on 'send', if the quota is override
                    \Piwigo\Core\PageState::current()->addError($msgBreakTimeout);
                    break;
                }

                $checkKeyTreated[] = $nbmUser->checkKey;

                $doUpdate = true;
                if ($nbmUser->mailAddress !== '') {
                    $this->setUserOnEnv($nbmUser, true);

                    $subject = '[' . $galleryTitle . '] ' . ($isSubscribe ? Lang::t('Subscribe to notification by mail') : Lang::t('Unsubscribe from notification by mail'));

                    $this->assignVarsNbmMailContent($nbmUser);

                    $sectionActionBy = ($isSubscribe ? 'subscribe_by_' : 'unsubscribe_by_');
                    $sectionActionBy .= ($isAdminRequest ? 'admin' : 'himself');

                    $emailFormat = $this->emailFormat ?? new MailService()
                        ->getStrEmailFormat(false);
                    $mailTemplate = $this->mailTemplate ?? new MailService()
                        ->getMailTemplate($emailFormat);

                    $mailTemplate->assign(
                        [
                            $sectionActionBy => true,
                            'GOTO_GALLERY_TITLE' => $galleryTitle,
                            'GOTO_GALLERY_URL' => $this->urlService->getGalleryHomeUrl(),
                        ]
                    );

                    $sendAsMailFormatted = $this->sendAsMailFormatted ?? '';

                    $ret = new MailService()
                        ->mail(
                            [
                                'name' => stripslashes($nbmUser->username),
                                'email' => $nbmUser->mailAddress,
                            ],
                            [
                                'from' => $sendAsMailFormatted,
                                'subject' => $subject,
                                'email_format' => $emailFormat,
                                'content' => $mailTemplate->parse('notification_by_mail', true),
                                'content_format' => $emailFormat,
                            ]
                        );

                    if ($ret) {
                        $this->incMailSentSuccess($nbmUser);
                    } else {
                        $this->incMailSentFailed($nbmUser);
                        $doUpdate = false;
                    }

                    $this->unsetUserOnEnv();
                }

                if ($doUpdate) {
                    $updates[] = [
                        'check_key' => $nbmUser->checkKey,
                        'enabled' => $enabledValue,
                    ];
                    ++$updatedDataCount;
                    \Piwigo\Core\PageState::current()->addInfo(sprintf($msgInfo, stripslashes($nbmUser->username), $nbmUser->mailAddress));
                } else {
                    ++$errorOnUpdatedDataCount;
                    \Piwigo\Core\PageState::current()->addError(sprintf($msgError, stripslashes($nbmUser->username), $nbmUser->mailAddress));
                }
            }

            $this->endUsersEnv();

            $this->displayCounterInfo();

            $this->batchWriter->massUpdate(
                Tables::userMailNotification(),
                [
                    'primary' => ['check_key'],
                    'update' => ['enabled'],
                ],
                $updates
            );
        }

        \Piwigo\Core\PageState::current()->addInfo(Translator::get()->plural(
            '%d user was updated.',
            '%d users were updated.',
            $updatedDataCount
        ));

        if ($errorOnUpdatedDataCount !== 0) {
            \Piwigo\Core\PageState::current()->addError(Translator::get()->plural(
                '%d user was not updated.',
                '%d users were not updated.',
                $errorOnUpdatedDataCount
            ));
        }

        $this->urlService->unsetMakeFullUrl();

        return $checkKeyTreated;
    }

    /**
     * Send mail for notification to all users.
     * Returns the list of "selected" users for 'list_to_send'.
     * Returns the list of "treated" check_key for 'send'.
     *
     * @param array<int, mixed> $checkKeyList
     * @return array<int, mixed>
     */
    public function sendMailNotifications(string $action = 'list_to_send', array $checkKeyList = [], mixed $customizeMailContent = ''): array
    {
        $returnList = [];

        if (in_array($action, ['list_to_send', 'send'], true)) {
            // Env::now() rather than SQL's NOW() -- matches
            // SessionRepository/CommentRepository's own established
            // reasoning: invisible to PIWIGO_TEST_NOW, so a fixture-dated
            // 'last_send' comparison would silently use two different
            // clocks otherwise.
            $dbnow = \Piwigo\Core\Env::now()->format('Y-m-d H:i:s');

            $isActionSend = ($action === 'send');

            // disabled and null mail_address are not selected in the list
            $dataUsers = $this->getUserNotifications('send', $checkKeyList);

            // List all if it's define on options or on timeout
            $isListAllWithoutTest = ($this->isSendmailTimeout or \Piwigo\Config\CurrentConfig::nbmListAllEnabledUsersToSend());

            // Check if exist news to list user or send mails
            if ((! $isListAllWithoutTest) or ($isActionSend)) {
                if (count($dataUsers) > 0) {
                    $datas = [];

                    if (! isset($customizeMailContent)) {
                        $customizeMailContent = \Piwigo\Config\CurrentConfig::nbmComplementaryMailContent();
                    }

                    $customizeMailContent =
                      \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('nbm_render_global_customize_mail_content', $customizeMailContent);

                    // Prepare message after change language
                    if ($isActionSend) {
                        $msgBreakTimeout = Lang::t('Time to send mail is limited. Others mails are skipped.');
                    } else {
                        $msgBreakTimeout = Lang::t('Prepared time for list of users to send mail is limited. Others users are not listed.');
                    }

                    $this->beginUsersEnv($isActionSend);

                    foreach ($dataUsers as $nbmUser) {
                        if ((! $isActionSend) and $this->checkSendmailTimeout()) {
                            // Stop fill list on 'list_to_send', if the quota is override
                            \Piwigo\Core\PageState::current()->addInfo($msgBreakTimeout);
                            break;
                        }
                        if (($isActionSend) and $this->checkSendmailTimeout()) {
                            // Stop fill list on 'send', if the quota is override
                            \Piwigo\Core\PageState::current()->addError($msgBreakTimeout);
                            break;
                        }

                        $this->setUserOnEnv($nbmUser, $isActionSend);
                        if ($isActionSend) {
                            $auth = null;
                            $addUrlParams = [];

                            $authKey = $this->authService->createUserAuthKey($nbmUser->userId, $nbmUser->status);

                            if ($authKey !== false and is_string($authKey['auth_key'])) {
                                $auth = $authKey['auth_key'];
                                $addUrlParams['auth'] = $auth;
                            }

                            $this->urlService->setMakeFullUrl();
                            // Fill return list of "treated" check_key for 'send'
                            $returnList[] = $nbmUser->checkKey;

                            // These nbm_* flags are plain booleans in
                            // include/config_default.inc.php; (bool) is always a
                            // safe, non-failing cast for a mixed config value.
                            $nbmSendDetailedContent = \Piwigo\Config\CurrentConfig::nbmSendDetailedContent();
                            $nbmSendHtmlMail = \Piwigo\Config\CurrentConfig::nbmSendHtmlMail();

                            // Only ever read below inside `if
                            // ($nbmSendDetailedContent)` (where it's also
                            // assigned) -- declared here so Psalm can see
                            // it's always defined by the time it's used.
                            $news = [];
                            if ($nbmSendDetailedContent) {
                                $news = $this->notificationService->news($nbmUser->lastSend, $dbnow, false, $nbmSendHtmlMail, $auth);
                                $existData = count($news) > 0;
                            } else {
                                $existData = $this->notificationService->newsExists($nbmUser->lastSend, $dbnow);
                            }

                            if ($existData) {
                                // gallery_title is always a configured string
                                // (see include/config_default.inc.php).
                                $galleryTitle = \Piwigo\Config\CurrentConfig::galleryTitle();
                                $subject = '[' . $galleryTitle . '] ' . Lang::t('New photos added');

                                $mailEmailFormat = $this->emailFormat ?? new MailService()
                                    ->getStrEmailFormat($nbmSendHtmlMail);
                                $mailTemplate = $this->mailTemplate ?? new MailService()
                                    ->getMailTemplate($mailEmailFormat);

                                // Assign current var for nbm mail
                                $this->assignVarsNbmMailContent($nbmUser);

                                if ($nbmUser->lastSend !== null) {
                                    $mailTemplate->assign(
                                        'content_new_elements_between',
                                        [
                                            'DATE_BETWEEN_1' => $nbmUser->lastSend,
                                            'DATE_BETWEEN_2' => $dbnow,
                                        ]
                                    );
                                } else {
                                    $mailTemplate->assign(
                                        'content_new_elements_single',
                                        [
                                            'DATE_SINGLE' => $dbnow,
                                        ]
                                    );
                                }

                                if ($nbmSendDetailedContent) {
                                    $mailTemplate->assign('global_new_lines', $news);
                                }

                                $nbmUserCustomizeMailContent =
                                  \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                                      'nbm_render_user_customize_mail_content',
                                      $customizeMailContent,
                                      $nbmUser
                                  );
                                if (! in_array($nbmUserCustomizeMailContent, [null, false, 0, '0', '', []], true)) {
                                    $mailTemplate->assign(
                                        'custom_mail_content',
                                        $nbmUserCustomizeMailContent
                                    );
                                }

                                $nbmSendRecentPostDates = \Piwigo\Config\CurrentConfig::nbmSendRecentPostDates();
                                if ($nbmSendHtmlMail and $nbmSendRecentPostDates) {
                                    // Real bug found via PHPStan (same shape as
                                    // FeedController's RSS equivalent):
                                    // CurrentConfig::recentPostDates() returns a typed
                                    // NotificationConfig object, not a raw array --
                                    // the old is_array() check here was always
                                    // false, so NBM recent-post-date limits always
                                    // fell back to getRecentPostDatesArray()'s own
                                    // hardcoded 3/3/3 defaults.
                                    $nbmConfig = \Piwigo\Config\CurrentConfig::recentPostDates()->nbm;
                                    $nbmRecentPostDatesArgs = [
                                        'max_dates' => $nbmConfig->maxDates,
                                        'max_elements' => $nbmConfig->maxElements,
                                        'max_cats' => $nbmConfig->maxCats,
                                    ];

                                    $recentPostDates = $this->notificationService->getRecentPostDatesArray($nbmRecentPostDatesArgs);
                                    foreach ($recentPostDates as $dateDetail) {
                                        // getRecentPostDatesArray() is typed to
                                        // return array<int|string, mixed>; each
                                        // element is really one getRecentPostDates()
                                        // date-detail row (array<string, mixed>).
                                        assert(is_array($dateDetail));
                                        /** @var array<string, mixed> $dateDetail */
                                        $mailTemplate->append(
                                            'recent_posts',
                                            [
                                                'TITLE' => $this->notificationService->getTitleRecentPostDate($dateDetail),
                                                'HTML_DATA' => $this->notificationService->getHtmlDescriptionRecentPostDate($dateDetail, $auth),
                                            ]
                                        );
                                    }
                                }

                                $galleryHomeUrl = $this->urlService->getGalleryHomeUrl();
                                $mailTemplate->assign(
                                    [
                                        'GOTO_GALLERY_TITLE' => $galleryTitle,
                                        'GOTO_GALLERY_URL' => $this->urlService->addUrlParams(is_string($galleryHomeUrl) ? $galleryHomeUrl : '', $addUrlParams),
                                        'SEND_AS_NAME' => $this->sendAsName,
                                    ]
                                );

                                $mailArgs = [
                                    'from' => $this->sendAsMailFormatted,
                                    'subject' => $subject,
                                    'email_format' => $mailEmailFormat,
                                    'content' => $mailTemplate->parse('notification_by_mail', true),
                                    'content_format' => $mailEmailFormat,
                                ];
                                if (is_string($auth)) {
                                    $mailArgs['auth_key'] = $auth;
                                }

                                $ret = new MailService()
                                    ->mail(
                                        [
                                            'name' => stripslashes($nbmUser->username),
                                            'email' => $nbmUser->mailAddress,
                                        ],
                                        $mailArgs
                                    );

                                if ($ret) {
                                    $this->incMailSentSuccess($nbmUser);

                                    $datas[] = [
                                        'user_id' => $nbmUser->userId,
                                        'last_send' => $dbnow,
                                    ];
                                } else {
                                    $this->incMailSentFailed($nbmUser);
                                }

                                $this->urlService->unsetMakeFullUrl();
                            }
                        } else {
                            if ($this->notificationService->newsExists($nbmUser->lastSend, $dbnow)) {
                                // Fill return list of "selected" users for 'list_to_send'
                                $returnList[] = $nbmUser;
                            }
                        }

                        $this->unsetUserOnEnv();
                    }

                    $this->endUsersEnv();

                    if ($isActionSend) {
                        $this->batchWriter->massUpdate(
                            Tables::userMailNotification(),
                            [
                                'primary' => ['user_id'],
                                'update' => ['last_send'],
                            ],
                            $datas
                        );

                        $this->displayCounterInfo();
                    }
                } else {
                    if ($isActionSend) {
                        \Piwigo\Core\PageState::current()->addError(Lang::t('No user to send notifications by mail.'));
                    }
                }
            } else {
                // Quick List, don't check news
                // Fill return list of "selected" users for 'list_to_send'
                $returnList = $dataUsers;
            }
        }

        // Return list of "selected" users for 'list_to_send'
        // Return list of "treated" check_key for 'send'
        return $returnList;
    }

    /**
     * @param array<int, mixed> $checkKeyList check_key list where action will be done
     * @return mixed[] check_key list treated
     */
    public function subscribeNotificationByMail(bool $isAdminRequest, array $checkKeyList = []): array
    {
        return $this->doSubscribeUnsubscribeNotificationByMail($isAdminRequest, true, $checkKeyList);
    }

    /**
     * @param array<int, mixed> $checkKeyList check_key list where action will be done
     * @return mixed[] check_key list treated
     */
    public function unsubscribeNotificationByMail(bool $isAdminRequest, array $checkKeyList = []): array
    {
        return $this->doSubscribeUnsubscribeNotificationByMail($isAdminRequest, false, $checkKeyList);
    }

    /**
     * Add quote to all elements of a check_key list.
     *
     * @param array<int, mixed> $checkKeyList
     * @return string[] quoted check key list
     */
    public static function quoteCheckKeyList(array $checkKeyList = []): array
    {
        // $checkKeyList may come straight from $_POST (see
        // NotificationByMailSubController), so its elements are only
        // provably string-castable once filtered.
        $stringCheckKeys = array_filter($checkKeyList, is_string(...));

        return array_map(fn (string $s): string => '\'' . $s . '\'', $stringCheckKeys);
    }
}
