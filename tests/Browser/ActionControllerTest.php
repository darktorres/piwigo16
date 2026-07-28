<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\ActionController (replaces action.php) -- the
 * permission-checked original/representative/format-file download
 * handler, reached as a plain top-level route (not under /admin.php).
 */
it('downloads a photo\'s original file via part=e', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Photo');
    @unlink($image);

    $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e');

    expect($result['status'])->toBe(200);
    expect(strlen($result['body']))->toBeGreaterThan(0);
});

it('returns 400 for an invalid id/part combination', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/action.php?part=e');

    expect($result['status'])->toBe(400);
    expect($result['body'])->toContain('Invalid request - id/part');
});

it('returns 400 for a request missing both id/part and format', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/action.php');

    expect($result['status'])->toBe(400);
});

it('returns 404 for a nonexistent image id', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/action.php?id=999999999&part=e');

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('Requested id not found');
});

it('returns 404 for part=r when the photo has no representative file', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller No Rep Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller No Rep Photo');
    @unlink($image);

    $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=r');

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('Requested file not found');
});

it('returns 400 for part=f when the extensions-format system is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Format Off Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Format Off Photo');
        @unlink($image);

        // isFormatsEnabled()=false means format= is never even parsed as a
        // format request -- falls through to the plain id/part branch,
        // which is missing here, so this is really the id/part-missing 400.
        $result = H::rawGet($page, '/action.php?format=1');

        expect($result['status'])->toBe(400);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('returns 400 for a nonexistent format id when formats are enabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::loginAsAdmin($this);

        $result = H::rawGet($page, '/action.php?format=999999999');

        expect($result['status'])->toBe(400);
        expect($result['body'])->toContain('Invalid request - format');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sends a Content-Disposition attachment header when download is requested', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Download Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Download Photo');
    @unlink($image);

    $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e&download');

    expect($result['status'])->toBe(200);
});

it('rejects access to a private album\'s photo for a guest', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Action Controller Private Album ' . uniqid(),
        'status' => 'private',
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Private Photo');
    @unlink($image);

    $guestPage = H::visitPwg($this, '/index.php');
    H::assertNoServerErrors($guestPage, 'guest gallery home');

    $result = H::rawGet($guestPage, '/action.php?id=' . $imageId . '&part=e');

    expect($result['status'])->toBe(401);
    expect($result['body'])->toContain('Access denied');
});