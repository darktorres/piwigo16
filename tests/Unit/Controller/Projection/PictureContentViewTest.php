<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PictureContentView;
use Piwigo\Core\Kernel;
use Piwigo\Image\ImageStdParams;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\PictureElementTestFactory;

beforeEach(function (): void {
    PictureElementTestFactory::boot();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

/**
 * The old version of this file asserted both methods return [] "when
 * selected_derivative is missing" -- a state `$current` being an untyped
 * array made reachable and the constructor now does not: the derivative
 * the page is showing is required. What remains testable is the real
 * branch, `isCached()`.
 */
function makePictureContentView(): PictureContentView
{
    $element = PictureElementTestFactory::build();

    return new PictureContentView(
        uOriginal: null,
        altImg: 'photo',
        cookiePath: '/',
        pdfViewerFilesizeThreshold: null,
        current: $element,
        selectedDerivative: $element->derivatives[ImageStdParams::MEDIUM],
        sizeOptions: $element->derivatives,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );
}

test('an uncached selected derivative pulls in the thumbnail loader and its error icon', function (): void {
    // `is_cached` only ever goes false on the derivative-URL style that
    // stats the file (0); the default (2) never looks at disk and leaves
    // it true. Under the throwaway root the file does not exist, so this
    // is the real uncached branch.
    CurrentConfigTestFactory::get()->derivativeUrlStyle = 0;

    $view = makePictureContentView();

    expect($view->pageAssets())
        ->toHaveCount(2);
    expect($view->exposedPageData())
        ->toBe([
            'error_icon' => 'http://example.com/icon/errors_small.png',
        ]);
});

test('a cached selected derivative contributes no assets and no page data', function (): void {
    // The default URL style, which never stats the file. Both hooks are
    // gated on the same isCached() call, so both stay empty -- the state
    // the old version of this file reached only by leaving
    // `selected_derivative` out of an untyped array entirely.
    $view = makePictureContentView();

    expect($view->pageAssets())
        ->toBe([]);
    expect($view->exposedPageData())
        ->toBe([]);
});
