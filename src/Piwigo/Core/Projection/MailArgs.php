<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\MailerInterface::mail()}'s own `$args` options bag --
 * every field here was already a precisely-shaped, fully-enumerable
 * docblock entry on that interface, just not yet a real object.
 *
 * Deliberately mutable (not `final readonly`, same rationale as
 * {@see \Piwigo\Category\Projection\ComputedCategoryRow}): `MailService::
 * mail()`'s own body resolves `$subject`/`$content`/`$contentFormat`/
 * `$theme`/`$mailTitle`/`$mailSubtitle` defaults in place across its
 * body, the same way the original array did; `AlbumNotificationPageRenderer`
 * clones a base instance per recipient and overrides `$authKey` on each
 * clone (`clone $args`, matching the original array's own copy-on-assign
 * semantics -- object assignment alone would NOT clone).
 *
 * `from`/`Cc`/`Bcc` keep {@see \Piwigo\Mail\MailService::unformatEmail()}'s
 * own accepted `array{email: string, name?: string}|string` union --
 * a real, bounded 2-shape input, not a fully arbitrary bag.
 */
final class MailArgs
{
    /**
     * @param array{email: string, name?: string}|string|null $from
     * @param array{email: string, name?: string}|string|null $cc
     * @param array{email: string, name?: string}|string|null $bcc
     */
    public function __construct(
        public readonly array|string|null $from = null,
        public readonly ?string $replyToMailAddress = null,
        public readonly ?string $replyToName = null,
        public readonly array|string|null $cc = null,
        public readonly array|string|null $bcc = null,
        public ?string $subject = null,
        public ?string $content = null,
        public ?string $contentFormat = null,
        public readonly ?string $emailFormat = null,
        public ?string $theme = null,
        public ?string $mailTitle = null,
        public ?string $mailSubtitle = null,
        public ?string $authKey = null,
    ) {}

    /**
     * Boundary conversion for {@see \Piwigo\Job\SendNotificationEmailJob::
     * $args}, which stays array-shaped on the Job itself (a real Symfony
     * Messenger serialization boundary -- an in-flight queued message must
     * still deserialize against the old array shape).
     *
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     */
    public static function fromArray(array $args): self
    {
        return new self(
            from: $args['from'] ?? null,
            replyToMailAddress: $args['reply_to_mail_address'] ?? null,
            replyToName: $args['reply_to_name'] ?? null,
            cc: $args['Cc'] ?? null,
            bcc: $args['Bcc'] ?? null,
            subject: $args['subject'] ?? null,
            content: $args['content'] ?? null,
            contentFormat: $args['content_format'] ?? null,
            emailFormat: $args['email_format'] ?? null,
            theme: $args['theme'] ?? null,
            mailTitle: $args['mail_title'] ?? null,
            mailSubtitle: $args['mail_subtitle'] ?? null,
            authKey: $args['auth_key'] ?? null,
        );
    }
}
