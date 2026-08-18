<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\ThemesInstalledPageRenderer (admin.php?page=themes, the
 * default "installed" tab) -- already GET-tested by the extension-tabs
 * smoke route. This file exercises the CSRF-gated action dispatch
 * (activate/deactivate/set_default/delete). It does NOT actually toggle a
 * real theme's active state: this test environment's live, Apache-shared
 * themes/ root has 'default' and 'golden_html_test' on disk (the latter
 * a deliberate non-selectable test fixture, see themes/golden_html_test/
 * theme.json's own description), both excluded from the installed-themes
 * list by render()'s own `continue` guard -- a real activate/delete cycle
 * would need a genuine 3rd, throwaway theme directory written directly
 * under that live root, which no other test in this suite does (too
 * much blast radius for concurrently running Browser tests).
 * ExtensionLifecycle::performAction() itself already has its own dedicated
 * Integration coverage (ExtensionLifecycleTest.php) for the actual
 * state-transition logic.
 *
 * 'default' itself NEVER gets a `themes` DB row through any real code
 * path -- ExtensionLifecycle::performThemeAction()'s 'activate' case
 * special-cases `$id === 'default'` into an unconditional no-op break
 * (faithful port of legacy admin/include/themes.class.php's identical
 * `'default' === $theme_id` guard; confirmed neither install/config.sql
 * nor any fixture ever INSERTs a 'default' row either). So `action=
 * deactivate&theme=default` always hits this class's own `$dbRow === null`
 * early break first and never reaches the "need at least one theme" guard
 * -- the "you need at least one theme" test below seeds that DB row
 * directly (matching StatsPageRendererTest's own precedent of raw-SQL
 * seeding to reach an otherwise-unreachable-via-the-app code path) so the
 * guard itself is still exercised, without relying on an activation path
 * that can't produce that state for 'default'.
 */
it('renders an empty installed-themes list since every real theme on disk is deliberately excluded', function (): void {
    // ThemesInstalledPageRenderer::render()'s own $fs_themes loop
    // unconditionally `continue`s past 'default'/'standard_pages'/
    // 'golden_html_test' before ever building $tpl_themes -- confirmed in
    // its own source. Every theme actually on disk in this test
    // environment falls into that exclusion list, so the installed-themes
    // table always renders with zero rows here.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=themes');

    $page->assertSee('Add a new theme');
    // The real regression this test previously missed: golden_html_test
    // (a non-selectable test fixture, not a real theme) leaked into this
    // admin-visible, webmaster-activatable list before the exclusion
    // above existed.
    $page->assertDontSee('golden_html_test');
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

it('handles a CSRF-valid activate action on the already-active default theme as a safe no-op redirect', function (): void {
    // ExtensionLifecycle::performThemeAction()'s 'activate' case breaks
    // immediately with zero errors when the theme already has a DB row
    // ($dbRow !== null), before any real activation logic runs -- 'default'
    // is always installed in this test environment, so this exercises the
    // CSRF-valid action-dispatch block (fs-entry lookup + performAction()
    // call + the action_errors===[] "deleteCompiledTemplates()+redirect"
    // branch) as a genuine no-op, without ever mutating real theme state.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $page = H::navigateOk($page, '/admin.php?page=themes&action=activate&theme=default&pwg_token=' . $token);

    $page->assertSee('Add a new theme');
});

it('rejects a CSRF-valid deactivate action on the only installed theme with the "need at least one theme" error', function (): void {
    // performThemeAction()'s 'deactivate' case checks `$dbRow === null`
    // before ever reaching `$this->repo->count(Theme) <= 1` -- 'default'
    // never has a real `themes` row (see this file's own top docblock), so
    // that row is seeded directly here to make the count<=1 guard
    // reachable at all, exactly the state a genuinely-installed
    // last-remaining theme would be in.
    $db = H::connect();
    H::dbQuery($db, "DELETE FROM themes WHERE id = 'default'");
    H::dbQuery($db, "INSERT INTO themes (id, version, name) VALUES ('default', '1.0.0', 'default')");

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $page = H::navigateOk($page, '/admin.php?page=themes&action=deactivate&theme=default&pwg_token=' . $token);

        $page->assertSee('Impossible to deactivate this theme, you need at least one theme');
    } finally {
        H::dbQuery($db, "DELETE FROM themes WHERE id = 'default'");
        H::dbClose($db);
    }
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
    $userId = wsAddedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)
            ->fill('password', $password)
            ->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=themes');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});
