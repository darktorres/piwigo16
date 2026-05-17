<?php

declare(strict_types=1);

namespace Piwigo\Admin\Notification;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Notification\MailNotificationContext;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

final readonly class NotificationAdminService
{
    public function __construct(
        private Connection $conn,
        private MailService $mailService,
        private NotificationRepository $notificationRepository,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserService $userService,
    ) {
    }

    public function findAvailableCheckKey(): string
    {
        while (true) {
            $key = StringUtil::generateKey(16);
            if (!$this->notificationRepository->checkKeyExists($key)) {
                return $key;
            }
        }
    }

    public function checkSendmailTimeout(): bool
    {
        $ctx                   = MailNotificationContext::current();
        $ctx->isSendmailTimeout = ((StringUtil::getMoment() - $ctx->startTime) > $ctx->sendmailTimeout);
        return $ctx->isSendmailTimeout;
    }

    /**
     * @param string[] $checkKeyList
     * @return string[]
     */
    public function quoteCheckKeyList(array $checkKeyList = []): array
    {
        return array_map(fn (string $s): string => '\'' . $s . '\'', $checkKeyList);
    }

    /**
     * @param string[] $checkKeyList
     * @return list<array<string, float|int|string|null>>
     */
    public function getUserNotifications(string $action, array $checkKeyList = [], bool|string $enabledFilterValue = ''): array
    {
        $enabledFilter = ($enabledFilterValue !== '' && $enabledFilterValue !== false)
            ? (bool) $enabledFilterValue
            : null;
        return $this->notificationRepository->getUserNotifications($action, $checkKeyList, $enabledFilter);
    }

    public function beginUsersEnvNbm(bool $isToSendMail = false): void
    {
        $ctx          = MailNotificationContext::current();
        $ctx->saveUser = CurrentUser::get()->rawAttributes;
        $this->mailService->switchLangTo(CurrentUser::get()->language);
        $ctx->isToSendMail = $isToSendMail;
        if ($isToSendMail) {
            $ctx->emailFormat          = $this->mailService->getStrEmailFormat(Config::nbmSendHtmlMail());
            $ctx->sendAsName           = (Config::has('nbm_send_mail_as') && !empty(Config::nbmSendMailAs())) ? Config::nbmSendMailAs() : $this->mailService->getMailSenderName();
            $ctx->sendAsMailAddress    = $this->mailService->getWebmasterMailAddress();
            $ctx->sendAsMailFormated   = $this->mailService->formatEmail($ctx->sendAsName, $ctx->sendAsMailAddress);
            $ctx->errorOnMailCount     = 0;
            $ctx->sentMailCount        = 0;
            $ctx->msgInfo  = Lang::t('Mail sent to %s [%s].');
            $ctx->msgError = Lang::t('Error when sending email to %s [%s].');
        }
    }

    public function endUsersEnvNbm(): void
    {
        $ctx = MailNotificationContext::current();
        CurrentUser::setRawAttributes($ctx->saveUser);
        $this->mailService->switchLangBack();
        if ($ctx->isToSendMail) {
            $ctx->emailFormat          = '';
            $ctx->sendAsName           = '';
            $ctx->sendAsMailAddress    = '';
            $ctx->sendAsMailFormated   = '';
            $ctx->msgInfo  = '';
            $ctx->msgError = '';
        }
        $ctx->saveUser     = [];
        $ctx->isToSendMail = false;
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function setUserOnEnvNbm(array &$nbmUser, bool $isActionSend): void
    {
        $ctx     = MailNotificationContext::current();
        $newUser = $this->userService->buildUser(is_numeric($nbmUser['user_id']) ? (int) $nbmUser['user_id'] : 0, true);
        CurrentUser::setRawAttributes($newUser);
        $this->mailService->switchLangTo(is_string($newUser['language'] ?? null) ? $newUser['language'] : '');
        if ($isActionSend) {
            $ctx->mailTemplate = $this->mailService->getMailTemplate($ctx->emailFormat);
        }
    }

    public function unsetUserOnEnvNbm(): void
    {
        $this->mailService->switchLangBack();
        MailNotificationContext::current()->mailTemplate = null;
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function incMailSentSuccess(array $nbmUser): void
    {
        $ctx = MailNotificationContext::current();
        $ctx->sentMailCount += 1;
        PageState::current()->addInfo(sprintf($ctx->msgInfo, stripslashes((string) $nbmUser['username']), (string) $nbmUser['mail_address']));
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function incMailSentFailed(array $nbmUser): void
    {
        $ctx = MailNotificationContext::current();
        $ctx->errorOnMailCount += 1;
        PageState::current()->addError(sprintf($ctx->msgError, stripslashes((string) $nbmUser['username']), (string) $nbmUser['mail_address']));
    }

    public function displayCounterInfo(): void
    {
        $ctx = MailNotificationContext::current();
        if ($ctx->errorOnMailCount != 0) {
            PageState::current()->addError(Translator::get()->plural('%d mail was not sent.', '%d mails were not sent.', $ctx->errorOnMailCount));
            if ($ctx->sentMailCount != 0) {
                PageState::current()->addInfo(Translator::get()->plural('%d mail was sent.', '%d mails were sent.', $ctx->sentMailCount));
            }
        } else {
            if ($ctx->sentMailCount == 0) {
                PageState::current()->addInfo(Lang::t('No mail to send.'));
            } else {
                PageState::current()->addInfo(Translator::get()->plural('%d mail was sent.', '%d mails were sent.', $ctx->sentMailCount));
            }
        }
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function assignVarsNbmMailContent(array $nbmUser): void
    {
        $ctx = MailNotificationContext::current();
        $tpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set in assignVarsNbmMailContent');
        $this->urlService->setMakeFullUrl();
        $tpl->assign([
            'USERNAME'        => stripslashes((string) $nbmUser['username']),
            'SEND_AS_NAME'    => $ctx->sendAsName,
            'UNSUBSCRIBE_LINK' => $this->urlService->addUrlParams($this->urlGenerator->nbm(), ['unsubscribe' => $nbmUser['check_key']]),
            'SUBSCRIBE_LINK'   => $this->urlService->addUrlParams($this->urlGenerator->nbm(), ['subscribe' => $nbmUser['check_key']]),
            'CONTACT_EMAIL'    => $ctx->sendAsMailAddress,
        ]);
        $this->urlService->unsetMakeFullUrl();
    }

    /**
     * @param string[] $checkKeyList
     * @return string[]
     */
    public function doSubscribeUnsubscribeNotificationByMail(bool $isAdminRequest, bool $isSubscribe = false, array $checkKeyList = []): array
    {
        $this->urlService->setMakeFullUrl();
        $checkKeyTreated         = [];
        $updatedDataCount        = 0;
        $errorOnUpdatedDataCount = 0;
        $msgInfo  = $isSubscribe ? Lang::t('User %s [%s] was added to the subscription list.') : Lang::t('User %s [%s] was removed from the subscription list.');
        $msgError = $isSubscribe ? Lang::t('User %s [%s] was not added to the subscription list.') : Lang::t('User %s [%s] was not removed from the subscription list.');

        if (count($checkKeyList) != 0) {
            $updates       = [];
            $enabledValue  = BoolUtil::toInt($isSubscribe);
            $dataUsers     = $this->getUserNotifications('subscribe', $checkKeyList, !$isSubscribe);
            $msgBreakTimeout = Lang::t('Time to send mail is limited. Others mails are skipped.');
            $this->beginUsersEnvNbm(true);

            foreach ($dataUsers as $nbmUser) {
                if ($this->checkSendmailTimeout()) {
                    PageState::current()->addError($msgBreakTimeout);
                    break;
                }
                $checkKeyTreated[] = (string) $nbmUser['check_key'];
                $doUpdate = true;

                if ($nbmUser['mail_address'] != '') {
                    $this->setUserOnEnvNbm($nbmUser, true);
                    $subject = '[' . Config::galleryTitle() . '] ' . ($isSubscribe ? Lang::t('Subscribe to notification by mail') : Lang::t('Unsubscribe from notification by mail'));
                    $this->assignVarsNbmMailContent($nbmUser);
                    $sectionActionBy = ($isSubscribe ? 'subscribe_by_' : 'unsubscribe_by_') . ($isAdminRequest ? 'admin' : 'himself');
                    $ctx = MailNotificationContext::current();
                    $tpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set');
                    $tpl->assign([$sectionActionBy => true, 'GOTO_GALLERY_TITLE' => Config::galleryTitle(), 'GOTO_GALLERY_URL' => $this->urlService->getGalleryHomeUrl()]);
                    $ret = $this->mailService->pwgMail(
                        ['name' => stripslashes((string) $nbmUser['username']), 'email' => $nbmUser['mail_address']],
                        ['from' => $ctx->sendAsMailFormated, 'subject' => $subject, 'email_format' => $ctx->emailFormat, 'content' => $tpl->parse('notification_by_mail.latte', true), 'content_format' => $ctx->emailFormat]
                    );
                    if ($ret) {
                        $this->incMailSentSuccess($nbmUser);
                    } else {
                        $this->incMailSentFailed($nbmUser);
                        $doUpdate = false;
                    }
                    $this->unsetUserOnEnvNbm();
                }

                if ($doUpdate) {
                    $updates[] = ['check_key' => $nbmUser['check_key'], 'enabled' => $enabledValue];
                    $updatedDataCount++;
                    PageState::current()->addInfo(sprintf($msgInfo, stripslashes((string) $nbmUser['username']), (string) $nbmUser['mail_address']));
                } else {
                    $errorOnUpdatedDataCount++;
                    PageState::current()->addError(sprintf($msgError, stripslashes((string) $nbmUser['username']), (string) $nbmUser['mail_address']));
                }
            }

            $this->endUsersEnvNbm();
            $this->displayCounterInfo();
            $this->conn->transactional(function () use ($updates): void {
                foreach ($updates as $row) {
                    $this->conn->update(Tables::userMailNotification(), ['enabled' => $row['enabled']], ['check_key' => $row['check_key']]);
                }
            });
        }

        PageState::current()->addInfo(Translator::get()->plural('%d user was updated.', '%d users were updated.', $updatedDataCount));
        if ($errorOnUpdatedDataCount != 0) {
            PageState::current()->addError(Translator::get()->plural('%d user was not updated.', '%d users were not updated.', $errorOnUpdatedDataCount));
        }
        $this->urlService->unsetMakeFullUrl();
        return $checkKeyTreated;
    }

    /**
     * @param string[] $checkKeyList
     * @return string[]
     */
    public function unsubscribeNotificationByMail(bool $isAdminRequest, array $checkKeyList = []): array
    {
        return $this->doSubscribeUnsubscribeNotificationByMail($isAdminRequest, false, $checkKeyList);
    }

    /**
     * @param string[] $checkKeyList
     * @return string[]
     */
    public function subscribeNotificationByMail(bool $isAdminRequest, array $checkKeyList = []): array
    {
        return $this->doSubscribeUnsubscribeNotificationByMail($isAdminRequest, true, $checkKeyList);
    }
}
