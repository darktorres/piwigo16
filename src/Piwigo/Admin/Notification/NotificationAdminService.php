<?php

declare(strict_types=1);

namespace Piwigo\Admin\Notification;

use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\Dml;
use Piwigo\Notification\MailNotificationContext;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

final class NotificationAdminService
{
    public function findAvailableCheckKey(): string
    {
        while (true) {
            $key = generate_key(16);
            if (!ServiceLocator::get(NotificationRepository::class)->checkKeyExists($key)) {
                return $key;
            }
        }
    }

    public function checkSendmailTimeout(): bool
    {
        $ctx                   = MailNotificationContext::current();
        $ctx->isSendmailTimeout = ((get_moment() - $ctx->startTime) > $ctx->sendmailTimeout);
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
        return ServiceLocator::get(NotificationRepository::class)->getUserNotifications($action, $checkKeyList, $enabledFilter);
    }

    public function beginUsersEnvNbm(bool $isToSendMail = false): void
    {
        $ctx          = MailNotificationContext::current();
        $ctx->saveUser = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        switch_lang_to(CurrentUser::get()->language);
        $ctx->isToSendMail = $isToSendMail;
        if ($isToSendMail) {
            $ctx->emailFormat          = get_str_email_format(Config::nbmSendHtmlMail());
            $ctx->sendAsName           = (Config::has('nbm_send_mail_as') && !empty(Config::nbmSendMailAs())) ? Config::nbmSendMailAs() : get_mail_sender_name();
            $ctx->sendAsMailAddress    = get_webmaster_mail_address();
            $ctx->sendAsMailFormated   = format_email($ctx->sendAsName, $ctx->sendAsMailAddress);
            $ctx->errorOnMailCount     = 0;
            $ctx->sentMailCount        = 0;
            $ctx->msgInfo  = l10n('Mail sent to %s [%s].');
            $ctx->msgError = l10n('Error when sending email to %s [%s].');
        }
    }

    public function endUsersEnvNbm(): void
    {
        $ctx = MailNotificationContext::current();
        CurrentUser::setRawAttributes($ctx->saveUser);
        switch_lang_back();
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
        $newUser = UserService::get()->buildUser(is_numeric($nbmUser['user_id']) ? (int) $nbmUser['user_id'] : 0, true);
        CurrentUser::setRawAttributes($newUser);
        switch_lang_to(is_string($newUser['language'] ?? null) ? $newUser['language'] : '');
        if ($isActionSend) {
            $ctx->mailTemplate = get_mail_template($ctx->emailFormat);
            $ctx->mailTemplate->setFilename('notification_by_mail', 'notification_by_mail.tpl');
        }
    }

