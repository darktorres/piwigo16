<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Admin\Projection\HelpView;
use Piwigo\Asset\AssetContribution;

test('pageAssets registers the help stylesheet when synchronization is disabled', function (): void {
    $view = new HelpView(helpContent: new Html(''), helpSectionTitle: new Html(''), enableSynchronization: false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/admin/default/css/pages/help.css', id: 'help'),
        ]);
});

test('pageAssets registers nothing when synchronization is enabled', function (): void {
    $view = new HelpView(helpContent: new Html(''), helpSectionTitle: new Html(''), enableSynchronization: true);

    expect($view->pageAssets())
        ->toBe([]);
});
