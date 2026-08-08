<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ThemesNewPageRenderer::render()}.
 */
final readonly class ThemesNewPageContext implements TemplatePageContext
{
    public function __construct(
        public string $defaultScreenshot,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'default_screenshot' => $this->defaultScreenshot,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
