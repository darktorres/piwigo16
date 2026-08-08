<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Bootstrap\RedirectService::redirectHtml()}.
 */
final readonly class RedirectHtmlPageContext implements TemplatePageContext
{
    public function __construct(
        public string $redirectMsg,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'REDIRECT_MSG' => $this->redirectMsg,
        ];
    }
}
