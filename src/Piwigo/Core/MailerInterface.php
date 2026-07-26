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
     * from/Cc/Bcc: array|string, matching unformatEmail()'s own accepted
     * param type (a single "email"/"name" array or a "Name <email>"/plain
     * email string) -- neither of this interface's 2 real callers actually
     * sets any of the 3, but mail()'s own body handles both forms.
     * subject/content: both real callers always pass a plain string
     * (Lang::t()/Lang::args() results).
     *
     * @param string|array<int|string, mixed> $to
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl
     */
    public function mail(string|array $to, array $args = [], array $tpl = []): bool;

    /**
     * Array form is one (subject) or a list of (content) Lang::buildArgs()
     * results -- every real caller builds $content via repeated
     * `$keyargsContent[] = Lang::buildArgs(...)`, matching buildArgs()'s own
     * declared return shape.
     *
     * @param string|array{key_args: array<int, mixed>} $subject
     * @param string|list<array{key_args: array<int, mixed>}> $content
     */
    public function mailNotificationAdmins(string|array $subject, string|array $content, bool $sendTechnicalDetails = true, int|string|null $groupId = null): bool;
}
