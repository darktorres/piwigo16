<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/* nbm_global_var */
\Piwigo\Notification\MailNotificationContext::init();

/*
 * Search an available check_key
 *
 * It's a copy of function find_available_feed_id
 *
 * @return string nbm identifier
 */
function find_available_check_key(): string
{
    while (true) {
        $key = generate_key(16);
        if (!\Piwigo\Core\ServiceLocator::get(\Piwigo\Notification\NotificationRepository::class)
            ->checkKeyExists($key)) {
            return $key;
        }
    }
}

/*
 * Check sendmail timeout state
 *
 * @return true, if it's timeout
 */
function check_sendmail_timeout(): bool
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    $ctx->isSendmailTimeout = ((get_moment() - $ctx->startTime) > $ctx->sendmailTimeout);
    return $ctx->isSendmailTimeout;
}


/*
 * Add quote to all elements of check_key_list
 *
 * @return quoted check key list
 */
/**
 * @param string[] $check_key_list
 * @return string[]
 */
function quote_check_key_list(array $check_key_list = []): array
{
    return array_map(fn ($s): string => '\''.$s.'\'', $check_key_list);
}

/*
 * Execute all main queries to get list of user
 *
 * Type are the type of list 'subscribe', 'send'
 *
 * return array of users
 */
/**
 * @return list<array<string, float|int|string|null>>
 */
/**
 * @param string[] $check_key_list
 * @return list<array<string, float|int|string|null>>
 */
function get_user_notifications(string $action, array $check_key_list = [], bool|string $enabled_filter_value = ''): array
{
    // PHP loose comparison: false == '' is true, so the original only applied
    // the filter when $enabled_filter_value was strictly true.
    $enabledFilter = ($enabled_filter_value !== '' && $enabled_filter_value !== false)
        ? (bool) $enabled_filter_value
        : null;

    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Notification\NotificationRepository::class)
        ->getUserNotifications($action, $check_key_list, $enabledFilter);
}

/*
 * Begin of use nbm environment
 * Prepare and save current environment and initialize data in order to send mail
 *
 * Return none
 */
function begin_users_env_nbm(bool $is_to_send_mail = false): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    // Save $user, $lang_info and $lang arrays (include/user.inc.php has been executed)
    $ctx->saveUser = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
    // Save current language to stack, necessary because $user change during NBM
    switch_lang_to(\Piwigo\Users\CurrentUser::get()->language);

    $ctx->isToSendMail = $is_to_send_mail;

    if ($is_to_send_mail) {
        // Init mail configuration
        $ctx->emailFormat = get_str_email_format(\Piwigo\Config\Config::nbmSendHtmlMail());
        $ctx->sendAsName = ((\Piwigo\Config\Config::has('nbm_send_mail_as') and !empty(\Piwigo\Config\Config::nbmSendMailAs())) ? \Piwigo\Config\Config::nbmSendMailAs() : get_mail_sender_name());
        $ctx->sendAsMailAddress = get_webmaster_mail_address();
        $ctx->sendAsMailFormated = format_email($ctx->sendAsName, $ctx->sendAsMailAddress);
        // Init mail counter
        $ctx->errorOnMailCount = 0;
        $ctx->sentMailCount = 0;
        // Save sendmail message info and error in the original language
        $ctx->msgInfo = l10n('Mail sent to %s [%s].');
        $ctx->msgError = l10n('Error when sending email to %s [%s].');
    }
}

/*
 * End of use nbm environment
 * Restore environment
 *
 * Return none
 */
function end_users_env_nbm(): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    // Restore $user, $lang_info and $lang arrays (include/user.inc.php has been executed)
    \Piwigo\Users\CurrentUser::setRawAttributes($ctx->saveUser);
    // Restore current language to stack, necessary because $user change during NBM
    switch_lang_back();

    if ($ctx->isToSendMail) {
        $ctx->emailFormat = '';
        $ctx->sendAsName = '';
        $ctx->sendAsMailAddress = '';
        $ctx->sendAsMailFormated = '';
        // counters intentionally kept: errorOnMailCount, sentMailCount
        $ctx->msgInfo = '';
        $ctx->msgError = '';
    }

    $ctx->saveUser = [];
    $ctx->isToSendMail = false;
}

/*
 * Set user on nbm enviromnent
 *
 * Return none
 */
