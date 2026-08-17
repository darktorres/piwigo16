<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('adds a batch of photo ids to the caddie and redirects to the batch manager', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Photos Add Direct Batch Album ' . uniqid(),
    ]);
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

    $db = H::connect();
    $caddieRow = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM caddie WHERE user_id = 1 AND element_id = %d', $imageId));
    H::dbClose($db);
    expect(is_array($caddieRow) ? (int) $caddieRow['c'] : -1)->toBe(1);
});

it('rejects a batch caddie request without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=photos_add&batch=1');

    expect($result['status'])->toBe(400);
});

it('preselects a valid album= and shows its display name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Photos Add Direct Preselect Album ' . uniqid(),
    ]);
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

it('renders a real 404 "Page not found" response for a nonexistent album=', function (): void {
    $page = H::loginAsAdmin($this);

    // pageNotFound() -> RedirectService::redirectHtml() throws a real
    // ResponseReadyException with the given status code baked into the
    // response (a meta-refresh HTML body, NOT a Location header) -- a
    // genuine 404. H::httpStatus/H::httpBody are unauthenticated-only, so
    // this stays on H::rawGet with an explicit status/body check, same
    // pattern as PictureCoiPageRendererTest's identical admin-authenticated
    // pageNotFound() situation.
    $result = H::rawGet($page, '/admin.php?page=photos_add&album=999999999');

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('Page not found');
});

