<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The runtime custom-content template variable set assigned by
 * {@see \Piwigo\Mail\MailService::mail()}. `$extra` stays a loose bag --
 * it's the public `mail()`/`mailAdmins()`/`mailGroup()` API's own
 * `$tpl['assign']` passthrough, and different real callers
 * (`MailService::mailNotificationAdmins()`'s `TECHNICAL` block,
 * `Admin\AlbumNotificationPageRenderer`'s `IMG`/`CAT_NAME`/`LINK`/
 * `CPL_CONTENT` block) supply entirely different key sets for entirely
 * different runtime templates. `CONTENT` stays a fixed, named field --
 * every real caller assigns exactly that one key, always last, so it
 * can override a same-named key `$extra` might also carry, matching the
 * original two sequential `assign()` calls' own overwrite order.
 */
final readonly class MailRuntimeTemplatePageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public array $extra,
        public string $content,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            ...$this->extra,
            'CONTENT' => $this->content,
        ];
    }
}
