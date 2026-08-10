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
    $page = H::loginAsAdmin($this);
    $username = 'std_pages_admin_' . uniqid();
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
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
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
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=themes_standard_pages', [
        'submit' => '1',
        'use_standard_pages' => '1',
    ]);

    expect($result['status'])->toBe(400);
});

it('persists the selected logo option and skin across a later plain visit', function (): void {
    $snapshot = H::snapshotConfig(['use_standard_pages', 'standard_pages_selected_logo', 'standard_pages_selected_skin']);
    $page = H::loginAsAdmin($this);

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
        expect($page->attribute('input[name=std_pgs_display_logo][value=gallery_title]', 'checked'))->not->toBeNull();
        expect($page->attribute('input[name=std_pgs_selected_skin]', 'value'))->toBe('cobalt');
        expect($page->attribute('input[name=use_standard_pages]', 'checked'))->not->toBeNull();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('unchecks use_standard_pages when the checkbox is simply omitted from the submission', function (): void {
    $snapshot = H::snapshotConfig(['use_standard_pages']);
    H::setConfigValue('use_standard_pages', 'true');
    $page = H::loginAsAdmin($this);

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

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    $stdPagesUrl = $baseUrl . '/admin.php?page=themes_standard_pages';
    $baseFields = [
        'pwg_token' => $pwgToken,
        'submit' => '1',
        'std_pgs_display_logo' => 'custom_logo',
        'std_pgs_selected_skin' => 'fuchsia',
    ];

    $txtPath = tempnam(sys_get_temp_dir(), 'pwg_std_pages_logo_') . '.txt';
    file_put_contents($txtPath, 'not an image');

    $pngPath = tempnam(sys_get_temp_dir(), 'pwg_std_pages_logo_') . '.png';
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
        expect($storedPath)->toBe('"logo/my_custom_logo.png"');

        $uploadedPath = dirname(__DIR__, 2) . '/local/logo/my_custom_logo.png';
        expect(file_exists($uploadedPath))->toBeTrue();

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
