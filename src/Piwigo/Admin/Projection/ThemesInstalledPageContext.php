<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ThemesInstalledPageRenderer::render()}.
 */
final readonly class ThemesInstalledPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $tplThemes
     */
    public function __construct(
        public string $activateBaseUrl,
        public string $deactivateBaseUrl,
        public string $setDefaultBaseUrl,
        public string $deleteBaseUrl,
        public array $tplThemes,
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
            'activate_baseurl' => $this->activateBaseUrl,
            'deactivate_baseurl' => $this->deactivateBaseUrl,
            'set_default_baseurl' => $this->setDefaultBaseUrl,
            'delete_baseurl' => $this->deleteBaseUrl,
            'tpl_themes' => $this->tplThemes,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'CONF_ENABLE_EXTENSIONS_INSTALL' => $this->enableExtensionsInstall,
        ];
    }
}
