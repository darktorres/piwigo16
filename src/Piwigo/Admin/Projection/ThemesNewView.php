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
 * `themes_new.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ThemesNewPageRenderer::render()}. `$newThemes` is
 * always included (even empty) since the template reads it with
 * `{if !empty($new_themes)}`, not `isset()`.
 */
#[Template('themes_new.latte')]
final readonly class ThemesNewView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array<string, mixed>> $newThemes
     */
    public function __construct(
        public string $defaultScreenshot,
        public array $newThemes,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('themes_new', 'themes/admin/default/js/themes_new.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'default_screenshot' => $this->defaultScreenshot,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
