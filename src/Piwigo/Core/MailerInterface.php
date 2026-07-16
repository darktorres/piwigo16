<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8c: `Piwigo\Mail\MailService` (L3Presentation, forced there by
 * its real `Template` dependency for themed HTML email) can't be
 * constructor-injected into L2aCoreDomain/L2bExtendedDomain classes
 * (`deptrac.yaml`'s ruleset forbids depending upward on L3). Lives in
 * `Piwigo\Core` (L1Infrastructure, same direction as `ControllerInterface`'s
 * L3-depends-on-its-own-lower-layer-contract shape) so L2a/L2b classes can
 * depend downward on this instead of the concrete class. `MailService`
 * implements it; `config/container.php` binds the two.
 *
 * Only the 2 methods real L2a/L2b callers actually use
 * (`Users\UserService`/`Comment\CommentService`) — grows if a 3rd
 * consumer needs another `MailService` method later, not pre-populated
 * ahead of need.
 */
interface MailerInterface
{
    /**
     * @param string|array<int|string, mixed> $to
     * @param array{from?: mixed, reply_to_mail_address?: string, reply_to_name?: string, Cc?: mixed, Bcc?: mixed, subject?: mixed, content?: mixed, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl
     */
    public function mail(string|array $to, array $args = [], array $tpl = []): bool;

    /**
     * @param string|array<int|string, mixed> $subject
     * @param string|array<int|string, mixed> $content
     */
    public function mailNotificationAdmins(string|array $subject, string|array $content, bool $sendTechnicalDetails = true, int|string|null $groupId = null): bool;
}
