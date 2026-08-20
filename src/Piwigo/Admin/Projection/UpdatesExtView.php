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
 * `updates_ext.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UpdatesExtPageRenderer::render()}. Shared by 4 real
 * callers -- `Controller\Admin\UpdatesSubController`'s own "ext" tab,
 * and `LanguagesSubController`/`ThemesSubController`/
 * `PluginsSubController`'s own "update" tab, each of which applies its
 * own `ADMIN_PAGE_TITLE` override via its own `AdminContentPageContext`
 * call after this render() call returns.
 */
#[Template('updates_ext.latte')]
final readonly class UpdatesExtView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, list<array<string, mixed>>> $updatesExtension
     */
    public function __construct(
        public array $updatesExtension,
        public bool $showReset,
        public string $pwgToken,
        public string $extType,
        public int $isWebmaster,
    ) {}

    /**
     * `updates_ext.latte`'s own unconditional `{do combineScript(...)}`x5/
     * `{do combineCss(...)}`x3 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.ajaxmanager', 'themes/default/js/plugins/jquery.ajaxmanager.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::script('jquery.jgrowl', 'themes/default/js/plugins/jquery.jgrowl_minimized.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery.jgrowl.css'),
            AssetContribution::css('themes/admin/default/css/pages/updates_ext.css', id: 'updates_ext'),
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
            AssetContribution::script('updates_ext', 'themes/admin/default/js/updates_ext.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.effect-blind', 'jquery.ajaxmanager', 'jquery.jgrowl', 'common', 'jquery.confirm', 'page-data']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'csrf_token' => $this->pwgToken,
            'ext_type' => $this->extType,
        ];
    }

    /**
     * `updates_ext.latte`'s own unconditional `{do exposeString(...)}`x6
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'ERROR',
            'Update Complete',
            'an error happened',
            'Reset ignored updates',
            'Are you sure you want to update all extensions?',
        ];
    }
}
