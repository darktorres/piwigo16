<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PictureCoiView;

test('exposedPageData omits coi entirely when coi is null', function (): void {
    $view = new PictureCoiView(
        alt: 'photo',
        imgUrl: 'http://example.com/photo.jpg',
        coi: null,
        croppedDerivatives: [],
    );

    expect($view->exposedPageData())
        ->toBe([]);
});

test('exposedPageData includes coi when coi is a real crop-of-interest box', function (): void {
    $coi = [
        'l' => 0.1,
        't' => 0.2,
        'r' => 0.8,
        'b' => 0.9,
    ];

    $view = new PictureCoiView(
        alt: 'photo',
        imgUrl: 'http://example.com/photo.jpg',
        coi: $coi,
        croppedDerivatives: [],
    );

    expect($view->exposedPageData())
        ->toBe([
            'coi' => $coi,
        ]);
});
