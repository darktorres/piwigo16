<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\DatepickerView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;

$rootPath = dirname(__DIR__, 4) . '/';

test('pageAssets skips per-language scripts when neither locale file exists', function () use ($rootPath): void {
    $view = new DatepickerView(rootPath: $rootPath, jqueryCode: 'en');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('jquery.ui.timepicker-addon', 'themes/default/js/ui/jquery.ui.timepicker-addon.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.datepicker', 'jquery.ui.slider']),
            AssetContribution::script('datepicker', 'themes/admin/default/js/datepicker.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon']),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.theme.css'),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css'),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.datepicker.css'),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.timepicker-addon.css'),
        ]);
});

test('pageAssets registers both per-language scripts when both locale files exist', function () use ($rootPath): void {
    $view = new DatepickerView(rootPath: $rootPath, jqueryCode: 'fr');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('jquery.ui.timepicker-addon', 'themes/default/js/ui/jquery.ui.timepicker-addon.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.datepicker', 'jquery.ui.slider']),
            AssetContribution::script('jquery.ui.datepicker-fr', 'themes/default/js/ui/i18n/jquery.ui.datepicker-fr.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.datepicker']),
            AssetContribution::script('jquery.ui.timepicker-fr', 'themes/default/js/ui/i18n/jquery.ui.timepicker-fr.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon']),
            AssetContribution::script('datepicker', 'themes/admin/default/js/datepicker.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon', 'jquery.ui.datepicker-fr', 'jquery.ui.timepicker-fr']),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.theme.css'),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css'),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.datepicker.css'),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.timepicker-addon.css'),
        ]);
});

test('pageAssets honors an explicit load_mode', function () use ($rootPath): void {
    $view = new DatepickerView(load_mode: 'async', rootPath: $rootPath, jqueryCode: 'en');

    expect($view->pageAssets()[0]->loadMode)->toBe(LoadMode::Async);
});
