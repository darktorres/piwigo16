<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `plugins_installed.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PluginsInstalledPageRenderer::render()}. `$plugins`
 * stays a loose row shape -- each entry's own key set varies per plugin
 * (e.g. `AUTHOR_URL` is only present when the filesystem plugin
 * declares one), not a fixed structural shape worth minting its own DTO
 * for here. No `$baseUrl`/`$maxInactiveBeforeHide`/`$pluginStates`
 * field -- confirmed dead against both the template's own body and
 * `plugins_installed_config.js`'s `pwg_getPageData()` reads.
 */
#[Template('plugins_installed.latte')]
final readonly class PluginsInstalledView implements View
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
        public string $csrfToken,
        public bool $showDetails,
        public int $isWebmaster,
        public string $viewSelector,
        public bool $enableExtensionsInstall,
    ) {}
}
