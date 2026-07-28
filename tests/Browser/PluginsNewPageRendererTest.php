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

it('rejects an automatic-install request from a non-webmaster session', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    $page = H::loginAsAdmin($this);
    $username = 'plugins_new_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addResult);

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf("UPDATE %suser_infos SET status = 'admin' WHERE user_id = %d", $prefix, $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=plugins&tab=new&revision=1&extension=1');
        $adminPage->assertSee('Webmaster status is required');
    } finally {
        $db->query(sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        $db->query(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        $db->close();
        H::restoreConfig($snapshot);
    }
});