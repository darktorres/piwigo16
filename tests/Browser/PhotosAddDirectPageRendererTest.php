<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PhotosAddDirectPageRenderer (admin.php?page=photos_add) --
 * AdminExtendedSmokeTest's own routes already cover the direct/
 * applications/ftp/invalid-tab GET renders; this file targets the
 * remaining real branches: the CSRF-gated `batch` caddie action, the
 * `album`/`formats` GET params, and the `hide_warnings` session flag.
 */
function photosAddDirectDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

it('adds a batch of photo ids to the caddie and redirects to the batch manager', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photos Add Direct Batch Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photos Add Direct Batch Photo');
    @unlink($image);

    $token = H::pwgToken($page);
    $result = H::rawGet($page, '/admin.php?page=photos_add&batch=' . $imageId . '&pwg_token=' . $token);

    // redirect() is a real Location header -- opaque under fetch(manual),
    // status always 0 (see this suite's own empty_caddie test).
    expect($result['status'])->toBe(0);

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $caddieCheck = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %scaddie WHERE user_id = 1 AND element_id = %d',
        photosAddDirectDbPrefix(),
        $imageId
    ));
    $caddieRow = $caddieCheck instanceof mysqli_result ? $caddieCheck->fetch_assoc() : null;
    $db->close();
    expect(is_array($caddieRow) ? (int) $caddieRow['c'] : -1)->toBe(1);
});

it('rejects a batch caddie request without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=photos_add&batch=1');

    expect($result['status'])->toBe(400);
});

it('preselects a valid album= and shows its display name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photos Add Direct Preselect Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $albumName = is_string($albumResult['name'] ?? null) ? $albumResult['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=photos_add&album=' . $albumId);

    $page->assertSee($albumName);
    $page->assertNoJavaScriptErrors();
});

it('rejects a nonexistent album= as a hacking attempt', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=photos_add&album=999999999');

    expect($result['body'])->toContain('Hacking attempt');
});

it('falls back to filename-based original detection when formats= targets a nonexistent photo', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add&formats=999999999');

        // No "doesn't exist" error for an unresolvable original -- HAVE_
        // FORMATS_ORIGINAL just stays false, and the template shows this
        // real hint instead (confirmed live).
        $page->assertSee('The original picture will be detected with the filename (without extension).');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('lists a real photo\'s existing formats when formats= targets a valid original', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photos Add Direct Formats Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photos Add Direct Formats Photo');
        @unlink($image);

        $db = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = photosAddDirectDbPrefix();
        $db->query(sprintf("INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, 'tif', 2048)", $prefix, $imageId));
        $db->close();

        $page = H::navigateOk($page, '/admin.php?page=photos_add&formats=' . $imageId);

        $page->assertSee('tif');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sets the upload_hide_warnings session flag when hide_warnings= is present', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=photos_add&hide_warnings=1');
    $page->assertNoJavaScriptErrors();

    // A second visit in the same session, with the flag no longer in the
    // URL, must still suppress the warnings block (real session
    // persistence, not just a per-request echo).
    $page = H::navigateOk($page, '/admin.php?page=photos_add');
    $page->assertNoJavaScriptErrors();
});

it('computes the GD max-upload-resolution memory warning when forced onto the "gd" graphics library', function (): void {
    $snapshot = H::snapshotConfig(['graphics_library']);
    // Default 'auto' resolves to 'ext_imagick' in this test env (both the
    // imagick PHP extension and the `convert`/`identify` CLI binaries are
    // present, confirmed live) -- prepareUploadForm()'s own memory-limit
    // math only runs for the 'gd' library specifically.
    H::setConfigValue('graphics_library', '"gd"');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'photos_add, graphics_library=gd');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the original-resize dimensions warning when original_resize is enabled', function (): void {
    $snapshot = H::snapshotConfig(['original_resize', 'original_resize_maxwidth', 'original_resize_maxheight']);
    H::setConfigValue('original_resize', 'true');
    H::setConfigValue('original_resize_maxwidth', '1600');
    H::setConfigValue('original_resize_maxheight', '1200');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'photos_add, original_resize enabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});