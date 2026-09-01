<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\ThemesStandardPagesPageRenderer (admin.php?page=themes_standard_pages,
 * also reachable as admin.php?page=themes&tab=standard_pages) -- configures
 * the shared login/register/forgotten-password "standard pages" logo+skin.
 * No existing test file before this one.
 *
 * The `is_standard_pages_used`/`standard_pages_used_by` detection (a fs
 * theme whose themeconf.inc.php declares 'use_standard_pages' => true) is
 * NOT exercised: this test env's only real theme on disk (themes/default)
 * declares no such key (confirmed by direct read of its themeconf.inc.php),
 * and ExtensionType::scanDirectory() hardcodes the real themes/ path with
 * no injection point (same documented limitation as
 * ExtensionScannerTest/ThemesInstalledPageRendererTest's own docblocks), so
 * writing a second theme there is out of scope for the same blast-radius
 * reason. The mkgetdir()-failure and fopen()-failure `save_error` branches
 * (lines needing a real permission failure on the shared local/ disk) are
 * also not exercised for the same reason ThemesInstalledPageRendererTest
 * avoids mutating shared filesystem state destructively.
 */
it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::asAdmin($this);
    $username = 'std_pages_admin_' . uniqid();
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

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=themes_standard_pages');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

it('rejects a save submission with no CSRF token', function (): void {
    $page = H::asAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=themes_standard_pages', [
        'submit' => '1',
        'use_standard_pages' => '1',
    ]);

    expect($result['status'])->toBe(400);
});

