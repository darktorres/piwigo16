<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\PopuphelpView;

test('pageAssets registers popuphelp.js for the front-end context', function (): void {
    $view = new PopuphelpView(helpContent: new Html(''), isAdminContext: false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('popuphelp', 'themes/default/js/popuphelp.ts', loadMode: LoadMode::Footer),
        ]);
});

test('pageAssets registers nothing for the admin context', function (): void {
    $view = new PopuphelpView(helpContent: new Html(''), isAdminContext: true);

    expect($view->pageAssets())
        ->toBe([]);
});
