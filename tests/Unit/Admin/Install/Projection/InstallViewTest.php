<?php

declare(strict_types=1);

use Piwigo\Admin\Install\Projection\InstallView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;

/**
 * @param list<mixed> $themes
 */
function makeInstallView(array $themes): InstallView
{
    return new InstallView(
        languageSelection: null,
        languageOptions: [],
        tContentEncoding: 'utf-8',
        release: '17.0.0',
        fAction: 'install.php',
        fDbHost: 'localhost',
        fDbUser: 'root',
        fDbName: 'piwigo',
        fDbDriver: 'mysqli',
        fDbPort: null,
        fAdmin: 'admin',
        fAdminEmail: 'admin@example.com',
        email: 'admin@example.com',
        fNewsletterSubscribe: false,
        lInstallHelp: '',
        install: null,
        errors: null,
        infos: null,
        themes: $themes,
    );
}

test('pageAssets registers each theme\'s own theme.css when load_css is true', function (): void {
    $view = makeInstallView([
        [
            'id' => 'clear',
            'load_css' => true,
        ],
        [
            'id' => 'dark',
            'load_css' => true,
        ],
    ]);

    $cssIds = array_map(
        static fn (AssetContribution $c): string => $c->path,
        array_filter($view->pageAssets(), static fn (AssetContribution $c): bool => str_contains($c->path, 'theme.css'))
    );

    expect($cssIds)
        ->toBe([
            'themes/admin/clear/theme.css',
            'themes/admin/dark/theme.css',
        ]);
});

test('pageAssets skips a theme whose load_css is false', function (): void {
    $view = makeInstallView([
        [
            'id' => 'clear',
            'load_css' => false,
        ],
    ]);

    $themeCss = array_filter($view->pageAssets(), static fn (AssetContribution $c): bool => str_contains($c->path, 'theme.css'));

    expect($themeCss)
        ->toBe([]);
});

test('pageAssets always registers the 4 static assets regardless of themes', function (): void {
    $view = makeInstallView([]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('jquery', 'themes/default/js/jquery.min.js'),
            AssetContribution::css('themes/admin/default/css/pages/install.css', id: 'install'),
            AssetContribution::script('jquery.cluetip', 'themes/default/js/plugins/jquery.cluetip.js', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            AssetContribution::script('install', 'themes/admin/default/js/install.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.cluetip']),
        ]);
});
