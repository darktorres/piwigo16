<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The header title template variable set assigned by
 * {@see \Piwigo\Mail\MailService::mail()} on every content-type
 * iteration, cache hit or miss.
 */
final readonly class MailTitlePageContext implements TemplatePageContext
{
    public function __construct(
        public string $mailTitle,
        public string $mailSubtitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'MAIL_TITLE' => $this->mailTitle,
            'MAIL_SUBTITLE' => $this->mailSubtitle,
        ];
    }
}
