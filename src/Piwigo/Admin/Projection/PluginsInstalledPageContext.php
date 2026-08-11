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
 * for here. `$pluginStates` is genuinely dead: confirmed via a full-repo
 * grep that no template anywhere reads `plugin_states` -- kept here
 * anyway as a faithful 1:1 port of the original 2-4 unconditional/
 * conditional `Template::append()` calls, not removed as an
 * out-of-scope dead-code cleanup.
 */
final readonly class PluginsInstalledPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $plugins
     * @param array<string, int> $countTypesPlugins keyed by
     *   active/inactive/missing/merged, but the renderer builds this via a
     *   dynamic-key increment loop, so PHPStan can't verify the sealed
     *   shape survives that mutation -- kept loose here to match
     * @param list<string> $pluginStates
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
        public array $pluginStates,
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
            'CSRF_TOKEN' => $this->pwgToken,
            'base_url' => $this->baseUrl,
            'show_details' => $this->showDetails,
            'max_inactive_before_hide' => $this->maxInactiveBeforeHide,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'view_selector' => $this->viewSelector,
            'CONF_ENABLE_EXTENSIONS_INSTALL' => $this->enableExtensionsInstall,
            'plugin_states' => $this->pluginStates,
        ];
    }
}