/** @param array<string, float|int|string|null> $nbm_user */
function set_user_on_env_nbm(array &$nbm_user, bool $is_action_send): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    $newUser = build_user(is_numeric($nbm_user['user_id']) ? (int)$nbm_user['user_id'] : 0, true);
    \Piwigo\Users\CurrentUser::setRawAttributes($newUser);

    switch_lang_to(is_string($newUser['language'] ?? null) ? $newUser['language'] : '');

    if ($is_action_send) {
        $ctx->mailTemplate = get_mail_template($ctx->emailFormat);
        $ctx->mailTemplate->set_filename('notification_by_mail', 'notification_by_mail.tpl');
    }
}

/*
 * Unset user on nbm enviromnent
 *
 * Return none
 */
function unset_user_on_env_nbm(): void
{
    switch_lang_back();
    \Piwigo\Notification\MailNotificationContext::current()->mailTemplate = null;
}

/*
 * Inc Counter success
 *
 * Return none
 */
/** @param array<string, float|int|string|null> $nbm_user */
function inc_mail_sent_success(array $nbm_user): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    $ctx->sentMailCount += 1;
    \Piwigo\Core\PageState::current()->addInfo(sprintf($ctx->msgInfo, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
}

/*
 * Inc Counter failed
 *
 * Return none
 */
/** @param array<string, float|int|string|null> $nbm_user */
function inc_mail_sent_failed(array $nbm_user): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    $ctx->errorOnMailCount += 1;
    \Piwigo\Core\PageState::current()->addError(sprintf($ctx->msgError, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
}

/*
 * Display Counter Info
 *
 * Return none
 */
function display_counter_info(): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    if ($ctx->errorOnMailCount != 0) {
        \Piwigo\Core\PageState::current()->addError(l10n_dec(
            '%d mail was not sent.',
            '%d mails were not sent.',
            $ctx->errorOnMailCount
        ));

        if ($ctx->sentMailCount != 0) {
            \Piwigo\Core\PageState::current()->addInfo(l10n_dec(
                '%d mail was sent.',
                '%d mails were sent.',
                $ctx->sentMailCount
            ));
        }
    } else {
        if ($ctx->sentMailCount == 0) {
            \Piwigo\Core\PageState::current()->addInfo(l10n('No mail to send.'));
        } else {
            \Piwigo\Core\PageState::current()->addInfo(l10n_dec(
                '%d mail was sent.',
                '%d mails were sent.',
                $ctx->sentMailCount
            ));
        }
    }
}

/** @param array<string, float|int|string|null> $nbm_user */
function assign_vars_nbm_mail_content(array $nbm_user): void
{
    $ctx = \Piwigo\Notification\MailNotificationContext::current();
    $tpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set in assign_vars_nbm_mail_content');

    set_make_full_url();

    $tpl->assign(
        [
        'USERNAME' => stripslashes((string) $nbm_user['username']),
        'SEND_AS_NAME' => $ctx->sendAsName,
        'UNSUBSCRIBE_LINK' => add_url_params(get_gallery_home_url().'/nbm.php', ['unsubscribe' => $nbm_user['check_key']]),
        'SUBSCRIBE_LINK' => add_url_params(get_gallery_home_url().'/nbm.php', ['subscribe' => $nbm_user['check_key']]),
        'CONTACT_EMAIL' => $ctx->sendAsMailAddress,
    ]
    );

    unset_make_full_url();
}

/*
 * Subscribe or unsubscribe notification by mail
 *
 * is_subscribe define if action=subscribe or unsubscribe
 * check_key list where action will be done
 *
 * @return string[] check_key list treated
 */
/**
 * @param string[] $check_key_list
 * @return string[]
 */
function do_subscribe_unsubscribe_notification_by_mail(bool $is_admin_request, bool $is_subscribe = false, array $check_key_list = []): array
{
    set_make_full_url();

    $check_key_treated = [];
    $updated_data_count = 0;
    $error_on_updated_data_count = 0;

    if ($is_subscribe) {
        $msg_info = l10n('User %s [%s] was added to the subscription list.');
        $msg_error = l10n('User %s [%s] was not added to the subscription list.');
    } else {
        $msg_info = l10n('User %s [%s] was removed from the subscription list.');
        $msg_error = l10n('User %s [%s] was not removed from the subscription list.');
    }

    if (count($check_key_list) != 0) {
        $updates = [];
        $enabled_value = \Piwigo\Core\BoolUtil::toString($is_subscribe);
        $data_users = get_user_notifications('subscribe', $check_key_list, !$is_subscribe);

        // Prepare message after change language
        $msg_break_timeout = l10n('Time to send mail is limited. Others mails are skipped.');

        // Begin nbm users environment
        begin_users_env_nbm(true);

        foreach ($data_users as $nbm_user) {
            if (check_sendmail_timeout()) {
                // Stop fill list on 'send', if the quota is override
                \Piwigo\Core\PageState::current()->addError($msg_break_timeout);
                break;
            }

            // Fill return list
            $check_key_treated[] = (string) $nbm_user['check_key'];

            $do_update = true;
            if ($nbm_user['mail_address'] != '') {
                // set env nbm user
                set_user_on_env_nbm($nbm_user, true);

                $subject = '['.\Piwigo\Config\Config::galleryTitle().'] '.($is_subscribe ? l10n('Subscribe to notification by mail') : l10n('Unsubscribe from notification by mail'));

                // Assign current var for nbm mail
                assign_vars_nbm_mail_content($nbm_user);

                $section_action_by = ($is_subscribe ? 'subscribe_by_' : 'unsubscribe_by_');
                $section_action_by .= ($is_admin_request ? 'admin' : 'himself');
                $ctx = \Piwigo\Notification\MailNotificationContext::current();
                $tpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set in do_subscribe_unsubscribe_notification_by_mail');
                $tpl->assign(
                    [
                    $section_action_by => true,
                    'GOTO_GALLERY_TITLE' => \Piwigo\Config\Config::galleryTitle(),
                    'GOTO_GALLERY_URL' => get_gallery_home_url(),
          ]
                );

                $ret = pwg_mail(
                    [
                    'name' => stripslashes((string) $nbm_user['username']),
                    'email' => $nbm_user['mail_address'],
                    ],
                    [
                    'from' => $ctx->sendAsMailFormated,
                    'subject' => $subject,
                    'email_format' => $ctx->emailFormat,
                    'content' => $tpl->parse('notification_by_mail', true),
                    'content_format' => $ctx->emailFormat,
                    ]
                );

                if ($ret) {
                    inc_mail_sent_success($nbm_user);
                } else {
                    inc_mail_sent_failed($nbm_user);
                    $do_update = false;
                }

                // unset env nbm user
                unset_user_on_env_nbm();

            }

            if ($do_update) {
                $updates[] = [
                  'check_key' => $nbm_user['check_key'],
                  'enabled' => $enabled_value,
                  ];
                $updated_data_count += 1;
                \Piwigo\Core\PageState::current()->addInfo(sprintf($msg_info, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
            } else {
                $error_on_updated_data_count += 1;
                \Piwigo\Core\PageState::current()->addError(sprintf($msg_error, stripslashes((string) $nbm_user['username']), $nbm_user['mail_address']));
            }

        }

        // Restore nbm environment
        end_users_env_nbm();

        display_counter_info();

        mass_updates(
            USER_MAIL_NOTIFICATION_TABLE,
            [
            'primary' => ['check_key'],
            'update' => ['enabled'],
      ],
            $updates
        );

    }

    \Piwigo\Core\PageState::current()->addInfo(l10n_dec(
        '%d user was updated.',
        '%d users were updated.',
        $updated_data_count
    ));

    if ($error_on_updated_data_count != 0) {
        \Piwigo\Core\PageState::current()->addError(l10n_dec(
            '%d user was not updated.',
            '%d users were not updated.',
            $error_on_updated_data_count
        ));
    }

    unset_make_full_url();

    return $check_key_treated;
}

/*
 * Unsubscribe notification by mail
 *
 * check_key list where action will be done
 *
 * @return check_key list treated
 */
/**
 * @param string[] $check_key_list
 * @return string[]
 */
function unsubscribe_notification_by_mail(bool $is_admin_request, array $check_key_list = []): array
{
    return do_subscribe_unsubscribe_notification_by_mail($is_admin_request, false, $check_key_list);
}

/*
 * Subscribe notification by mail
 *
 * check_key list where action will be done
 *
 * @return string[] check_key list treated
 */
/**
 * @param string[] $check_key_list
 * @return string[]
 */
function subscribe_notification_by_mail(bool $is_admin_request, array $check_key_list = []): array
{
    return do_subscribe_unsubscribe_notification_by_mail($is_admin_request, true, $check_key_list);
}
