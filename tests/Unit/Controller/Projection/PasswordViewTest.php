<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\PasswordView;

function makePasswordView(bool $isStandardPagesTheme, ?string $action): PasswordView
{
    return new PasswordView(
        homeUrl: 'http://example.com/',
        key: null,
        usernameOrEmail: null,
        isFirstLogin: null,
        title: '',
        formAction: 'password.php',
        action: $action,
        username: null,
        pwgToken: 'token',
        languageOptions: [],
        currentLanguage: 'en_UK',
        helpLink: '',
        isStandardPagesTheme: $isStandardPagesTheme,
        standardPagesSelectedSkin: 'default',
    );
}

test('pageAssets registers no focus script when action is none of the 3 known values', function (): void {
    $view = makePasswordView(false, null);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer),
        ]);
});

test('pageAssets focuses username_or_email when action is lost', function (): void {
    $view = makePasswordView(false, 'lost');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('username_or_email');"),
        ]);
});

test('pageAssets focuses use_new_pwd when action is reset', function (): void {
    $view = makePasswordView(false, 'reset');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('use_new_pwd');"),
        ]);
});

test('pageAssets focuses user_code when action is lost_code', function (): void {
    $view = makePasswordView(false, 'lost_code');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('user_code');"),
        ]);
});

test('pageAssets registers the standard_pages theme\'s own asset list when standard_pages', function (): void {
    $view = makePasswordView(true, 'lost');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/standard_pages/skins/default.css', id: 'standard_pages_css', order: 100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::css('themes/standard_pages/css/pages/password.css', id: 'password'),
            AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.js', loadMode: LoadMode::Async, dependsOn: ['jquery']),
        ]);
});
