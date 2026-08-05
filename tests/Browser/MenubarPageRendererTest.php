<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\MenubarPageRenderer (admin.php?page=menubar) -- already
 * GET-tested as webmaster by AdminExtendedSmokeTest; this file adds the
 * webmaster-required warning for a plain "admin"-status session, and a
 * stale block-id entry in the persisted blk_menubar config (a block that
 * was once registered but no longer is).
 */
it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'menubar_admin_' . uniqid();
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

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=menubar');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        H::dbQuery($db, sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        H::dbClose($db);
    }
});

it('silently drops a stale block id from blk_menubar that no longer matches a registered block', function (): void {
    $snapshot = H::snapshotConfig(['blk_menubar']);
    // 'no_longer_registered_block' was once a real BlockManager block id,
    // but isn't one of the currently-registered ones -- render()'s own
    // `if (! isset($reg_blocks[$id])) { unset($mb_conf[$id]); }` loop
    // exists exactly to clean this up rather than carry it forward forever.
    H::setConfigValue('blk_menubar', '{"no_longer_registered_block":999}');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=menubar');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'menubar with a stale blk_menubar entry');
    } finally {
        H::restoreConfig($snapshot);
    }
});