    public function unsetUserOnEnvNbm(): void
    {
        switch_lang_back();
        MailNotificationContext::current()->mailTemplate = null;
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function incMailSentSuccess(array $nbmUser): void
    {
        $ctx = MailNotificationContext::current();
        $ctx->sentMailCount += 1;
        PageState::current()->addInfo(sprintf($ctx->msgInfo, stripslashes((string) $nbmUser['username']), $nbmUser['mail_address']));
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function incMailSentFailed(array $nbmUser): void
    {
        $ctx = MailNotificationContext::current();
        $ctx->errorOnMailCount += 1;
        PageState::current()->addError(sprintf($ctx->msgError, stripslashes((string) $nbmUser['username']), $nbmUser['mail_address']));
    }

    public function displayCounterInfo(): void
    {
        $ctx = MailNotificationContext::current();
        if ($ctx->errorOnMailCount != 0) {
            PageState::current()->addError(l10n_dec('%d mail was not sent.', '%d mails were not sent.', $ctx->errorOnMailCount));
            if ($ctx->sentMailCount != 0) {
                PageState::current()->addInfo(l10n_dec('%d mail was sent.', '%d mails were sent.', $ctx->sentMailCount));
            }
        } else {
            if ($ctx->sentMailCount == 0) {
                PageState::current()->addInfo(l10n('No mail to send.'));
            } else {
                PageState::current()->addInfo(l10n_dec('%d mail was sent.', '%d mails were sent.', $ctx->sentMailCount));
            }
        }
    }

    /** @param array<string, float|int|string|null> $nbmUser */
    public function assignVarsNbmMailContent(array $nbmUser): void
    {
        $ctx = MailNotificationContext::current();
        $tpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set in assignVarsNbmMailContent');
        set_make_full_url();
        $tpl->assign([
            'USERNAME'        => stripslashes((string) $nbmUser['username']),
            'SEND_AS_NAME'    => $ctx->sendAsName,
            'UNSUBSCRIBE_LINK' => add_url_params(ServiceLocator::get(UrlGenerator::class)->nbm(), ['unsubscribe' => $nbmUser['check_key']]),
            'SUBSCRIBE_LINK'   => add_url_params(ServiceLocator::get(UrlGenerator::class)->nbm(), ['subscribe' => $nbmUser['check_key']]),
            'CONTACT_EMAIL'    => $ctx->sendAsMailAddress,
        ]);
        unset_make_full_url();
    }

    /**
     * @param string[] $checkKeyList
     * @return string[]
     */
    public function doSubscribeUnsubscribeNotificationByMail(bool $isAdminRequest, bool $isSubscribe = false, array $checkKeyList = []): array
    {
        set_make_full_url();
        $checkKeyTreated         = [];
        $updatedDataCount        = 0;
        $errorOnUpdatedDataCount = 0;
        $msgInfo  = $isSubscribe ? l10n('User %s [%s] was added to the subscription list.') : l10n('User %s [%s] was removed from the subscription list.');
        $msgError = $isSubscribe ? l10n('User %s [%s] was not added to the subscription list.') : l10n('User %s [%s] was not removed from the subscription list.');

        if (count($checkKeyList) != 0) {
            $updates       = [];
            $enabledValue  = BoolUtil::toString($isSubscribe);
            $dataUsers     = $this->getUserNotifications('subscribe', $checkKeyList, !$isSubscribe);
            $msgBreakTimeout = l10n('Time to send mail is limited. Others mails are skipped.');
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
                    $subject = '[' . Config::galleryTitle() . '] ' . ($isSubscribe ? l10n('Subscribe to notification by mail') : l10n('Unsubscribe from notification by mail'));
                    $this->assignVarsNbmMailContent($nbmUser);
                    $sectionActionBy = ($isSubscribe ? 'subscribe_by_' : 'unsubscribe_by_') . ($isAdminRequest ? 'admin' : 'himself');
                    $ctx = MailNotificationContext::current();
                    $tpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set');
                    $tpl->assign([$sectionActionBy => true, 'GOTO_GALLERY_TITLE' => Config::galleryTitle(), 'GOTO_GALLERY_URL' => get_gallery_home_url()]);
                    $ret = pwg_mail(
                        ['name' => stripslashes((string) $nbmUser['username']), 'email' => $nbmUser['mail_address']],
                        ['from' => $ctx->sendAsMailFormated, 'subject' => $subject, 'email_format' => $ctx->emailFormat, 'content' => $tpl->parse('notification_by_mail', true), 'content_format' => $ctx->emailFormat]
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
                    PageState::current()->addInfo(sprintf($msgInfo, stripslashes((string) $nbmUser['username']), $nbmUser['mail_address']));
                } else {
                    $errorOnUpdatedDataCount++;
                    PageState::current()->addError(sprintf($msgError, stripslashes((string) $nbmUser['username']), $nbmUser['mail_address']));
                }
            }

            $this->endUsersEnvNbm();
            $this->displayCounterInfo();
            Dml::massUpdates(USER_MAIL_NOTIFICATION_TABLE, ['primary' => ['check_key'], 'update' => ['enabled']], $updates);
        }

        PageState::current()->addInfo(l10n_dec('%d user was updated.', '%d users were updated.', $updatedDataCount));
        if ($errorOnUpdatedDataCount != 0) {
            PageState::current()->addError(l10n_dec('%d user was not updated.', '%d users were not updated.', $errorOnUpdatedDataCount));
        }
        unset_make_full_url();
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
