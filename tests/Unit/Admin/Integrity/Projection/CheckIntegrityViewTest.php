<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\Projection\CheckIntegrityView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;

test('exposedPageData omits c13y_do_check_ids entirely when c13yDoCheck is null', function (): void {
    $view = new CheckIntegrityView(
        showSubmitAutomaticCorrection: false,
        showSubmitIgnore: false,
        c13yList: [],
        c13yDoCheck: null,
    );

    expect($view->exposedPageData())
        ->toBe([]);
});

test('exposedPageData includes c13y_do_check_ids when c13yDoCheck is a real list', function (): void {
    $view = new CheckIntegrityView(
        showSubmitAutomaticCorrection: false,
        showSubmitIgnore: false,
        c13yList: [],
        c13yDoCheck: ['1', '2'],
    );

    expect($view->exposedPageData())
        ->toBe([
            'c13y_do_check_ids' => ['1', '2'],
        ]);
});

test('pageAssets registers check_integrity.js unconditionally', function (): void {
    $view = new CheckIntegrityView(
        showSubmitAutomaticCorrection: false,
        showSubmitIgnore: false,
        c13yList: [],
        c13yDoCheck: null,
    );

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('check_integrity', 'themes/admin/default/js/check_integrity.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
        ]);
});
