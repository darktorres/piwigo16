<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AuthService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Mail\NbmRenderGlobalCustomizeMailContent;
use Piwigo\Lang\Translator;
use Piwigo\Mail\Projection\NbmMailContentPageContext;
use Piwigo\Mail\Projection\NbmNewsMailContext;
use Piwigo\Mail\Projection\NbmSubscribeActionMailContext;
use Piwigo\Notification\Event\NbmRenderUserCustomizeMailContent;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationService;
use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Threads a subscribe/unsubscribe/send-mail pipeline with language-switch
 * stacking through real instance state, constructed fresh per logical
 * "mail-sending session".
 *
 * Placed in `Piwigo\Mail` (L3Presentation in deptrac.yaml), NOT
 * `Piwigo\Notification` (L2bExtendedDomain, home of the constructor-injected
 * `NotificationByMailService` below): this class holds a real
 * `Piwigo\Template\Template` instance (`$mailTemplate`, built via
 * `MailService::getMailTemplate()`), and L2bExtendedDomain may not depend on
 * L3Presentation. L3->L2b (this class -> NotificationByMailService) and
 * L4->L3 (both real callers, NotificationByMailSubController and
 * NbmController, both L4Integration) are both allowed downward
 * dependencies.
 *
 * Mail sending/templating calls `MailService` directly (same namespace, no
 * `use` import needed). "What's new" queries call the constructor-injected
 * `Piwigo\Notification\NotificationService` -- `Notification` is
 * L2bExtendedDomain, this class is L3Presentation, same allowed downward
 * direction as `NotificationByMailService` above. URL building calls the
 * constructor-injected `Piwigo\Core\UrlServiceInterface` directly, same
 * allowed L3->L1 direction. Bulk row updates and boolean-column conversion
 * go through `Piwigo\Db\BatchWriter`/`Piwigo\Db\SqlDialect`.
 *
 * Every `$checkKeyList` param below stays mixed by design -- see
 * NotificationByMailService::getUserNotifications()'s own docblock: it
 * may come straight from $_POST (admin/notification_by_mail.php).
 */
final class NotificationByMailSender
{
    private readonly float $startTime;

    private float $sendmailTimeout;

    private bool $isSendmailTimeout = false;

    private ?User $saveCurrentUser = null;

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
        private readonly Lang $lang,
        private readonly NotificationByMailService $notificationByMailService,
        private readonly NotificationService $notificationService,
        private readonly BatchWriter $batchWriter,
        private readonly UserService $userService,
        private readonly AuthService $authService,
        private readonly UrlServiceInterface $urlService,
        private readonly Translator $translator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly MailService $mailer,
        private readonly CurrentConfig $currentConfig,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $nbmMaxTreatmentTimeoutPercent = $this->currentConfig->nbmMaxTreatmentTimeoutPercent;

        $this->startTime = TimingHelper::getMoment();
        $this->sendmailTimeout = (float) intval(ini_get('max_execution_time')) * $nbmMaxTreatmentTimeoutPercent;

        if ($this->sendmailTimeout <= 0) {
            $this->sendmailTimeout = $this->currentConfig->nbmTreatmentTimeoutDefault;
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
        $isTimeout = (TimingHelper::getMoment() - $this->startTime) > $this->sendmailTimeout;
        $this->isSendmailTimeout = $isTimeout;

        return $isTimeout;
    }

