<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\PictureNavButtonsView;
use Piwigo\Controller\Projection\PictureNavEntry;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\PictureElementTestFactory;

// Building a PictureNavEntry means building a real SrcImage, which
// resolves CurrentConfig out of the container.
beforeEach(function (): void {
    PictureElementTestFactory::boot();
});

afterEach(function (): void {
    Kernel::reset();
});

/**
 * @param array<string, string>|null $slideshowNav
 */
function makePictureNavButtonsView(
    ?PictureNavEntry $navNext = null,
    ?array $slideshowNav = null,
): PictureNavButtonsView {
    return new PictureNavButtonsView(
        navFirst: null,
        navPrevious: null,
        navNext: $navNext,
        navLast: null,
        uUp: 'http://example.com/up',
        displayNavButtons: true,
        slideshowNav: $slideshowNav,
    );
}

test('pageAssets registers picture_nav_buttons.js unconditionally', function (): void {
    $view = makePictureNavButtonsView();

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('picture_nav_buttons', 'themes/default/js/picture_nav_buttons.ts', loadMode: LoadMode::Footer),
        ]);
});

test('exposedPageData omits every nav key when everything is null', function (): void {
    $view = makePictureNavButtonsView();

    expect($view->exposedPageData())
        ->toBe([
            'nav_up_url' => 'http://example.com/up',
        ]);
});

test('exposedPageData includes nav_next_url when navNext is set', function (): void {
    $view = makePictureNavButtonsView(
        navNext: PictureElementTestFactory::navEntry(imgUrl: 'http://example.com/next'),
    );

    expect($view->exposedPageData())
        ->toBe([
            'nav_next_url' => 'http://example.com/next',
            'nav_up_url' => 'http://example.com/up',
        ]);
});

test('exposedPageData omits nav_up_url when slideshowNav is set', function (): void {
    $view = makePictureNavButtonsView(slideshowNav: [
        'U_START_PLAY' => 'http://example.com/start',
    ]);

    expect($view->exposedPageData())
        ->toBe([
            'nav_slideshow_start_url' => 'http://example.com/start',
        ]);
});
