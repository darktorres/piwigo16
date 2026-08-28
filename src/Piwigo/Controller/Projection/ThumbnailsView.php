<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Category\Projection\ImageThumbnail;
use Piwigo\Contribution\ThumbnailOverlay;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `thumbnails.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} from
 * {@see \Piwigo\Category\CategoryDefaultRenderer::render()}'s own {@see
 * \Piwigo\Category\Projection\CategoryDefaultResult}. `CategoryDefaultRenderer`
 * is `Piwigo\Category\*` (L2aCoreDomain) and may not depend on
 * `Renderer`/`View`/`#[Template]` (L3Presentation) directly -- it stays a
 * pure data-returning method, same shape as
 * `Piwigo\Picture\PictureMetadataRenderer`/`PictureRateRenderer`, and its
 * caller (a Controller, always L3/L4) does the actual render. The rendered
 * `Html` is then written into `Template::$vars['THUMBNAILS']` via {@see
 * ThumbnailsHtmlPageContext}, staying an ambient sibling contributor
 * `index.latte` reads directly, not folded onto `IndexView` itself.
 * `$rootUrl`/`$iconDir` are the ambient `$ROOT_URL`/`$themeconf['icon_dir']`
 * the template's own `error_icon` `exposeData` call reads.
 * `$pluginThumbnailOverlays` is `$template->thumbnailOverlays()` --
 * `CategoryDefaultRenderer` (L2aCoreDomain) may not call `Template`
 * directly, so `GalleryController` (L3/L4) resolves it the same way it
 * resolves every other field here.
 */
#[Template('thumbnails.latte')]
final readonly class ThumbnailsView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<ImageThumbnail> $thumbnails
     * @param list<ThumbnailOverlay> $pluginThumbnailOverlays
     */
    public function __construct(
        public DerivativeParams $derivativeParams,
        public int $maxRequests,
        public bool $showThumbnailCaption,
        public array $thumbnails,
        public string $rootUrl,
        public string $iconDir,
        public array $pluginThumbnailOverlays,
    ) {}

    /**
     * `thumbnails.latte`'s own `{if !empty($thumbnails)}`-gated
     * `{do combineCss(...)}` plus its `{if !$derivative->isCached()}`-
     * gated `{do combineScript(...)}` pair, registered unconditionally
     * whenever there are thumbnails -- same dedup-safe widening as
     * `CommentListView`'s own identical pattern (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        if ($this->thumbnails === []) {
            return [];
        }

        return [
            AssetContribution::css('themes/default/css/pages/thumbnails.css', id: 'thumbnails'),
            AssetContribution::script('jquery.ajaxmanager', 'https://cdn.jsdelivr.net/gh/aFarkas/Ajaxmanager@3.12/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        if ($this->thumbnails === []) {
            return [];
        }

        return [
            'error_icon' => $this->rootUrl . $this->iconDir . '/errors_small.png',
            'max_requests' => $this->maxRequests,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
