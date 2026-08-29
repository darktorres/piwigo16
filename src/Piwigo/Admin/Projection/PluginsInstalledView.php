<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
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
 * `plugins_installed_config.ts`'s `pwg_getPageData()` reads.
 */
#[Template('plugins_installed.latte')]
final readonly class PluginsInstalledView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<PluginListRow> $plugins
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

    /**
     * `plugins_installed.latte`'s own unconditional `{do combineScript(...)}`x6/
     * `{do combineCss(...)}`x1 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.cookie', 'https://cdn.jsdelivr.net/npm/jquery.cookie@1.4.1/jquery.cookie.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::script('tiptip', 'https://cdn.jsdelivr.net/gh/drewwilson/TipTip@277e33629e/jquery.tipTip.minified.js'),
            // Real per-page bundle entry (docs/PLAN.md's P48) -- folds
            // plugins_installed_config.ts/plugins_installated.ts's code
            // in via real imports instead of the 2 separate script
            // tags this used to register.
            AssetContribution::script('plugins_installed_page', 'themes/admin/default/js/pages/plugins_installed.ts', loadMode: LoadMode::Footer),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'csrf_token' => $this->csrfToken,
            'count_types_plugins' => $this->countTypesPlugins,
            'is_webmaster' => $this->isWebmaster,
            'show_details' => $this->showDetails,
        ];
    }

    /**
     * `plugins_installed.latte`'s own unconditional `{do exposeString(...)}`x18
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'WARNING! This plugin does not seem to be compatible with this version of Piwigo.',
            'Do you want to activate anyway?',
            'Are you sure you want to delete the plugin "%s"?',
            'Plugin "%s" deleted!',
            'Are you sure you want to restore the plugin "%s"?',
            'Are you sure you want to uninstall the plugin "%s"?',
            'Activated',
            'Deactivated',
            'Restored',
            'an error happened',
            'Webmaster status required',
            'No plugins found',
            '%s plugins found',
            '%s plugin found',
            'While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset',
        ];
    }
}
