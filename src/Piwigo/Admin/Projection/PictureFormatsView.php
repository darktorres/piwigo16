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
 * `picture_formats.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PictureFormatsPageRenderer::render()} -- shared by two
 * real callers, {@see \Piwigo\Controller\Admin\PictureFormatsSubController}
 * and {@see \Piwigo\Controller\Admin\PhotoSubController}'s own "formats"
 * tab. `$formats` stays a loose row shape: each entry is a real format
 * DB row plus 3 view-only keys spliced on per row (`download_url`/
 * `label`/`filesize`), not a fixed structural shape worth minting its
 * own DTO for here.
 */
#[Template('picture_formats.latte')]
final readonly class PictureFormatsView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array<string, mixed>> $formats
     */
    public function __construct(
        public string $addFormatsUrl,
        public string $imgSquareSrc,
        public array $formats,
        public string $pwgToken,
    ) {}

    /**
     * `picture_formats.latte`'s own unconditional `{do combineCss(...)}`x3/
     * `{do combineScript(...)}`x3 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/picture_formats.css', id: 'picture_formats'),
            AssetContribution::script('picture_formats', 'themes/admin/default/js/picture_formats.ts', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::script('common', 'themes/admin/default/js/common.ts', loadMode: LoadMode::Footer),
        ];
    }

    /**
     * `picture_formats.latte`'s own unconditional `{do exposeData(...)}`/
     * `{do exposeString(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'csrf_token' => $this->pwgToken,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Delete %s format ?',
        ];
    }
}
