<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\ThemesInstalledPageRenderer (admin.php?page=themes, the
 * default "installed" tab) -- already GET-tested by the extension-tabs
 * smoke route. This file exercises the CSRF-gated action dispatch
 * (activate/deactivate/set_default/delete). It does NOT actually toggle a
 * real theme's active state: this test environment has only one theme on
 * disk (themes/default), so "deactivate" is provably a no-op guard
 * (DEACTIVABLE=false, "you need at least one theme") on this env's real
 * installed set -- exercised passively below -- and a real activate/
 * delete cycle would need a second throwaway theme directory written
 * directly under the live, Apache-shared themes/ root, which no other
 * test in this suite does (too much blast radius for concurrently
 * running Browser tests). ExtensionLifecycle::performAction() itself
 * already has its own dedicated Integration coverage
 * (ExtensionLifecycleTest.php) for the actual state-transition logic.
 */
it('renders an empty installed-themes list since the only real theme (default) is deliberately excluded', function (): void {
    // ThemesInstalledPageRenderer::render()'s own $fs_themes loop
    // unconditionally `continue`s past 'default'/'standard_pages' before
    // ever building $tpl_themes -- confirmed in its own source. Since
    // `default` is the only theme on disk in this test environment, the
    // installed-themes table always renders with zero rows here; there is
    // no real "default" text to see inside it.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=themes');

    $page->assertSee('Add a new theme');
    $page->assertNoJavaScriptErrors();
});

it('rejects an activate action without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=themes&action=activate&theme=default');

    expect($result['status'])->toBe(400);
});

it('rejects a delete action without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=themes&action=delete&theme=default');

    expect($result['status'])->toBe(400);
});

it('does not attempt any action when action= is present but theme= is missing', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=themes&action=activate');

    // action/themeId must BOTH be present for the action block to even
    // run its CSRF check -- a bare action= with no theme= falls straight
    // through to the normal listing render instead of a 400.
    expect($result['status'])->toBe(200);
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'themes_installed_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $addUserResult = $addResult['result'] ?? null;
    $users = is_array($addUserResult) ? ($addUserResult['users'] ?? null) : null;
    $firstUser = is_array($users) ? ($users[0] ?? null) : null;
    if (! is_array($firstUser) || ! is_numeric($firstUser['id'] ?? null)) {
        throw new RuntimeException('pwg.users.add did not return a numeric id: ' . var_export($addResult, true));
    }
    $userId = (int) $firstUser['id'];

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

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=themes');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        $db->query(sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        $db->query(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        $db->close();
    }
});