<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Mail\MailService;
use Piwigo\Template\Template;

/**
 * Returns the name of the mail sender
 */
function get_mail_sender_name(): string
{
    return new MailService()
        ->getMailSenderName();
}

/**
 * Returns the email of the mail sender
 *
 * @since 2.6
 */
function get_mail_sender_email(): string
{
    return new MailService()
        ->getMailSenderEmail();
}

/**
 * Returns an array of mail configuration parameters.
 * - send_bcc_mail_webmaster
 * - mail_allow_html
 * - use_smtp
 * - smtp_host
 * - smtp_user
 * - smtp_password
 * - smtp_secure
 * - email_webmaster
 * - name_webmaster
 *
 * @return array<string, mixed>
 */
function get_mail_configuration(): array
{
    return new MailService()
        ->getMailConfiguration();
}

/**
 * Returns an email address with an associated real name.
 * Can return either:
 *    - email@domain.com
 *    - name <email@domain.com>
 *
 * @param string $name
 * @param string $email
 */
function format_email($name, $email): string
{
    return new MailService()
        ->formatEmail($name, $email);
}

/**
 * Returns the email and the name from a formatted address.
 * @since 2.6
 *
 * @param string|array<int|string, mixed> $input - if is an array must contain email[, name]
 * @return array{email: string, name: string}
 */
function unformat_email($input): array
{
    return new MailService()
        ->unformatEmail($input);
}

/**
 * Return a clean array of hashmaps (email, name) removing duplicates.
 * It accepts various inputs:
 *    - comma separated list
 *    - array of emails
 *    - single hashmap (email[, name])
 *    - array of incomplete hashmaps
 * @since 2.6
 *
 * @param mixed $data
 * @return list<array{email: string, name: string}>
 */
function get_clean_recipients_list($data): array
{
    return new MailService()
        ->getCleanRecipientsList($data);
}

/**
 * Returns an email address list with minimal email string.
 *
 * @param string $email_list - comma separated
 */
#[\Deprecated(message: '2.6')]
function get_strict_email_list($email_list): string
{
    return new MailService()
        ->getStrictEmailList($email_list);
}

/**
 * Return a new mail template.
 *
 * @param string $email_format - text/html or text/plain
 */
function get_mail_template($email_format): Template
{
    return new MailService()
        ->getMailTemplate($email_format);
}

/**
 * Return string email format (text/html or text/plain).
 *
 * @param bool $is_html
 */
function get_str_email_format($is_html): string
{
    return new MailService()
        ->getStrEmailFormat($is_html);
}

/**
 * Switch language to specified language.
 * All entries are push on language stack
 *
 * @param string $language
 */
function switch_lang_to($language): void
{
    new MailService()
        ->switchLangTo($language);
}

/**
 * Switch back language pushed with switch_lang_to() function.
 * @see switch_lang_to()
 * Language files are not reloaded
 */
function switch_lang_back(): void
{
    new MailService()
        ->switchLangBack();
}

/**
 * Send a notification email to all administrators.
 * current user (if admin) is not notified
 *
 * @param string|array<int|string, mixed> $subject
 * @param string|array<int|string, mixed> $content
 * @param bool $send_technical_details - send user IP and browser
 * @param int|string|null $group_id
 */
function pwg_mail_notification_admins($subject, $content, $send_technical_details = true, $group_id = null): bool
{
    return new MailService()
        ->mailNotificationAdmins($subject, $content, $send_technical_details, $group_id);
}

/**
 * Send a email to all administrators.
 * current user (if admin) is excluded
 * @see pwg_mail()
 * @since 2.6
 *
 * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args - as in pwg_mail()
 * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl - as in pwg_mail()
 * @param int|string|null $group_id
 */
function pwg_mail_admins(array $args = [], array $tpl = [], bool $exclude_current_user = true, bool $only_webmasters = false, $group_id = null): bool
{
    return new MailService()
        ->mailAdmins($args, $tpl, $exclude_current_user, $only_webmasters, $group_id);
}

/**
 * Send an email to a group.
 * @see pwg_mail()
 *
 * @param int $group_id
 * @param array{language_selected?: string, from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args - as in pwg_mail()
 *       o language_selected: filters users of the group by language [default value empty]
 * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl - as in pwg_mail()
 */