it('persists the selected logo option and skin across a later plain visit', function (): void {
    $snapshot = H::snapshotConfig(['use_standard_pages', 'standard_pages_selected_logo', 'standard_pages_selected_skin']);
    $page = H::asAdmin($this);

    try {
        $result = H::adminPost($page, '/admin.php?page=themes_standard_pages', [
            'submit' => '1',
            'use_standard_pages' => '1',
            'std_pgs_display_logo' => 'gallery_title',
            'std_pgs_selected_skin' => 'cobalt',
            'pwg_token' => H::pwgToken($page),
        ]);
        expect($result['status'])->toBe(200);

        // Config is a shared JSON-encoded value (ConfigService::confUpdateParam()'s
        // own persist path) -- distinct, recognizable values (not the same
        // literal for both fields) so a transposition between the logo/skin
        // writes would be caught here.
        expect(H::configValue('standard_pages_selected_logo'))->toBe('"gallery_title"');
        expect(H::configValue('standard_pages_selected_skin'))->toBe('"cobalt"');
        expect(H::configValue('use_standard_pages'))->toBe('true');

        $page = H::navigateOk($page, '/admin.php?page=themes_standard_pages');
        expect($page->attribute('input[name=std_pgs_display_logo][value=gallery_title]', 'checked'))
            ->not->toBeNull();
        expect($page->attribute('input[name=std_pgs_selected_skin]', 'value'))
            ->toBe('cobalt');
        expect($page->attribute('input[name=use_standard_pages]', 'checked'))
            ->not->toBeNull();
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * themes_standard_pages.ts's own "scroll mini to show the selected one" --
 * 0% live-interaction coverage before this. Two real, independent bugs
 * found live and fixed here: `.std_pgs_mini_previews` (the scroll
 * container) has no `position` rule of its own, so it was never the real
 * `offsetParent` of its `<img>` children -- the old `.position()`-based
 * calculation measured the *container's* own distance from an unrelated
 * positioned ancestor further up the page, not the selected thumbnail's
 * distance from the top of the container's own scrollable content, landing
 * on an arbitrary offset (490px in this fixture, always the same wrong
 * distance regardless of which skin was actually selected). Separately,
 * computing that position at `ready()` time raced the 11 real `<img>`
 * mini-previews' own loads. Both are fixed by switching to
 * `selected.scrollIntoView({block: "nearest"})` (no offsetParent
 * assumption) after waiting for every mini-preview to settle.
 *
 * `"default"` (the first thumbnail, index 0) needs no real scroll -- every
 * other test in this file uses it or "cobalt" (index 2, still within the
 * container's own initial visible height), neither of which the old bug's
 * own wrong-by-490px offset would coincidentally look correct for, but
 * neither exercises real scrolling either. `"teal"` (index 10, the last of
 * 11) is the one real selection that must scroll to become visible at all.
 */
it('scrolls the selected skin into view, including one far enough down the list to need it', function (): void {
    $snapshot = H::snapshotConfig(['standard_pages_selected_skin']);
    $page = H::asAdmin($this);

    try {
        $page = H::navigateOk($page, '/admin.php?page=themes_standard_pages');

        // The real default ("default", index 0) needs zero scroll -- the
        // old bug's own wrong offset (a fixed ~490px, this fixture's own
        // real distance from an unrelated ancestor) would have failed this
        // exact assertion regardless of which skin were selected.
        $defaultScrollTop = H::scriptJson($page, <<<'JS'
            new Promise((resolve) => {
                new Promise((r) => setTimeout(r, 200)).then(() => {
                    resolve(JSON.stringify({
                        scrollTop: document.querySelector('.std_pgs_mini_previews').scrollTop,
                    }));
                });
            })
            JS);
        expect($defaultScrollTop['scrollTop'])->toBe(0);

        $result = H::adminPost($page, '/admin.php?page=themes_standard_pages', [
            'submit' => '1',
            'use_standard_pages' => '1',
            'std_pgs_selected_skin' => 'teal',
            'pwg_token' => H::pwgToken($page),
        ]);
        expect($result['status'])->toBe(200);

        $page = H::navigateOk($page, '/admin.php?page=themes_standard_pages');

        $state = H::scriptJson($page, <<<'JS'
            new Promise((resolve) => {
                new Promise((r) => setTimeout(r, 200)).then(() => {
                    const container = document.querySelector('.std_pgs_mini_previews');
                    const selected = container.querySelector('.selected');
                    const containerRect = container.getBoundingClientRect();
                    const selectedRect = selected.getBoundingClientRect();
                    resolve(JSON.stringify({
                        selectedId: selected.id,
                        scrollTop: container.scrollTop,
                        // The real assertion that matters: the selected
                        // thumbnail's own box is actually within the
                        // container's visible (scrolled) viewport, not
                        // merely that *some* scrolling happened.
                        visible:
                            selectedRect.top >= containerRect.top - 1 &&
                            selectedRect.bottom <= containerRect.bottom + 1,
                    }));
                });
            })
            JS);

        expect($state['selectedId'])->toBe('teal');
        expect($state['scrollTop'])->toBeGreaterThan(0);
        expect($state['visible'])->toBeTrue();

        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('unchecks use_standard_pages when the checkbox is simply omitted from the submission', function (): void {
    $snapshot = H::snapshotConfig(['use_standard_pages']);
    H::setConfigValue('use_standard_pages', 'true');
    $page = H::asAdmin($this);

    try {
        // Sending no `use_standard_pages` key at all (not '0') is exactly
        // how a real unchecked HTML checkbox submits -- confirmed against
        // ThemesStandardPagesSubmitRequest::fromArrays()'s own "not set" branch.
        $result = H::adminPost($page, '/admin.php?page=themes_standard_pages', [
            'submit' => '1',
            'pwg_token' => H::pwgToken($page),
        ]);
        expect($result['status'])->toBe(200);

        expect(H::configValue('use_standard_pages'))->toBe('false');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects a non-image logo upload with the generic invalid-image save_error, and accepts a real PNG', function (): void {
    $snapshot = H::snapshotConfig([
        'use_standard_pages',
        'standard_pages_selected_logo',
        'standard_pages_selected_skin',
        'standard_pages_selected_logo_path',
    ]);

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_std_pages_logo_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    $curl = static function (string $url, array $fields = []) use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusBody = H::curlApi($cookieJar, 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $stdPagesUrl = $baseUrl . '/admin.php?page=themes_standard_pages';
    $baseFields = [
        'pwg_token' => $pwgToken,
        'submit' => '1',
        'std_pgs_display_logo' => 'custom_logo',
        'std_pgs_selected_skin' => 'fuchsia',
    ];

    $txtTmp = tempnam(sys_get_temp_dir(), 'pwg_std_pages_logo_');
    if ($txtTmp === false) {
        throw new RuntimeException('tempnam() failed');
    }
    $txtPath = $txtTmp . '.txt';
    file_put_contents($txtPath, 'not an image');

    $pngTmp = tempnam(sys_get_temp_dir(), 'pwg_std_pages_logo_');
    if ($pngTmp === false) {
        throw new RuntimeException('tempnam() failed');
    }
    $pngPath = $pngTmp . '.png';
    $img = imagecreatetruecolor(16, 16);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img, $pngPath);

    $uploadedPath = null;

    try {
        // Wrong file type first: finfo_file()'s own MIME sniff rejects a
        // plain-text upload with no matching entry in $allowed_mimes,
        // without ever calling mkgetdir()/writing anything to disk.
        $txtResult = $curl($stdPagesUrl, array_merge($baseFields, [
            'std_pgs_logo' => new CURLFile($txtPath, 'text/plain', 'not-a-logo.txt'),
        ]));
        expect($txtResult['status'])->toBe(200);
        expect($txtResult['body'])->toContain('Invalid image file.');

        // Real PNG upload: StorageRegistry::disk('local') actually writes
        // the file under local/logo/, and the disk-relative path
        // ('logo/<slug>.png') gets persisted to config.
        $pngResult = $curl($stdPagesUrl, array_merge($baseFields, [
            'std_pgs_logo' => new CURLFile($pngPath, 'image/png', 'My Custom Logo!.png'),
        ]));
        expect($pngResult['status'])->toBe(200);
        expect($pngResult['body'])->not->toContain('Invalid image file.');

        // str2url()'s own slugging lowercases and replaces spaces with
        // underscores (confirmed live) -- "My Custom Logo!.png" becomes
        // "my_custom_logo.png", not a hyphenated slug. ConfigService::encode()
        // itself produces a PHP json_encode() string with the '/' escaped
        // ("\/"), but config.value is a real MySQL JSON column
        // (install/piwigo_structure-mysql.sql), which parses the inserted
        // text and re-serializes it in MySQL's own canonical form on
        // storage -- '/' needs no escaping per the JSON spec, so MySQL
        // drops the backslash; the raw column value read back here is
        // MySQL's canonical form, not PHP's literal encode() output.
        $storedPath = H::configValue('standard_pages_selected_logo_path');
        expect($storedPath)
            ->toBe('"logo/my_custom_logo.png"');

        $uploadedPath = dirname(__DIR__, 2) . '/local/logo/my_custom_logo.png';
        expect(file_exists($uploadedPath))
            ->toBeTrue();

        // std_pgs_selected_logo_path resolves to a root-relative logo.php
        // URL (CustomLogoController's own fixed, parameter-less route,
        // confirmed live) once a logo path is configured -- not the raw
        // disk-relative path stored in config.
        $pageResult = $curl($stdPagesUrl);
        expect($pageResult['body'])->toContain('<img src="logo.php">');
    } finally {
        @unlink($cookieJar);
        @unlink($txtPath);
        @unlink($pngPath);
        if ($uploadedPath !== null) {
            @unlink($uploadedPath);
        }
        H::restoreConfig($snapshot);
    }
});
