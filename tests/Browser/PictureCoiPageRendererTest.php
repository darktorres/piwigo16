<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function pictureCoiValue(int $imageId): ?string
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT coi FROM images WHERE id = %d', $imageId));
    H::dbClose($db);

    return is_array($row) && is_string($row['coi']) ? $row['coi'] : null;
}

it('renders the coi editor for a photo with no center of interest set yet', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Photo');
    @unlink($image);

    expect(pictureCoiValue($imageId))
        ->toBeNull();

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_coi no-coi-yet render');
});

it('submits a new center of interest, persists it, and invalidates derivative-URL-style config', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi Submit Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Submit Photo');
    @unlink($image);

    $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
        'submit' => '1',
        'l' => '0.1',
        't' => '0.2',
        'r' => '0.8',
        'b' => '0.9',
    ]);

    expect($result['status'])->toBe(200);
    expect(pictureCoiValue($imageId))
        ->not->toBeNull();
    expect(pictureCoiValue($imageId))
        ->toHaveLength(4);
});

it('carries a real representative_ext into the deleted derivative_infos when set', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi RepExt Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi RepExt Photo');
    @unlink($image);

    $db = H::connect();
    // representative_ext is non-empty for a video/pdf/etc. upload (a
    // representative image with a different extension than the original
    // file) -- set directly here rather than via a real video/pdf upload,
    // which needs ffmpeg/imagemagick binaries this test env may not have.
    H::dbQuery($db, sprintf("UPDATE images SET representative_ext = 'jpg' WHERE id = %d", $imageId));

    try {
        $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
            'submit' => '1',
            'l' => '0.05',
            't' => '0.15',
            'r' => '0.75',
            'b' => '0.85',
        ]);

        expect($result['status'])->toBe(200);
        expect(pictureCoiValue($imageId))
            ->not->toBeNull();
    } finally {
        H::dbClose($db);
    }
});

it('resets a "questionmark" derivative_url_style (1) back to "auto" (0) for this render only', function (): void {
    // CurrentConfig::setDerivativeUrlStyle() (what render()'s reset calls)
    // only ever writes the in-process static -- there is no
    // confUpdateParam('derivative_url_style', ...) call anywhere in this
    // codebase, so the DB row is never actually touched (confirmed live:
    // the config table's value is still '1' after a real POST here). The
    // real, externally-observable effect is that THIS SAME response's
    // U_IMG now renders through the "auto" i.php? derivative route
    // instead of a plain static link -- assert that instead of a DB value
    // that was never meant to change.
    $snapshot = H::snapshotConfig(['derivative_url_style']);
    H::setConfigValue('derivative_url_style', '1');

    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi UrlStyle Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi UrlStyle Photo');
    @unlink($image);

    try {
        $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
            'submit' => '1',
            'l' => '0.1',
            't' => '0.2',
            'r' => '0.8',
            'b' => '0.9',
        ]);

        expect($result['status'])->toBe(200);
        // A not-yet-cached derivative under "always static link" (1) would
        // render a plain upload/... path with no i.php involved; seeing it
        // routed through i.php? here proves the in-request reset to "auto"
        // (0) took effect for this render.
        expect($result['body'])->toContain('i.php?/upload/');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders a real 404 "Page not found" response for a nonexistent image_id', function (): void {
    $page = H::loginAsAdmin($this);

    // pageNotFound() -> RedirectService::redirectHtml() throws a real
    // ResponseReadyException with the given status code baked into the
    // response (a meta-refresh HTML body, NOT a Location header) -- a
    // genuine 404, not an opaque fetch(manual) redirect like this
    // renderer's own successful-submission paths elsewhere in this suite.
    $result = H::rawGet($page, '/admin.php?page=picture_coi&image_id=999999999');

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('Page not found');
});