function pwg_mail_group($group_id, array $args = [], array $tpl = []): bool
{
    return new MailService()
        ->mailGroup($group_id, $args, $tpl);
}

/**
 * Sends an email, using Piwigo specific informations.
 *
 * @param string|array<int|string, mixed> $to
 * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
 *       o from: sender [default value webmaster email]
 *       o reply_to_mail_address: reply-to can be different of the "from" (new 16.4.0) [default value empty]
 *       o reply_to_name: reply-to can be different of the "from" (new 16.4.0) [default value empty]
 *       o Cc: array of carbon copy receivers of the mail. [default value empty]
 *       o Bcc: array of blind carbon copy receivers of the mail. [default value empty]
 *       o subject [default value 'Piwigo']
 *       o content: content of mail [default value '']
 *       o content_format: format of mail content [default value 'text/plain']
 *       o email_format: global mail format [default value $conf_mail['default_email_format']]
 *       o theme: theme to use [default value $conf_mail['mail_theme']]
 *       o mail_title: main title of the mail [default value $conf['gallery_title']]
 *       o mail_subtitle: subtitle of the mail [default value subject]
 *       o auth_key: authentication key to add on footer link [default value null]
 * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl - use these options to define a custom content template file
 *       o filename
 *       o dirname (optional)
 *       o assign (optional)
 */
function pwg_mail($to, array $args = [], array $tpl = []): bool
{
    return new MailService()
        ->mail($to, $args, $tpl);
}

/**
 * @param mixed $result
 * @param string|array<int|string, mixed> $to
 * @param mixed $subject
 * @param mixed $content
 * @param mixed $headers unused
 */
#[\Deprecated(message: '2.6')]
function pwg_send_mail($result, $to, $subject, $content, $headers): mixed
{
    if (is_admin()) {
        trigger_error('pwg_send_mail function is deprecated', E_USER_NOTICE);
    }

    if (! (bool) $result) {
        return pwg_mail($to, [
            'content' => $content,
            'subject' => $subject,
        ]);
    } else {
        return $result;
    }
}

/**
 * Moves CSS rules contained in the <style> tag to inline CSS.
 * Used for compatibility with Gmail and such clients
 * @since 2.6
 */
function move_css_to_body(string $content): string
{
    return new MailService()
        ->moveCssToBody($content);
}

/**
 * Saves a copy of the mail if _data/tmp.
 *
 * @param bool $success
 * @param \Symfony\Component\Mime\Email $mail
 * @param string|null $error_message
 * @param array<string, mixed> $args
 */
function pwg_send_mail_test($success, $mail, array $args, $error_message = null): void
{
    new MailService()
        ->sendMailTest($success, $mail, $args, $error_message);
}

/**
 * Generate content mail for reset password
 *
 * Return the content mail to send
 * @since 15
 * @param string $username
 * @param string $password_link
 * @param string $gallery_title
 * @param string $remaining_time
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_reset_password_mail($username, $password_link, $gallery_title, $remaining_time): array
{
    return new MailService()
        ->generateResetPasswordMail($username, $password_link, $gallery_title, $remaining_time);
}

/**
 * Generate content mail for set password
 *
 * Return the content mail to send
 * @since 15
 * @param string $username
 * @param string $gallery_title
 * @param string $remaining_time
 * @param string $set_password_link
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_set_password_mail($username, $set_password_link, $gallery_title, $remaining_time): array
{
    return new MailService()
        ->generateSetPasswordMail($username, $set_password_link, $gallery_title, $remaining_time);
}

/**
 * Generate content mail for user code verification
 *
 * Return the content mail to send
 * @since 16
 * @param string $code
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_code_verification_mail($code): array
{
    return new MailService()
        ->generateCodeVerificationMail($code);
}

/**
 * Generate content mail for reset password success
 *
 * Return the content mail to send
 * @since 16
 * @param string $username
 * @param int $nb_of_apikeys
 * @return array{subject: string, content: string, content_format: string} mail content
 */
function pwg_generate_success_reset_password_mail($username, $nb_of_apikeys): array
{
    return new MailService()
        ->generateSuccessResetPasswordMail($username, $nb_of_apikeys);
}

trigger_notify('functions_mail_included');
