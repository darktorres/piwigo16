<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PictureContentView;

test('pageAssets/exposedPageData are both empty when selected_derivative is missing', function (): void {
    $view = new PictureContentView(
        uOriginal: null,
        altImg: 'photo',
        cookiePath: '/',
        pdfViewerFilesizeThreshold: null,
        current: [],
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );

    expect($view->pageAssets())
        ->toBe([]);
    expect($view->exposedPageData())
        ->toBe([]);
});