    /**
     * Whether the last checkSendmailTimeout() call found the session past
     * its deadline -- read by callers that need to react to a timeout
     * detected during an earlier doSubscribeUnsubscribeNotificationByMail()/
     * sendMailNotifications() call, once it has already returned.
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
        $this->saveCurrentUser = $this->currentUser->get();
        $userLanguage = $this->saveCurrentUser->language->value;
        $this->mailer
            ->switchLangTo($userLanguage);

        $this->isToSendMail = $isToSendMail;

        if ($isToSendMail) {
            $this->emailFormat = $this->mailer
                ->getStrEmailFormat(SqlDialect::getBoolean($this->currentConfig->nbmSendHtmlMail));

            // \Piwigo\Config\CurrentConfig::nbmSendMailAs() is admin-submitted free text (see
            // NotificationByMailSubController), always a string when set.
            $nbmSendMailAs = $this->currentConfig->nbmSendMailAs;
            $sendAsName = $nbmSendMailAs !== ''
                ? $nbmSendMailAs
                : $this->mailer
                    ->getMailSenderName();
            $this->sendAsName = $sendAsName;

            $sendAsMailAddress = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                ->getWebmasterMailAddress();
            $this->sendAsMailAddress = $sendAsMailAddress;

            $this->sendAsMailFormatted = $this->mailer
                ->formatEmail($sendAsName, $sendAsMailAddress);

            $this->errorOnMailCount = 0;
            $this->sentMailCount = 0;
            $this->msgInfo = $this->lang->t('Mail sent to %s [%s].');
            $this->msgError = $this->lang->t('Error when sending email to %s [%s].');
        }
    }

    public function endUsersEnv(): void
    {
        if ($this->saveCurrentUser instanceof User) {
            $this->currentUser->set($this->saveCurrentUser);
        }
        $this->mailer
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
        $user = $this->userService->buildUser($nbmUser->userId);
        $this->currentUser->set(User::fromUserArray($user));

        $currentUserLanguage = $this->currentUser->get()
            ->language->value;
        $this->mailer
            ->switchLangTo($currentUserLanguage);

        if ($isActionSend) {
            $emailFormat = $this->emailFormat ?? $this->mailer
                ->getStrEmailFormat(false);
            $mailTemplate = $this->mailer
                ->getMailTemplate($emailFormat);
            $this->mailTemplate = $mailTemplate;
        }
    }

    public function unsetUserOnEnv(): void
    {
        $this->mailer
            ->switchLangBack();
        $this->mailTemplate = null;
    }

    public function incMailSentSuccess(UserMailNotification $nbmUser): void
    {
        $this->sentMailCount++;

        $msgInfo = $this->msgInfo ?? 'Mail sent to %s [%s].';
        $this->pageState->addInfo(sprintf($msgInfo, $nbmUser->username, $nbmUser->mailAddress));
    }

    public function incMailSentFailed(UserMailNotification $nbmUser): void
    {
        $this->errorOnMailCount++;

        $msgError = $this->msgError ?? 'Error when sending email to %s [%s].';
        $this->pageState->addError(sprintf($msgError, $nbmUser->username, $nbmUser->mailAddress));
    }

    public function displayCounterInfo(): void
    {
        if ($this->errorOnMailCount !== 0) {
            $this->pageState->addError($this->translator->plural(
                '%d mail was not sent.',
                '%d mails were not sent.',
                $this->errorOnMailCount
            ));

            if ($this->sentMailCount !== 0) {
                $this->pageState->addInfo($this->translator->plural(
                    '%d mail was sent.',
                    '%d mails were sent.',
                    $this->sentMailCount
                ));
            }
        } else {
            if ($this->sentMailCount === 0) {
                $this->pageState->addInfo($this->lang->t('No mail to send.'));
            } else {
                $this->pageState->addInfo($this->translator->plural(
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

        $galleryHomeUrlStr = $this->urlService->getGalleryHomeUrl();

        $emailFormat = $this->emailFormat ?? $this->mailer
            ->getStrEmailFormat(false);
        $mailTemplate = $this->mailTemplate ?? $this->mailer
            ->getMailTemplate($emailFormat);

        $mailTemplate->assignContext(new NbmMailContentPageContext(
            username: $nbmUser->username,
            sendAsName: $this->sendAsName,
            unsubscribeLink: $this->urlService->addUrlParams($galleryHomeUrlStr . '/nbm.php', [
                'unsubscribe' => $nbmUser->checkKey,
            ]),
            subscribeLink: $this->urlService->addUrlParams($galleryHomeUrlStr . '/nbm.php', [
                'subscribe' => $nbmUser->checkKey,
            ]),
            contactEmail: $this->sendAsMailAddress,
        ));

        $this->urlService->unsetMakeFullUrl();
    }

    /**
     * Subscribe or unsubscribe notification by mail.
     *
     * @param array<int, mixed> $checkKeyList check_key list where action will be done
     * @return list<string> check_key list treated
     */
    public function doSubscribeUnsubscribeNotificationByMail(bool $isAdminRequest, bool $isSubscribe, array $checkKeyList): array
    {
        $this->urlService->setMakeFullUrl();

        // \Piwigo\Config\CurrentConfig::galleryTitle() is always a string (config_default.inc.php),
        // but that isn't visible through $conf's own array<string, mixed> type.
        $galleryTitle = $this->currentConfig->galleryTitle;

        $checkKeyTreated = [];
        $updatedDataCount = 0;
        $errorOnUpdatedDataCount = 0;

        if ($isSubscribe) {
            $msgInfo = $this->lang->t('User %s [%s] was added to the subscription list.');
            $msgError = $this->lang->t('User %s [%s] was not added to the subscription list.');
        } else {
            $msgInfo = $this->lang->t('User %s [%s] was removed from the subscription list.');
            $msgError = $this->lang->t('User %s [%s] was not removed from the subscription list.');
        }

        if (count($checkKeyList) !== 0) {
            $updates = [];
            $enabledValue = SqlDialect::booleanToInt($isSubscribe);
            $dataUsers = $this->getUserNotifications('subscribe', $checkKeyList, ! $isSubscribe);

            // Prepare message after change language
            $msgBreakTimeout = $this->lang->t('Time to send mail is limited. Others mails are skipped.');

            $this->beginUsersEnv(true);

            foreach ($dataUsers as $nbmUser) {
                if ($this->checkSendmailTimeout()) {
                    // Stop fill list on 'send', if the quota is override
                    $this->pageState->addError($msgBreakTimeout);
                    break;
                }

                $checkKeyTreated[] = $nbmUser->checkKey;

                $doUpdate = true;
                if ($nbmUser->mailAddress !== '') {
                    $this->setUserOnEnv($nbmUser, true);

                    $subject = '[' . $galleryTitle . '] ' . ($isSubscribe ? $this->lang->t('Subscribe to notification by mail') : $this->lang->t('Unsubscribe from notification by mail'));

                    $this->assignVarsNbmMailContent($nbmUser);

                    $sectionActionBy = ($isSubscribe ? 'subscribe_by_' : 'unsubscribe_by_');
                    $sectionActionBy .= ($isAdminRequest ? 'admin' : 'himself');

                    $emailFormat = $this->emailFormat ?? $this->mailer
                        ->getStrEmailFormat(false);
                    $mailTemplate = $this->mailTemplate ?? $this->mailer
                        ->getMailTemplate($emailFormat);

                    $mailTemplate->assignContext(new NbmSubscribeActionMailContext(
                        sectionActionBy: $sectionActionBy,
                        galleryTitle: $galleryTitle,
                        galleryUrl: $this->urlService->getGalleryHomeUrl(),
                    ));

                    $sendAsMailFormatted = $this->sendAsMailFormatted ?? '';

                    $ret = $this->mailer
                        ->mail(
                            [
                                'name' => $nbmUser->username,
                                'email' => $nbmUser->mailAddress,
                            ],
                            [
                                'from' => $sendAsMailFormatted,
                                'subject' => $subject,
                                'email_format' => $emailFormat,
                                'content' => $mailTemplate->parse('notification_by_mail.latte', true),
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
                    $this->pageState->addInfo(sprintf($msgInfo, $nbmUser->username, $nbmUser->mailAddress));
                } else {
                    ++$errorOnUpdatedDataCount;
                    $this->pageState->addError(sprintf($msgError, $nbmUser->username, $nbmUser->mailAddress));
                }
            }

            $this->endUsersEnv();

            $this->displayCounterInfo();

            $this->batchWriter->massUpdate(
                'user_mail_notification',
                [
                    'primary' => ['check_key'],
                    'update' => ['enabled'],
                ],
                $updates
            );
        }

        $this->pageState->addInfo($this->translator->plural(
            '%d user was updated.',
            '%d users were updated.',
            $updatedDataCount
        ));

        if ($errorOnUpdatedDataCount !== 0) {
            $this->pageState->addError($this->translator->plural(
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
     * @return ($action is 'send' ? list<string> : list<UserMailNotification>)
     */
    public function sendMailNotifications(string $action = 'list_to_send', array $checkKeyList = [], string $customizeMailContent = ''): array
    {
        $returnList = [];

        if (in_array($action, ['list_to_send', 'send'], true)) {
            // Env::now() rather than SQL's NOW() -- matches
            // SessionRepository/CommentRepository's own established
            // reasoning: invisible to PIWIGO_TEST_NOW, so a fixture-dated
            // 'last_send' comparison would silently use two different
            // clocks otherwise.
            $dbnow = Env::now()->format('Y-m-d H:i:s');

            $isActionSend = ($action === 'send');

            // disabled and null mail_address are not selected in the list
            $dataUsers = $this->getUserNotifications('send', $checkKeyList);

            // List all if it's define on options or on timeout
            $isListAllWithoutTest = ($this->isSendmailTimeout or $this->currentConfig->nbmListAllEnabledUsersToSend);

            // Check if exist news to list user or send mails
            if ((! $isListAllWithoutTest) or ($isActionSend)) {
                if (count($dataUsers) > 0) {
                    $datas = [];

                    if ($customizeMailContent === '') {
                        $customizeMailContent = $this->currentConfig->nbmComplementaryMailContent;
                    }

                    $customizeMailContent =
                      $this->eventDispatcher->dispatch(new NbmRenderGlobalCustomizeMailContent($customizeMailContent))
                          ->customizeMailContent;

                    // Prepare message after change language
                    if ($isActionSend) {
                        $msgBreakTimeout = $this->lang->t('Time to send mail is limited. Others mails are skipped.');
                    } else {
                        $msgBreakTimeout = $this->lang->t('Prepared time for list of users to send mail is limited. Others users are not listed.');
                    }

                    $this->beginUsersEnv($isActionSend);

                    foreach ($dataUsers as $nbmUser) {
                        if ((! $isActionSend) and $this->checkSendmailTimeout()) {
                            // Stop fill list on 'list_to_send', if the quota is override
                            $this->pageState->addInfo($msgBreakTimeout);
                            break;
                        }
                        if (($isActionSend) and $this->checkSendmailTimeout()) {
                            // Stop fill list on 'send', if the quota is override
                            $this->pageState->addError($msgBreakTimeout);
                            break;
                        }

                        $this->setUserOnEnv($nbmUser, $isActionSend);
                        if ($isActionSend) {
                            $auth = null;
                            $addUrlParams = [];

                            $authKey = $this->authService->createUserAuthKey($nbmUser->userId->value, $nbmUser->status);

                            if ($authKey !== false) {
                                $auth = $authKey['auth_key'];
                                $addUrlParams['auth'] = $auth;
                            }

                            $this->urlService->setMakeFullUrl();
                            // Fill return list of "treated" check_key for 'send'
                            $returnList[] = $nbmUser->checkKey;

                            // These nbm_* flags are plain booleans in
                            // include/config_default.inc.php; (bool) is always a
                            // safe, non-failing cast for a mixed config value.
                            $nbmSendDetailedContent = $this->currentConfig->nbmSendDetailedContent;
                            $nbmSendHtmlMail = $this->currentConfig->nbmSendHtmlMail;

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
                                $galleryTitle = $this->currentConfig->galleryTitle;
                                $subject = '[' . $galleryTitle . '] ' . $this->lang->t('New photos added');

                                $mailEmailFormat = $this->emailFormat ?? $this->mailer
                                    ->getStrEmailFormat($nbmSendHtmlMail);
                                $mailTemplate = $this->mailTemplate ?? $this->mailer
                                    ->getMailTemplate($mailEmailFormat);

                                // Assign current var for nbm mail
                                $this->assignVarsNbmMailContent($nbmUser);

                                $content_new_elements_between = null;
                                $content_new_elements_single = null;
                                if ($nbmUser->lastSend !== null) {
                                    $content_new_elements_between = [
                                        'DATE_BETWEEN_1' => $nbmUser->lastSend,
                                        'DATE_BETWEEN_2' => $dbnow,
                                    ];
                                } else {
                                    $content_new_elements_single = [
                                        'DATE_SINGLE' => $dbnow,
                                    ];
                                }

                                $global_new_lines = $nbmSendDetailedContent ? $news : null;

                                $nbmUserCustomizeMailContent =
                                  $this->eventDispatcher->dispatch(
                                      new NbmRenderUserCustomizeMailContent($customizeMailContent, $nbmUser)
                                  )->customizeMailContent;
                                $custom_mail_content = ! in_array($nbmUserCustomizeMailContent, ['0', ''], true) ? $nbmUserCustomizeMailContent : null;

                                $recent_posts = [];
                                $nbmSendRecentPostDates = $this->currentConfig->nbmSendRecentPostDates;
                                if ($nbmSendHtmlMail and $nbmSendRecentPostDates) {
                                    // CurrentConfig::recentPostDates() returns a
                                    // typed NotificationConfig object, not a raw
                                    // array -- same shape as FeedController's RSS
                                    // equivalent.
                                    $nbmConfig = $this->currentConfig->recentPostDates
                                        ->nbm;
                                    $nbmRecentPostDatesArgs = [
                                        'max_dates' => $nbmConfig->maxDates,
                                        'max_elements' => $nbmConfig->maxElements,
                                        'max_cats' => $nbmConfig->maxCats,
                                    ];

                                    $recentPostDates = $this->notificationService->getRecentPostDatesArray($nbmRecentPostDatesArgs);
                                    foreach ($recentPostDates as $dateDetail) {
                                        $recent_posts[] = [
                                            'TITLE' => $this->notificationService->getTitleRecentPostDate($dateDetail),
                                            'HTML_DATA' => $this->notificationService->getHtmlDescriptionRecentPostDate($dateDetail, $auth),
                                        ];
                                    }
                                }

                                $galleryHomeUrl = $this->urlService->getGalleryHomeUrl();
                                $mailTemplate->assignContext(new NbmNewsMailContext(
                                    contentNewElementsBetween: $content_new_elements_between,
                                    contentNewElementsSingle: $content_new_elements_single,
                                    globalNewLines: $global_new_lines,
                                    customMailContent: $custom_mail_content,
                                    galleryTitle: $galleryTitle,
                                    galleryUrl: $this->urlService->addUrlParams($galleryHomeUrl, $addUrlParams),
                                    sendAsName: $this->sendAsName,
                                    recentPosts: $recent_posts,
                                ));

                                $mailArgs = [
                                    'from' => $this->sendAsMailFormatted ?? '',
                                    'subject' => $subject,
                                    'email_format' => $mailEmailFormat,
                                    'content' => $mailTemplate->parse('notification_by_mail.latte', true),
                                    'content_format' => $mailEmailFormat,
                                ];
                                if (is_string($auth)) {
                                    $mailArgs['auth_key'] = $auth;
                                }

                                $ret = $this->mailer
                                    ->mail(
                                        [
                                            'name' => $nbmUser->username,
                                            'email' => $nbmUser->mailAddress,
                                        ],
                                        $mailArgs
                                    );

                                if ($ret) {
                                    $this->incMailSentSuccess($nbmUser);

                                    $datas[] = [
                                        'user_id' => $nbmUser->userId->value,
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
                            'user_mail_notification',
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
                        $this->pageState->addError($this->lang->t('No user to send notifications by mail.'));
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
     * @return list<string> check_key list treated
     */
    public function subscribeNotificationByMail(bool $isAdminRequest, array $checkKeyList = []): array
    {
        return $this->doSubscribeUnsubscribeNotificationByMail($isAdminRequest, true, $checkKeyList);
    }

    /**
     * @param array<int, mixed> $checkKeyList check_key list where action will be done
     * @return list<string> check_key list treated
     */
    public function unsubscribeNotificationByMail(bool $isAdminRequest, array $checkKeyList = []): array
    {
        return $this->doSubscribeUnsubscribeNotificationByMail($isAdminRequest, false, $checkKeyList);
    }
}
