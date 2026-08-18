<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PluginsNewPageRenderer (admin.php?page=plugins&tab=new) --
 * already GET-tested by the extension-tabs smoke route; this file
 * exercises the installstatus= result-message switch (pure display logic,
 * no network) and the enableExtensionsInstall() disabled guard.
 * getVersionsToCheck()/getServerExtensions() (the actual PEM catalog
 * browse) talk to piwigo.org over the network with no injectable seam --
 * same documented limitation as PemCatalog itself -- so render() always
 * degrades to "Can't connect to server" in this offline test environment,
 * which every test below tolerates rather than asserting against.
 *
 * Several other still-red branches were investigated and confirmed
 * genuinely unreachable here, not just uncovered by oversight: the
 * description-trimming/revision-date-diff/certification-computation body
 * (the `foreach ($server_plugins as $plugin)` loop) is nested inside the
 * same PemCatalog network-only `$server_plugins !== null` gate as above.
 * The already-fs-installed exclusion filter (`$fs_plugin_ids` loop) scans
 * PluginLoader::pluginsPath() -- this test env's real plugins/ directory
 * is empty (confirmed by direct read), and ExtensionType::scanDirectory()
 * hardcodes that real path with no injection point (same documented
 * limitation as ExtensionScannerTest's own docblock), so writing a
 * throwaway plugin there would mean mutating the live, Apache-shared
 * plugins/ root while other Browser suites may run concurrently -- out of
 * scope for the same reason ThemesInstalledPageRendererTest's own docblock
 * gives for not writing a second theme under the shared themes/ root. The
 * beta-test URL param (`preg_match('/(beta|RC)/', AppInfo::VERSION)`) is
 * gated on AppInfo::VERSION (a fixed class constant, currently '17.0.0')
 * containing "beta" or "RC", which it never does -- not overridable from a
 * test.
 */
it('shows a fatal error when the extensions install system is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'false');

    try {
        $page = H::loginAsAdmin($this);

        $result = H::rawGet($page, '/admin.php?page=plugins&tab=new');

        expect($result['body'])->toContain('Piwigo extensions install/update system is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('reports success and offers an activate-it-now link for installstatus=ok', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new&installstatus=ok');

        $page->assertSee('Plugin has been successfully copied');
        $page->assertSee('Activate it now');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('reports each known installstatus error with its own message', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    try {
        $page = H::loginAsAdmin($this);

        // The loaded en_UK translation catalog rephrases these from their
        // literal PHP source msgids (e.g. "Can't create temporary file."
        // becomes "Temporary file cannot be created.") -- confirmed live
        // via a real raw request, not assumed from source.
        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new&installstatus=temp_path_error');
        $page->assertSee('Temporary file cannot be created');

        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new&installstatus=dl_archive_error');
        $page->assertSee('Archive cannot be downloaded');

        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new&installstatus=archive_error');
        $page->assertSee('Archive cannot be read or extracted');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('reports an unrecognized installstatus with the generic extraction-error message', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new&installstatus=some-unknown-status');

        $page->assertSee('An error occured during the files');
        $page->assertSee('Please check "plugins" folder and sub-folders permissions');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('runs the webmaster automatic-install branch with a valid CSRF token, landing on the dl_archive_error result page', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        // revision/extension are arbitrary PEM ids -- the real download
        // always fails in this offline test environment (AppInfo::DOMAIN
        // resolves to upstream.example.invalid, a deliberately
        // unresolvable placeholder, confirmed via a real DNS lookup), so
        // extractArchive() always returns 'dl_archive_error'. This still
        // exercises the webmaster+CSRF-checked automatic-install branch
        // itself (isWebmaster() true, checkOrFail() passing, extractArchive()
        // called, redirect to base_url with the resulting installstatus),
        // distinct from the passive installstatus= display-only cases
        // already covered above.
        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new&revision=999999&extension=888888&pwg_token=' . $token);

        $page->assertSee('Archive cannot be downloaded');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects an automatic-install request from a non-webmaster session', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    $page = H::loginAsAdmin($this);
    $username = 'plugins_new_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::createUser($page, [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = addedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)
            ->fill('password', $password)
            ->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=plugins&tab=new&revision=1&extension=1');
        $adminPage->assertSee('Webmaster status is required');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
        H::restoreConfig($snapshot);
    }
});
