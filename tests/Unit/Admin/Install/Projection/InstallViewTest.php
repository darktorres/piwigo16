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
        fSendCredentialsByMail: false,
        lInstallHelp: '',
        install: null,
        errors: null,
        infos: null,
        themes: $themes,
        dedupErrorStrings: [],
        hasExistingInstall: null,
        overwriteToken: null,
        writableChecks: [],
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

test('pageAssets always registers the 5 static assets regardless of themes', function (): void {
    $view = makeInstallView([]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('jquery', 'https://cdn.jsdelivr.net/npm/jquery@1.11.3/dist/jquery.min.js'),
            AssetContribution::css('themes/admin/default/css/pages/install.css', id: 'install'),
            AssetContribution::script('jquery.cluetip', 'https://cdn.jsdelivr.net/gh/kswedberg/jquery-cluetip@1.2.6/jquery.cluetip.js', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            AssetContribution::script('install', 'themes/admin/default/js/install.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.cluetip']),
            AssetContribution::script('page-data', 'themes/default/js/page-data.ts', loadMode: LoadMode::Footer),
        ]);
});

test('exposedStrings returns the install-check translated strings', function (): void {
    $view = makeInstallView([]);

    expect($view->exposedStrings())
        ->toBe([
            'Testing connection...',
            'Connection successful',
            'Connected to the database, but couldn\'t verify whether it already contains a Piwigo installation — check the database user\'s privileges to list tables',
            'webmaster login can\'t contain characters \' or "',
            'please enter your password again',
            'mail address must be like xxx@yyy.eee (example : jack@altern.org)',
        ]);
});

test('exposedPageData returns no dynamic data', function (): void {
    $view = makeInstallView([]);

    expect($view->exposedPageData())
        ->toBe([]);
});
