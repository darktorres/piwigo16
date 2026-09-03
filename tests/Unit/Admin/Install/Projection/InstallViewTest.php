<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Admin\Install\Projection\InstallView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Template\ThemeChainEntry;

/**
 * @param list<ThemeChainEntry> $themes
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
        lInstallHelp: new Html(''),
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

test('pageAssets registers each theme\'s own theme.css when loadCss is true', function (): void {
    $view = makeInstallView([
        new ThemeChainEntry(id: 'clear', loadCss: true),
        new ThemeChainEntry(id: 'dark', loadCss: true),
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

test('pageAssets skips a theme whose loadCss is false', function (): void {
    $view = makeInstallView([
        new ThemeChainEntry(id: 'clear', loadCss: false),
    ]);

    $themeCss = array_filter($view->pageAssets(), static fn (AssetContribution $c): bool => str_contains($c->path, 'theme.css'));

    expect($themeCss)
        ->toBe([]);
});

test('pageAssets always registers the 3 static assets regardless of themes', function (): void {
    $view = makeInstallView([]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/admin/default/css/pages/install.css', id: 'install'),
            AssetContribution::script('install', 'themes/admin/default/js/install.ts', loadMode: LoadMode::Footer),
            // No standalone page-data registration: install.ts imports it,
            // so its code ships inside the install bundle. Registering it
            // separately emitted a `<script src=".../page-data.ts">` tag,
            // since P48 removed page-data.ts as a Vite entry and there is
            // no manifest entry to resolve.
            //
            // No bare `jquery`/`jquery.cluetip` registrations either
            // (P49-B): `cluetip.ts`'s native port removed this page's
            // only real jQuery-cluetip call site, and jQuery itself had
            // no other real consumer on this page once that went.
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
