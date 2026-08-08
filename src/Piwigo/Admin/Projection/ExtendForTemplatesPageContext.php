<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ExtendForTemplatesPageRenderer::render()}.
 */
final readonly class ExtendForTemplatesPageContext implements TemplatePageContext
{
    public function __construct(
        public string $helpUrl,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_HELP' => $this->helpUrl,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
