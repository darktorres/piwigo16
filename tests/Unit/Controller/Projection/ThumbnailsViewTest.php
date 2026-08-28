<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Category\Projection\ImageThumbnail;
use Piwigo\Controller\Projection\ThumbnailsView;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\SizingParams;
use Piwigo\Image\SrcImage;

/**
 * @param array<int|string, mixed> $thumbnails
 */
/**
 * @param list<ImageThumbnail> $thumbnails
 */
function makeThumbnailsView(array $thumbnails): ThumbnailsView
{
    return new ThumbnailsView(
        derivativeParams: new DerivativeParams(SizingParams::classic(160, 90)),
        maxRequests: 3,
        showThumbnailCaption: true,
        thumbnails: $thumbnails,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
        pluginThumbnailOverlays: [],
    );
}

test('pageAssets/exposedPageData are both empty when there are no thumbnails', function (): void {
    $view = makeThumbnailsView([]);

    expect($view->pageAssets())
        ->toBe([]);
    expect($view->exposedPageData())
        ->toBe([]);
});

test('pageAssets registers the 3 entries and exposedPageData includes error_icon/max_requests when there are thumbnails', function (): void {
    // SrcImage's constructor reads CurrentConfig through the container.
    // Only the list being non-empty matters to pageAssets(); the tile's
    // contents are never read here.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-thumbnails-view-test'));

    $view = makeThumbnailsView([
        new ImageThumbnail(
            id: 1,
            name: 'photo1',
            url: 'picture.php?/1',
            tnAlt: 'photo1',
            tnTitle: 'photo1',
            srcImage: new SrcImage(SrcImageInfo::fromRow([
                'id' => 1,
                'path' => 'galleries/photo1.jpg',
            ])),
        ),
    ]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/pages/thumbnails.css', id: 'thumbnails'),
            AssetContribution::script('jquery.ajaxmanager', 'https://cdn.jsdelivr.net/gh/aFarkas/Ajaxmanager@3.12/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager']),
        ]);
    expect($view->exposedPageData())
        ->toBe([
            'error_icon' => 'http://example.com/icon/errors_small.png',
            'max_requests' => 3,
        ]);
});
