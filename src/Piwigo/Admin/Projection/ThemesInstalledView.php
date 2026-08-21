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
 * `themes_installed.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ThemesInstalledPageRenderer::render()}.
 */
#[Template('themes_installed.latte')]
final readonly class ThemesInstalledView implements View, HasPageAssets, ExposesPageData
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
        public int $isWebmaster,
        public bool $enableExtensionsInstall,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
            AssetContribution::script('themes_installed', 'themes/admin/default/js/themes_installed.js', loadMode: LoadMode::Footer, dependsOn: ['common', 'jquery.confirm', 'jquery.colorbox', 'page-data']),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * `'Yes, I am sure'`/`'No, I have changed my mind'` already covered
     * unconditionally by `ThemeBaseAssets`'s own confirm-dialog triplet
     * (docs/PLAN.md's P42) -- dropped, not ported.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to delete the theme "%s"?',
        ];
    }
}
