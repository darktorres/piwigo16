<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Template\Projection\LocalHeadView;

test('pageAssets registers print.css when load_css is true', function (): void {
    $view = new LocalHeadView(load_css: true);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/print.css', order: -10),
        ]);
});

test('pageAssets registers nothing when load_css is false', function (): void {
    $view = new LocalHeadView(load_css: false);

    expect($view->pageAssets())
        ->toEqual([]);
});
