<?php

declare(strict_types=1);

namespace Piwigo\Html\Projection;

use Latte\Runtime\Html;
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
 * `$messages` are genuinely dead output (per a full-repo grep
 * of every theme template -- neither key is ever read), kept as a
 * faithful 1:1 port of the original 4 independently-gated
 * `Template::assign()` calls, not dropped as an out-of-scope cleanup.
 */
final readonly class PageMessagesContext implements TemplatePageContext
{
    /**
     * All 4 share {@see \Piwigo\Html\HtmlService::flushMessageList()}'s
     * own return type -- each element is already htmlspecialchars()'d (a
     * plain translated string) or genuinely safe pre-formed HTML (the
     * handful of callers that hand-build a real `<a>`/`<span>` fragment
     * into the message itself), so every real consumer
     * (`infos_errors.latte`'s own `{foreach $errors as $error}{$error}`)
     * prints it bare. `$errors` alone can also carry
     * `flushKeyedErrors()`'s own string-keyed error bag (e.g.
     * 'login_page_error' -- see that method's own docblock), hence
     * `array<array-key, Html>` rather than `list<Html>` for that one
     * field; `$infos`/`$warnings`/`$messages` only ever come from
     * `flushPageMessages()`'s plain lists.
     *
     * @param array<array-key, Html>|null $errors
     * @param list<Html>|null $infos
     * @param list<Html>|null $warnings
     * @param list<Html>|null $messages
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
