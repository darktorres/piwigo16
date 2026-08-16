<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

use Symfony\Component\Mime\Email;

/**
 * Typed event for the legacy `before_send_mail` filter. No handler is
 * registered for it anywhere today. `$email` is a real
 * `Symfony\Component\Mime\Email` instance -- the reference (which uses
 * PHPMailer instead) types the equivalent property `\PHPMailer\PHPMailer\
 * PHPMailer`, confirming this is a real library divergence, not an
 * oversight. `Symfony\Component\Mime\Email` isn't covered by any
 * deptrac.yaml layer collector (a plain vendor dependency), so no
 * namespace override is needed here. Mutable on `$shouldSend`; `$to`/
 * `$args`/`$email` stay context (a handler wanting to change the mail
 * itself mutates the already-mutable `$email` object in place, not this
 * property).
 */
final class BeforeSendMail
{
    /**
     * @param string|array<int|string, mixed> $to
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     */
    public function __construct(
        public bool $shouldSend,
        public readonly string|array $to,
        public readonly array $args,
        public readonly Email $email,
    ) {}
}
