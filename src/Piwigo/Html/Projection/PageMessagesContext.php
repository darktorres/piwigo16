<?php

declare(strict_types=1);

namespace Piwigo\Html\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The `errors`/`infos`/`warnings`/`messages` template variables assigned
 * by {@see \Piwigo\Html\HtmlService::flushPageMessages()} and
 * {@see \Piwigo\Html\HtmlService::flushKeyedErrors()}. Both methods
 * construct this independently (`flushKeyedErrors()` only ever sets
 * `$errors`, leaving the other 3 null) -- real callers may invoke both
 * in the same request (`IdentificationController`/`RegisterController`/
 * `PasswordController`, `flushPageMessages()` always first), relying on
 * `assignContext()`'s own partial-hashmap merge to compose them: a
 * later call's non-null fields overwrite (matching the original code's
 * own "later `Template::assign()` call replaces" behavior), and its
 * null fields leave the earlier call's own values untouched. `$warnings`/
 * `$messages` are genuinely dead output (confirmed via a full-repo grep
 * of every theme template -- neither key is ever read), kept as a
 * faithful 1:1 port of the original 4 independently-gated
 * `Template::assign()` calls, not dropped as an out-of-scope cleanup.
 */
final readonly class PageMessagesContext implements TemplatePageContext
{
    /**
     * All 4 share {@see \Piwigo\Html\HtmlService::flushMessageMode()}'s
     * own return type: array<array-key, string> (verified live against
     * every real PageState field declaration and every real
     * flushKeyedErrors() call site -- values are always translated
     * strings, only the key shape differs between the plain-list and
     * keyed-bag callers).
     *
     * @param array<array-key, string>|null $errors
     * @param array<array-key, string>|null $infos
     * @param array<array-key, string>|null $warnings
     * @param array<array-key, string>|null $messages
     */
    public function __construct(
        public ?array $errors,
        public ?array $infos,
        public ?array $warnings,
        public ?array $messages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [];

        if ($this->errors !== null) {
            $result['errors'] = $this->errors;
        }

        if ($this->infos !== null) {
            $result['infos'] = $this->infos;
        }

        if ($this->warnings !== null) {
            $result['warnings'] = $this->warnings;
        }

        if ($this->messages !== null) {
            $result['messages'] = $this->messages;
        }

        return $result;
    }
}
