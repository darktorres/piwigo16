<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\IdentificationView;

function makeIdentificationView(bool $isStandardPagesTheme): IdentificationView
{
    return new IdentificationView(
        homeUrl: 'http://example.com/',
        redirect: '',
        loginAction: 'http://example.com/identification.php',
        authorizeRemembering: true,
        register: null,
        lostPassword: null,
        languageOptions: [],
        currentLanguage: 'en_UK',
        helpLink: '',
        isStandardPagesTheme: $isStandardPagesTheme,
        standardPagesSelectedSkin: 'default',
        pluginAuthButtons: [],
    );
}

test('pageAssets registers the default theme\'s core_scripts_page bundle + footerScript when not standard_pages', function (): void {
    $view = makeIdentificationView(false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('username');"),
        ]);
});

test('pageAssets registers the standard_pages theme\'s own asset list when standard_pages', function (): void {
    $view = makeIdentificationView(true);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/standard_pages/skins/default.css', id: 'standard_pages_css', order: 100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.ts', loadMode: LoadMode::Async, dependsOn: ['jquery']),
        ]);
});
