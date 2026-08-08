<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PluginsInstalledPageRenderer::render()}. `$plugins`
 * stays a loose row shape -- each entry's own key set varies per plugin
 * (e.g. `AUTHOR_URL` is only present when the filesystem plugin
 * declares one), not a fixed structural shape worth minting its own DTO
 * for here.
 */
final readonly class PluginsInstalledPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $plugins
     * @param array<string, int> $countTypesPlugins keyed by
     *   active/inactive/missing/merged, but the renderer builds this via a
     *   dynamic-key increment loop, so PHPStan can't verify the sealed
     *   shape survives that mutation -- kept loose here to match
     */
    public function __construct(
        public array $plugins,
        public array $countTypesPlugins,
        public string $pwgToken,
        public string $baseUrl,
        public bool $showDetails,
        public int $maxInactiveBeforeHide,
        public bool $isWebmaster,
        public string $adminPageTitle,
        public string $viewSelector,
        public bool $enableExtensionsInstall,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'plugins' => $this->plugins,
            'count_types_plugins' => $this->countTypesPlugins,
            'PWG_TOKEN' => $this->pwgToken,
            'base_url' => $this->baseUrl,
            'show_details' => $this->showDetails,
            'max_inactive_before_hide' => $this->maxInactiveBeforeHide,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'view_selector' => $this->viewSelector,
            'CONF_ENABLE_EXTENSIONS_INSTALL' => $this->enableExtensionsInstall,
        ];
    }
}
