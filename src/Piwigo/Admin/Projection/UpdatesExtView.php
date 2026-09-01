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
     * @param array<string, list<ExtensionUpdateRow>> $updatesExtension keyed by
     *   plural extension type ('plugins'/'themes'/'languages')
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
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jgrowl@1.3.0/jquery.jgrowl.min.css'),
            AssetContribution::css('themes/admin/default/css/pages/updates_ext.css', id: 'updates_ext'),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // No `dependsOn: ['jquery.ui']` (P49-C) -- jgrowl/jquery-confirm
            // are both real native ports now (P49-B), and confirmed zero
            // real jQuery UI calls left anywhere in `updates_ext.ts` itself.
            AssetContribution::script('updates_ext', 'themes/admin/default/js/updates_ext.ts', loadMode: LoadMode::Footer),
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
