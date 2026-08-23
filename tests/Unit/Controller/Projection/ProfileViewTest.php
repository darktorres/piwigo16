<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\ProfileView;

function makeProfileView(bool $isStandardPagesTheme): ProfileView
{
    return new ProfileView(
        profileContent: new Html(''),
        username: 'alice',
        email: 'alice@example.com',
        allowUserCustomization: true,
        defaultUserValues: [],
        apiSelectedExpiration: null,
        apiCanManage: false,
        helpLink: '',
        csrfToken: 'token',
        nbImagePage: 15,
        templateOptions: [],
        templateSelection: '',
        languageOptions: [],
        languageSelection: 'en_UK',
        recentPeriod: 7,
        expand: 'false',
        activateComments: true,
        nbComments: 'false',
        nbHits: 'false',
        specialUser: false,
        apiExpiration: [],
        apiCurrentDate: '',
        apiEmailInfos: '',
        isStandardPagesTheme: $isStandardPagesTheme,
        standardPagesSelectedSkin: 'default',
        pluginProfileFields: [],
        pluginFieldOverrides: [],
        pluginFormProviders: [],
    );
}

test('pageAssets registers core.scripts for the default theme (profile_content.latte\'s own password-match check)', function (): void {
    $view = makeProfileView(false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer),
        ]);
});

test('exposedPageData/exposedStrings are both empty for the default theme', function (): void {
    $view = makeProfileView(false);

    expect($view->exposedPageData())
        ->toBe([]);
    expect($view->exposedStrings())
        ->toBe([]);
});

test('pageAssets includes the merged-in ToasterView contribution for standard_pages', function (): void {
    $view = makeProfileView(true);

    expect($view->pageAssets())
        ->toContainEqual(AssetContribution::script('toaster_js', 'themes/standard_pages/js/toaster.js', loadMode: LoadMode::Async, dependsOn: ['jquery']))
        ->toContainEqual(AssetContribution::css('themes/standard_pages/css/pages/toaster.css', id: 'toaster'));
});

test('exposedPageData exposes can_update_password as the inverse of specialUser for standard_pages', function (): void {
    $view = makeProfileView(true);

    expect($view->exposedPageData()['can_update_password'])->toBeTrue();
});

test('exposedStrings returns all 13 strings for standard_pages, including the shared password-match string', function (): void {
    $view = makeProfileView(true);

    expect($view->exposedStrings())
        ->toHaveCount(13)
        ->toContain('The passwords do not match');
});
