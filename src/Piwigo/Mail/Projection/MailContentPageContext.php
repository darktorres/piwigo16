<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The `CONTENT` template variable `mail-wrapper.latte` prints between
 * its own header and footer markup -- assigned by
 * {@see \Piwigo\Mail\MailService::mail()}, already fully
 * rendered/converted by that point (either a runtime `View` render or
 * the plain-text/HTML `$mailContent` conversion), so it's carried as a
 * trusted `Html` value here rather than a plain string, matching
 * `MailService::buildRuntimeTemplateView()`'s own `new Html($mailContent)`
 * convention.
 */
final readonly class MailContentPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $content,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'CONTENT' => $this->content,
        ];
    }
}
