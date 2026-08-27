<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `mainpage_categories.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} from
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}'s own {@see
 * \Piwigo\Category\Projection\CategoryCatsResult}, same L2aCoreDomain
 * split as {@see ThumbnailsView}/`CategoryDefaultRenderer`. `$rootUrl`/
 * `$iconDir` are the ambient `$ROOT_URL`/`$themeconf['icon_dir']` the
 * template's own `error_icon` `exposeData` call reads.
 */
#[Template('mainpage_categories.latte')]
final readonly class CategoryCatsView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<int|string, mixed> $categoryThumbnails
     */
    public function __construct(
        public int $maxRequests,
        public array $categoryThumbnails,
        public DerivativeParams $derivativeParams,
        public string $rootUrl,
        public string $iconDir,
    ) {}

    /**
     * `mainpage_categories.latte`'s own unconditional
     * `{do combineCss(...)}` plus its `{if !$derivative->isCached()}`-
     * gated `{do combineScript(...)}` pair, registered unconditionally
     * here -- same dedup-safe widening as `CommentListView`'s own
     * identical pattern (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/pages/mainpage_categories.css', id: 'mainpage_categories'),
            AssetContribution::script('jquery.ajaxmanager', 'https://cdn.jsdelivr.net/gh/aFarkas/Ajaxmanager@3.12/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
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
