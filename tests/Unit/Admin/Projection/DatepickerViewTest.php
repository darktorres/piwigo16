<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\DatepickerView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;

test('pageAssets skips per-language scripts for a locale neither package ships', function (): void {
    $view = new DatepickerView(jqueryCode: 'en');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('jquery.ui.timepicker-addon', 'https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui']),
            AssetContribution::script('datepicker', 'themes/admin/default/js/datepicker.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon']),
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui'),
            AssetContribution::css('https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.min.css'),
        ]);
});

test('pageAssets registers both per-language scripts for a locale both packages ship', function (): void {
    $view = new DatepickerView(jqueryCode: 'fr');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('jquery.ui.timepicker-addon', 'https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui']),
            AssetContribution::script('jquery.ui.datepicker-fr', 'https://cdn.jsdelivr.net/gh/jquery/jquery-ui@1.10.4/ui/i18n/jquery.ui.datepicker-fr.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui']),
            AssetContribution::script('jquery.ui.timepicker-fr', 'https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/i18n/jquery-ui-timepicker-fr.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon']),
            AssetContribution::script('datepicker', 'themes/admin/default/js/datepicker.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon', 'jquery.ui.datepicker-fr', 'jquery.ui.timepicker-fr']),
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui'),
            AssetContribution::css('https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.min.css'),
        ]);
});

test('pageAssets honors an explicit load_mode', function (): void {
    $view = new DatepickerView(load_mode: 'async', jqueryCode: 'en');

    expect($view->pageAssets()[0]->loadMode)->toBe(LoadMode::Async);
});
