<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\RegisterView;

function makeRegisterView(bool $isStandardPagesTheme): RegisterView
{
    return new RegisterView(
        homeUrl: 'http://example.com/',
        formKey: 'key',
        formAction: 'register.php',
        formLogin: '',
        formEmail: '',
        obligatoryUserMailAddress: false,
        languageOptions: [],
        currentLanguage: 'en_UK',
        helpLink: '',
        isStandardPagesTheme: $isStandardPagesTheme,
        standardPagesSelectedSkin: 'default',
        pluginRegisterFields: [],
        pluginAuthButtons: [],
        formSendPasswordByMail: false,
    );
}

test('pageAssets registers the default theme\'s core_scripts_page bundle + footerScript when not standard_pages', function (): void {
    $view = makeRegisterView(false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('login');"),
        ]);
});

test('pageAssets registers the standard_pages theme\'s own asset list when standard_pages', function (): void {
    $view = makeRegisterView(true);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/standard_pages/skins/default.css', id: 'standard_pages_css', order: 100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.ts', loadMode: LoadMode::Async, dependsOn: ['jquery']),
        ]);
});

test('exposedStrings returns the password-match and mail-format translated strings', function (): void {
    $view = makeRegisterView(false);

    expect($view->exposedStrings())
        ->toBe([
            'The passwords do not match',
            'mail address must be like xxx@yyy.eee (example : jack@altern.org)',
        ]);
});

test('exposedPageData returns no dynamic data', function (): void {
    $view = makeRegisterView(false);

    expect($view->exposedPageData())
        ->toBe([]);
});
