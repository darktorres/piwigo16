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
 * `'default' === $theme_id` guard; confirmed neither InstallDefaultConfig
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
    $page = H::asAdmin($this);
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
    $page = H::asAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=themes&action=activate&theme=default');

    expect($result['status'])->toBe(400);
});

it('rejects a delete action without a valid CSRF token', function (): void {
    $page = H::asAdmin($this);

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
    $page = H::asAdmin($this);
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
        $page = H::asAdmin($this);
        $token = H::pwgToken($page);

        $page = H::navigateOk($page, '/admin.php?page=themes&action=deactivate&theme=default&pwg_token=' . $token);

        $page->assertSee('Impossible to deactivate this theme, you need at least one theme');
    } finally {
        H::dbQuery($db, "DELETE FROM themes WHERE id = 'default'");
        H::dbClose($db);
    }
});

it('does not attempt any action when action= is present but theme= is missing', function (): void {
    $page = H::asAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=themes&action=activate');

    // action/themeId must BOTH be present for the action block to even
    // run its CSRF check -- a bare action= with no theme= falls straight
    // through to the normal listing render instead of a 400.
    expect($result['status'])->toBe(200);
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::asAdmin($this);
    $username = 'themes_installed_admin_' . uniqid();
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

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=themes');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

/**
 * P58 step 0b. themes_installed.latte's row loop reads 17 distinct keys off
 * ThemesInstalledView::$tplThemes, and nothing asserted any of them --
 * because the loop cannot render in this repo at all. Every theme on disk
 * (default, standard_pages, golden_html_test) is on the renderer's own
 * exclusion list, which is what the first test in this file asserts.
 *
 * So covering it means putting a fourth, non-excluded theme on disk for the
 * duration of one page load. The manifest below carries the exact fields
 * ExtensionScanner reads into ThemeScanRow, with values distinctive enough
 * that finding them in the markup proves they came from this theme.
 *
 * The row lands in the *inactive* list: nothing INSERTs a `themes` DB row
 * for it, which is also the branch that decides activable/deletable.
 */
it('renders a real theme row, with every field it emits, when a non-excluded theme is on disk', function (): void {
    $themeId = 'p58_row_probe';
    $dir = dirname(__DIR__, 2) . '/themes/' . $themeId;

    if (is_dir($dir)) {
        throw new RuntimeException("{$dir} already exists; refusing to overwrite it");
    }

    mkdir($dir, 0o755, true);

    try {
        $manifest = json_encode([
            'id' => $themeId,
            'name' => 'P58 Row Probe',
            'version' => '3.2.1',
            'description' => 'Temporary theme proving the installed-themes row loop renders.',
            'license' => 'GPL-2.0-or-later',
            'minPiwigo' => '16.3.0',
            'main' => 'Piwigo\\Theme\\P58RowProbe\\Theme',
            'homepage' => 'https://example.invalid/p58-theme',
            'author' => 'P58 Author',
            'authorUri' => 'https://example.invalid/p58-author',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($manifest === false) {
            throw new RuntimeException('json_encode failed for the probe theme manifest');
        }
        file_put_contents($dir . '/theme.json', $manifest);

        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=themes');
        $html = H::rawWebpage($page)->content();

        // NAME/VERSION/DESC/AUTHOR and the two URLs, each emitted by its own
        // expression in the row loop.
        expect($html)
            ->toContain('P58 Row Probe')
            ->and($html)
            ->toContain('3.2.1')
            ->and($html)
            ->toContain('Temporary theme proving the installed-themes row loop renders.')
            ->and($html)
            ->toContain('P58 Author')
            ->and($html)
            ->toContain('https://example.invalid/p58-theme')
            ->and($html)
            ->toContain('https://example.invalid/p58-author');

        // ID reaches the action URLs the row builds, and STATE puts it in
        // the inactive fieldset -- both are row-loop expressions too.
        expect($html)
            ->toContain('action=activate')
            ->and($html)
            ->toContain('theme=' . $themeId)
            ->and($html)
            ->toContain('theme=' . $themeId);

        // STATE decides which fieldset the row lands in, and the legend is
        // the translated string ('Inactive themes'), not the source key
        // ('Inactive Themes') the template passes to |translate.
        expect($html)
            ->toContain('Inactive themes');

        // SCREENSHOT falls back to the admin theme's placeholder, since the
        // probe ships no screenshot.png.
        expect($html)
            ->toContain('missing_screenshot.png');

        $page->assertNoJavaScriptErrors();
    } finally {
        @unlink($dir . '/theme.json');
        @rmdir($dir);
    }
});