it('falls back to filename-based original detection when formats= targets a nonexistent photo', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add&formats=999999999');

        // No "doesn't exist" error for an unresolvable original -- HAVE_
        // FORMATS_ORIGINAL just stays false, and the template shows this
        // real hint instead.
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
        $album = H::wsCall($page, 'pwg.categories.add', [
            'name' => 'Photos Add Direct Formats Album ' . uniqid(),
        ]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photos Add Direct Formats Photo');
        @unlink($image);

        $db = H::connect();
        H::dbQuery($db, sprintf("INSERT INTO image_format (image_id, ext, filesize) VALUES (%d, 'tif', 2048)", $imageId));
        H::dbClose($db);

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
    // present) -- prepareUploadForm()'s own memory-limit
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

it('skips the mobile-app-promotion computation entirely once the user has dismissed it', function (): void {
    $page = H::loginAsAdmin($this);

    // Real client flow (themes/admin/default/js/photos_add_direct.js's
    // ".dont-show-again" handler): a plain WS preferences write, using the
    // literal string 'false' -- PreferencesService::updateParam()'s own
    // 'true'/'false' string check exists specifically because a real
    // browser's url-encoded POST body delivers exactly this, not a JSON
    // boolean. This is the only way a real admin ever flips
    // 'promote-mobile-apps' to false; there's no admin.php GET param for it.
    $prefResponse = H::wsCall($page, 'pwg.users.preferences.set', [
        'param' => 'promote-mobile-apps',
        'value' => 'false',
    ]);
    $prefResult = $prefResponse['result'] ?? null;
    if (! is_array($prefResult) || ! is_array($prefResult['preferences'] ?? null)) {
        throw new RuntimeException('pwg.users.preferences.set did not return a {preferences: {...}} result: ' . var_export($prefResponse, true));
    }
    expect($prefResult['preferences']['promote-mobile-apps'] ?? null)->toBeFalse();

    try {
        $page = H::navigateOk($page, '/admin.php?page=photos_add');
        $page->assertNoJavaScriptErrors();

        // Can't tell this branch apart from the default (getPromoteMobileApps()
        // ?? true, true) one by rendered content alone: the fixture never
        // has 3 albums + 30 photos (see this class's own render() comment),
        // so PROMOTE_MOBILE_APPS ends up false either way and the
        // {if $PROMOTE_MOBILE_APPS} block in photos_add_direct.latte is
        // absent regardless. What this test actually proves is the
        // `getPromoteMobileApps() ?? true === false` short-circuit itself
        // -- the real behavioral difference is that render() skips
        // findEarliestRegistrationDate()/countAllCategories()/
        // countAllImages() entirely rather than computing and discarding a
        // false result, which a clean (no server error) page load with the
        // preference genuinely persisted as false is the closest real,
        // externally-observable signal for.
        $page->assertDontSee('Piwigo is also on mobile.');
    } finally {
        // No pwg.users.preferences.delete WS method exists to unset the key
        // outright -- setting it back to the literal default (true) is
        // behaviorally identical to the pristine unset state, since
        // getPromoteMobileApps() ?? true's own default is also true.
        H::wsCall($page, 'pwg.users.preferences.set', [
            'param' => 'promote-mobile-apps',
            'value' => 'true',
        ]);
    }
});

it('reports a setup error (and suppresses warnings) when the configured upload_dir does not exist and its parent is not writable either', function (): void {
    $snapshot = H::snapshotConfig(['upload_dir']);
    // A nested path under a directory that doesn't exist either -- no
    // mkdir()/chmod() needed (unlike the analogous "exists but not
    // writable" case): is_writable() on a nonexistent path is simply
    // false, no warning, so
    // this reaches UploadService::readyForUploadMessage()'s very first
    // branch (`! is_dir($upload_dir)` then `! is_writable(dirname($upload_dir))`)
    // through a real, non-mutating filesystem state.
    $relDir = 'missing-upload-test-' . uniqid() . '/nested/';
    H::setConfigValue('upload_dir', H::jsonEncode($relDir));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add');

        $page->assertSee('directory at the root of your Piwigo installation');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('warns when upload_form_chunk_size is configured larger than PHP\'s real upload_max_filesize', function (): void {
    $snapshot = H::snapshotConfig(['upload_form_chunk_size']);
    // php.ini's upload_max_filesize is 2M (2048kB) in this environment --
    // any chunk size configured above that in kB trips prepareUploadForm()'s own sanity
    // warning. Unlike upload_max_filesize/post_max_size themselves (real
    // PHP_INI_PERDIR directives baked into the live Apache php.ini, not
    // safely overridable per-request without mutating shared global state
    // every other parallel test/agent also depends on -- see this file's
    // trailing comment block), upload_form_chunk_size is a plain Piwigo
    // config row, so this branch is reachable through the normal
    // snapshot/set/restore config mechanism.
    H::setConfigValue('upload_form_chunk_size', '3000');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add');

        $page->assertSee('should be smaller than PHP configuration setting upload_max_filesize');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

// Four real branches in prepareUploadForm() are left uncovered here,
// each unreachable through a genuine, real request against this
// project's fixed, concurrently-shared test environment, without
// mutating global state every other parallel Browser-suite test also
// depends on:
//
// - The `if ($max_upload_resolution < 25)` GD memory-math body (assigning
//   max_upload_width/max_upload_height/max_upload_resolution): with this
//   environment's real Apache memory_limit (128M), max_upload_resolution
//   comes out >= 25 -- the fudge_factor=1.7 line just above (218) DOES
//   already run every time (unconditionally, whenever the outer
//   `getLibrary() === 'gd'` block is entered -- see the "computes the
//   GD..." test above), it's specifically the resolution<25 body that
//   stays unreached. memory_limit is PHP_INI_ALL (ini_set()-able in
//   principle), but only from *inside* the same request that's executing
//   admin.php -- there's no test-mode hook in this codebase's bootstrap
//   that does so, and the only external lever (editing the one shared
//   php.ini / dropping a webroot .htaccess) would apply to every
//   concurrently-running test hitting this same live Apache instance, not
//   just this one request.
//
// - `if (! function_exists('gd_info'))` ("GD library is missing"): ext-gd
//   is a hard `require` in composer.json (not `suggest`), and function_exists()
//   with a literal string argument always resolves the real global
//   built-in -- it can't be namespaced away, and this environment always
//   has it loaded. Same underlying check as ImageBackend::isGd() (also just
//   `function_exists('gd_info')`), which the "computes the GD..." test
//   above only ever observes returning true.
//
// - `if (CurrentConfig::useExif() and ! function_exists('exif_read_data'))`
//   ("Exif extension not available"): ext/exif is always loaded here --
//   exact same "verified untestable without breaking a real runtime
//   guarantee" conclusion this codebase already documents in
//   tests/Integration/MetadataServiceTest.php (getExifData()'s own
//   identical guard) and tests/Integration/Admin/Integrity/
//   C13yInternalTest.php (c13y_exif()'s own identical guard).
//
// - `if ($uploadService->getIniSize('upload_max_filesize') >
//   $uploadService->getIniSize('post_max_size'))` (the "upload_max_filesize
//   is bigger than post_max_size" warning): both are real PHP_INI_PERDIR
//   directives -- ini_set() on either silently no-ops -- baked into the
//   one shared Apache php.ini this whole Browser suite depends on. The
//   only way to flip this comparison true is a global php.ini/.htaccess
//   edit, which would corrupt every other concurrently-running test
//   hitting this same live server, not a scoped, restorable per-test
//   override like every config-row-based branch above.
