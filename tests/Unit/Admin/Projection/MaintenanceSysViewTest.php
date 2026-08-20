<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MaintenanceSysView;
use Piwigo\Asset\AssetContribution;

test('pageAssets registers the animation and maintenance_sys stylesheets for a webmaster', function (): void {
    $view = new MaintenanceSysView(isWebmaster: true, activityLogEntries: []);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/maintenance_sys.css', id: 'maintenance_sys'),
        ]);
});

test('pageAssets registers nothing for a non-webmaster', function (): void {
    $view = new MaintenanceSysView(isWebmaster: false, activityLogEntries: []);

    expect($view->pageAssets())
        ->toBe([]);
});
