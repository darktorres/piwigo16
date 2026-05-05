<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\mail
 */
use Piwigo\Template\Template;

function get_mail_sender_name(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getMailSenderName();
}

function get_mail_sender_email(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getMailSenderEmail();
}

/** @return array<string,mixed> */
function get_mail_configuration(): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getMailConfiguration();
}

function format_email(string $name, string $email): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->formatEmail($name, $email);
}

/**
 * @param string|array<mixed> $input
 * @return array{email: string, name: string}
 */
function unformat_email(string|array $input): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->unformatEmail($input);
}

/** @return string[][] */
function get_clean_recipients_list(mixed $data): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getCleanRecipientsList($data);
}

#[\Deprecated(message: '2.6')]
function get_strict_email_list(string $email_list): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getStrictEmailList($email_list);
}

function &get_mail_template(string $email_format): Template
{
    $result = \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getMailTemplate($email_format);
    return $result;
}

function get_str_email_format(bool $is_html): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->getStrEmailFormat($is_html);
}

function switch_lang_to(string $language): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->switchLangTo($language);
}

function switch_lang_back(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->switchLangBack();
}

/**
 * @param array<mixed>|string $subject
 * @param array<mixed>|string $content
 */
function pwg_mail_notification_admins(array|string $subject, array|string $content, bool $send_technical_details = true, ?int $group_id = null): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgMailNotificationAdmins($subject, $content, $send_technical_details, $group_id);
}

/**
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail_admins(array $args = [], array $tpl = [], bool $exclude_current_user = true, bool $only_webmasters = false, ?int $group_id = null): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgMailAdmins($args, $tpl, $exclude_current_user, $only_webmasters, $group_id);
}

/**
 * @param array<mixed> $args
 * @param array<mixed> $tpl
 */
function pwg_mail_group(int $group_id, array $args = [], array $tpl = []): bool|int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgMailGroup($group_id, $args, $tpl);
}

function mail_function_is_usable(): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->mailFunctionIsUsable();
}

/**
 * @param string|array<mixed> $to
 * @param array<mixed>        $args
 * @param array<mixed>        $tpl
 */
function pwg_mail(string|array $to, array $args = [], array $tpl = []): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgMail($to, $args, $tpl);
}

#[\Deprecated(message: '2.6')]
function pwg_send_mail(mixed $result, string $to, string $subject, string $content, string $headers): bool|int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgSendMail($result, $to, $subject, $content, $headers);
}

function move_css_to_body(string $content): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->moveCssToBody($content);
}

/** @param array<mixed> $args */
function pwg_send_mail_test(bool $success, mixed $mail, array $args): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgSendMailTest($success, $mail, $args);
}

/** @return array<mixed> */
function pwg_generate_reset_password_mail(string $username, string $password_link, string $gallery_title, string $remaining_time): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgGenerateResetPasswordMail($username, $password_link, $gallery_title, $remaining_time);
}

/** @return array<mixed> */
function pwg_generate_set_password_mail(string $username, string $set_password_link, string $gallery_title, string $remaining_time): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgGenerateSetPasswordMail($username, $set_password_link, $gallery_title, $remaining_time);
}

/** @return array<mixed> */
function pwg_generate_code_verification_mail(string $code): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgGenerateCodeVerificationMail($code);
}

/** @return array<mixed> */
function pwg_generate_success_reset_password_mail(string $username, int $nb_of_apikeys): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Mail\MailService::class)->pwgGenerateSuccessResetPasswordMail($username, $nb_of_apikeys);
}

trigger_notify('functions_mail_included');
