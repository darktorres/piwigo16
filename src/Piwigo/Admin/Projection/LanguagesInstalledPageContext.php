<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\LanguagesInstalledPageRenderer::render()}.
 */
final readonly class LanguagesInstalledPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public function __construct(
        public array $languages,
        public bool $isWebmaster,
        public string $adminPageTitle,
        public bool $enableExtensionsInstall,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'languages' => $this->languages,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'CONF_ENABLE_EXTENSIONS_INSTALL' => $this->enableExtensionsInstall,
        ];
    }
}
