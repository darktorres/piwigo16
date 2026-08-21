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
 * `plugins_new.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PluginsNewPageRenderer::render()}. `$orderSelected`
 * feeds the template's own `n:attr="selected: ..."` comparison against
 * `$orderOptions`, which already tolerates a `null` `$orderSelected` --
 * an always-present `null` behaves identically to the original
 * conditionally-omitted key. `$plugins` is
 * always included (even empty) since the template reads it with
 * `{if !empty($plugins)}`, not `isset()`. `$colorscheme` is the
 * ambient `$themeconf['colorscheme']` the template's own
 * `combineCss(id: 'jquery.selectize', ...)` call reads -- the
 * controller resolves it the same way `Template` itself would, via
 * `$template->themeConf('colorscheme')`.
 */
#[Template('plugins_new.latte')]
final readonly class PluginsNewView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, string> $orderOptions
     * @param list<array<string, mixed>> $plugins
     */
    public function __construct(
        public array $orderOptions,
        public ?string $orderSelected,
        public ?string $betaUrl,
        public bool $betaTest,
        public array $plugins,
        public string $colorscheme,
    ) {}

    /**
     * `plugins_new.latte`'s own unconditional `{do combineScript(...)}`x5/
     * `{do combineCss(...)}`x4 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.sort', 'themes/default/js/plugins/jquery.sort.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.ui.slider', 'themes/default/js/ui/minified/jquery.ui.slider.min.js', dependsOn: ['jquery.ui']),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css'),
            AssetContribution::css('themes/admin/default/css/pages/plugins_new.css', id: 'plugins_new'),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::script('pluginsNew', 'themes/admin/default/js/plugins_new.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.effect-blind', 'jquery.sort', 'page-data']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * `plugins_new.latte`'s own unconditional `{do exposeString(...)}`x13
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to install the plugin "%s"?',
            'This plugin is incompatible with your version',
            'This plugin have no update since 3 years ! It may be outdated',
            'This plugin has no recent update',
            'This plugin was updated less than 6 months ago',
            'This plugin have been updated recently',
            '%d month',
            '%d months',
            '%d year',
            '%d years',
            'since the beginning',
        ];
    }
}
