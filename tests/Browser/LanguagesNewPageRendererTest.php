<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\LanguagesNewPageRenderer (admin.php?page=languages&tab=new)
 * -- already GET-tested by the extension-tabs smoke route. Same pattern as
 * PluginsNewPageRendererTest: installstatus= is pure display logic (no
 * network); getServerExtensions() (the actual PEM catalog browse) talks
 * to piwigo.org over the network with no injectable seam, so it always
 * degrades to "Can't connect to server" in this offline test environment.
 */
it('shows a fatal error when the extensions install system is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'false');

    try {
        $page = H::asAdmin($this);

        $result = H::rawGet($page, '/admin.php?page=languages&tab=new');

        expect($result['body'])->toContain('Piwigo extensions install/update system is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('reports success for installstatus=ok', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=languages&tab=new&installstatus=ok');

        $page->assertSee('Language has been successfully installed');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('reports each known installstatus error with its own message', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    try {
        $page = H::asAdmin($this);

        // The loaded en_UK translation catalog rephrases these from their
        // literal PHP source msgids (e.g. "Can't create temporary file."
        // becomes "Temporary file cannot be created.") -- confirmed live
        // via a real raw request, not assumed from source.
        $page = H::navigateOk($page, '/admin.php?page=languages&tab=new&installstatus=temp_path_error');
        $page->assertSee('Temporary file cannot be created');

        $page = H::navigateOk($page, '/admin.php?page=languages&tab=new&installstatus=dl_archive_error');
        $page->assertSee('Archive cannot be downloaded');

        $page = H::navigateOk($page, '/admin.php?page=languages&tab=new&installstatus=archive_error');
        $page->assertSee('Archive cannot be read or extracted');

        $page = H::navigateOk($page, '/admin.php?page=languages&tab=new&installstatus=some-unknown-status');
        $page->assertSee('An error occured during the files');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects an install request from a non-webmaster session', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'true');

    $page = H::asAdmin($this);
    $username = 'languages_new_admin_' . uniqid();
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

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=languages&tab=new&revision=1');
        $adminPage->assertSee('Webmaster status is required');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
        H::restoreConfig($snapshot);
    }
});

/**
 * languages_new.ts's whole body is one cluetip call inside a ready()
 * wrapper. P49-A converts the wrapper; cluetip itself is a library and
 * goes in P49-B, so what needs proving here is only that the callback
 * still fires under the new ready().
 *
 * cluetip's own init inserts `#cluetip-waitimage` into the document if it
 * is not already there, which is the earliest observable effect of the
 * call and needs no hover to produce.
 */
it('runs its ready callback, initializing cluetip on load', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=languages&tab=new');

    expect($page->script('document.querySelectorAll("#cluetip-waitimage").length'))
        ->toBe(1);
    $page->assertNoJavaScriptErrors();
});
