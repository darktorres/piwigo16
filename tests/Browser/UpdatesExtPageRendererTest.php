<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\UpdatesExtPageRenderer (the "update" tab of plugins/themes/
 * languages, and the "ext" tab of admin.php?page=updates) -- already
 * GET-tested from all 4 real call sites by the extension-tabs smoke route,
 * exercising the $types_to_check per-page-slug filter. getPendingUpdates()
 * talks to piwigo.org over the network with no injectable seam, so it
 * always fails in this offline environment -> $all_types_reachable=false
 * -> the early-return "Can't connect to server" branch, itself already a
 * real exercised branch via those same 4 smoke routes. This file adds the
 * 2 guard branches those routes don't reach.
 */
it('shows a fatal error when the extensions install system is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install']);
    H::setConfigValue('enable_extensions_install', 'false');

    try {
        $page = H::loginAsAdmin($this);

        $result = H::rawGet($page, '/admin.php?page=updates&tab=ext');

        expect($result['body'])->toContain('Piwigo extensions install/update system is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'updates_ext_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addResult);

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    H::dbQuery($db, sprintf("UPDATE %suser_infos SET status = 'admin' WHERE user_id = %d", $prefix, $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=updates&tab=ext');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        H::dbQuery($db, sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        H::dbClose($db);
    }
});