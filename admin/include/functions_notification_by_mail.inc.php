<?php

declare(strict_types=1);

use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Core\ServiceLocator;
use Piwigo\Notification\MailNotificationContext;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/* File-level initialisation — must stay here, runs at include time. */
MailNotificationContext::init();

function find_available_check_key(): string
{
    return ServiceLocator::get(NotificationAdminService::class)->findAvailableCheckKey();
}

function check_sendmail_timeout(): bool
{
    return ServiceLocator::get(NotificationAdminService::class)->checkSendmailTimeout();
}

/**
 * @param string[] $check_key_list
 * @return string[]
 */
function quote_check_key_list(array $check_key_list = []): array
{
    return ServiceLocator::get(NotificationAdminService::class)->quoteCheckKeyList($check_key_list);
}

/**
 * @param string[] $check_key_list
 * @return list<array<string, float|int|string|null>>
 */
function get_user_notifications(string $action, array $check_key_list = [], bool|string $enabled_filter_value = ''): array
{
    return ServiceLocator::get(NotificationAdminService::class)->getUserNotifications($action, $check_key_list, $enabled_filter_value);
}

function begin_users_env_nbm(bool $is_to_send_mail = false): void
{
    ServiceLocator::get(NotificationAdminService::class)->beginUsersEnvNbm($is_to_send_mail);
}

function end_users_env_nbm(): void
{
    ServiceLocator::get(NotificationAdminService::class)->endUsersEnvNbm();
}

/** @param array<string, float|int|string|null> $nbm_user */
function set_user_on_env_nbm(array &$nbm_user, bool $is_action_send): void
{
    ServiceLocator::get(NotificationAdminService::class)->setUserOnEnvNbm($nbm_user, $is_action_send);
}

function unset_user_on_env_nbm(): void
{
    ServiceLocator::get(NotificationAdminService::class)->unsetUserOnEnvNbm();
}

/** @param array<string, float|int|string|null> $nbm_user */
function inc_mail_sent_success(array $nbm_user): void
{
    ServiceLocator::get(NotificationAdminService::class)->incMailSentSuccess($nbm_user);
}

/** @param array<string, float|int|string|null> $nbm_user */
function inc_mail_sent_failed(array $nbm_user): void
{
    ServiceLocator::get(NotificationAdminService::class)->incMailSentFailed($nbm_user);
}

function display_counter_info(): void
{
    ServiceLocator::get(NotificationAdminService::class)->displayCounterInfo();
}

/** @param array<string, float|int|string|null> $nbm_user */
function assign_vars_nbm_mail_content(array $nbm_user): void
{
    ServiceLocator::get(NotificationAdminService::class)->assignVarsNbmMailContent($nbm_user);
}

/**
 * @param string[] $check_key_list
 * @return string[]
 */
function do_subscribe_unsubscribe_notification_by_mail(bool $is_admin_request, bool $is_subscribe = false, array $check_key_list = []): array
{
    return ServiceLocator::get(NotificationAdminService::class)->doSubscribeUnsubscribeNotificationByMail($is_admin_request, $is_subscribe, $check_key_list);
}

/**
 * @param string[] $check_key_list
 * @return string[]
 */
function unsubscribe_notification_by_mail(bool $is_admin_request, array $check_key_list = []): array
{
    return ServiceLocator::get(NotificationAdminService::class)->unsubscribeNotificationByMail($is_admin_request, $check_key_list);
}

/**
 * @param string[] $check_key_list
 * @return string[]
 */
function subscribe_notification_by_mail(bool $is_admin_request, array $check_key_list = []): array
{
    return ServiceLocator::get(NotificationAdminService::class)->subscribeNotificationByMail($is_admin_request, $check_key_list);
}
