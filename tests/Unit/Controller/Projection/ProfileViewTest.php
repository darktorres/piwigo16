<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\AssetKind;
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

test('pageAssets registers core_scripts_page for the default theme (profile_content.latte\'s own password-match check)', function (): void {
    $view = makeProfileView(false);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer),
        ]);
});

test('exposedPageData/exposedStrings are both empty for the default theme', function (): void {
    $view = makeProfileView(false);

    expect($view->exposedPageData())
        ->toBe([]);
    expect($view->exposedStrings())
        ->toBe([]);
});

test('pageAssets includes the merged-in ToasterView CSS but not its own toaster_js script for standard_pages (docs/PLAN.md P48 -- toaster.ts ships as a real import inside profile.ts\'s own bundle now, not a separate script tag)', function (): void {
    $view = makeProfileView(true);
    $assets = $view->pageAssets();

    expect($assets)
        ->toContainEqual(AssetContribution::css('themes/standard_pages/css/pages/toaster.css', id: 'toaster'));

    $scriptIds = array_map(
        static fn (AssetContribution $asset): string => $asset->id,
        array_filter($assets, static fn (AssetContribution $asset): bool => $asset->kind === AssetKind::Script),
    );
    expect($scriptIds)
        ->not->toContain('toaster_js');
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
