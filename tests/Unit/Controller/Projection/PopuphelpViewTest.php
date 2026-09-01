<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\PopuphelpView;

test('pageAssets registers popuphelp.js for the front-end context', function (): void {
    $view = new PopuphelpView(helpContent: '', isAdminContext: false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('popuphelp', 'themes/default/js/popuphelp.ts', loadMode: LoadMode::Footer),
        ]);
});

test('pageAssets registers nothing for the admin context', function (): void {
    $view = new PopuphelpView(helpContent: '', isAdminContext: true);

    expect($view->pageAssets())
        ->toBe([]);
});
