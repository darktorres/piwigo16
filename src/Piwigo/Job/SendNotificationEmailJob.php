<?php

declare(strict_types=1);

namespace Piwigo\Job;

/**
 * Mirrors Piwigo\Mail\MailService::mail()'s own parameter shape --
 * SendNotificationEmailHandler is a thin delegate to that real,
 * already-built service.
 */
final readonly class SendNotificationEmailJob
{
    /**
     * @param string|array<int|string, mixed> $to
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     * @param array{filename?: string, assign?: array<string, mixed>} $tpl
     */
    public function __construct(
        public string|array $to,
        public array $args = [],
        public array $tpl = [],
    ) {}
}
