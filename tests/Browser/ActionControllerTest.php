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

    try {
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e');

        expect($result['status'])->toBe(200);
        expect(strlen($result['body']))->toBeGreaterThan(0);
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
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

    try {
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=r');

        expect($result['status'])->toBe(404);
        expect($result['body'])->toContain('Requested file not found');
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

it('returns 400 for part=f when the extensions-format system is disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'false');

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

    try {
        // isFormatsEnabled()=false means format= is never even parsed as a
        // format request -- falls through to the plain id/part branch,
        // which is missing here, so this is really the id/part-missing 400.
        $result = H::rawGet($page, '/action.php?format=1');

        expect($result['status'])->toBe(400);
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
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

    try {
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e&download');

        expect($result['status'])->toBe(200);
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

function actionDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function actionDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function actionImagePath(int $imageId): string
{
    $db = actionDbConnect();
    $result = $db->query(sprintf('SELECT path FROM %simages WHERE id = %d', actionDbPrefix(), $imageId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();
    if (! is_array($row) || ! is_string($row['path'] ?? null)) {
        throw new RuntimeException("actionImagePath(): no path found for image {$imageId}");
    }

    return $row['path'];
}

it('serves a photo through a real registered format id, logging a "high" visit', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Format Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Format Photo');
    @unlink($image);

    $realPath = actionImagePath($imageId);
    // ActionController resolves paths against CurrentPaths::get()->root,
    // which public/action.php builds as Paths::fromRoot(dirname(__DIR__))
    // -- the repo root, one level *above* public/, not public/ itself
    // (confirmed live: without this, action.php reported "Requested file
    // not found" at the repo-root path even though this test had written
    // the file under public/).
    $root = dirname(__DIR__, 2) . '/';
    $realDir = dirname($root . $realPath);
    $baseName = pathinfo($realPath, PATHINFO_FILENAME);
    $formatDir = $realDir . '/pwg_format';
    if (! is_dir($formatDir)) {
        mkdir($formatDir, 0777, true);
    }
    $formatFile = $formatDir . '/' . $baseName . '.ct_raw';
    file_put_contents($formatFile, str_repeat('R', 4096));

    $db = actionDbConnect();
    $db->query(sprintf(
        "INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, 'ct_raw', 4)",
        actionDbPrefix(),
        $imageId
    ));
    $formatId = (int) $db->insert_id;
    $db->close();

    try {
        $result = H::rawGet($page, '/action.php?format=' . $formatId);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toBe(str_repeat('R', 4096));
    } finally {
        @unlink($formatFile);
        $cleanupDb = actionDbConnect();
        $cleanupDb->query(sprintf('DELETE FROM %simage_format WHERE format_id = %d', actionDbPrefix(), $formatId));
        $cleanupDb->close();
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
        H::restoreConfig($snapshot);
    }
});

it('serves a photo\'s representative file via part=r when one is registered', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Rep Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Rep Photo');
    @unlink($image);

    $realPath = actionImagePath($imageId);
    // See the format-id test above: root is the repo root (one level above
    // public/), not public/ itself.
    $root = dirname(__DIR__, 2) . '/';
    $realDir = dirname($root . $realPath);
    $baseName = pathinfo($realPath, PATHINFO_FILENAME);
    $repDir = $realDir . '/pwg_representative';
    if (! is_dir($repDir)) {
        mkdir($repDir, 0777, true);
    }
    $repFile = $repDir . '/' . $baseName . '.jpg';
    file_put_contents($repFile, str_repeat('P', 2048));

    $db = actionDbConnect();
    $db->query(sprintf("UPDATE %simages SET representative_ext = 'jpg' WHERE id = %d", actionDbPrefix(), $imageId));
    $db->close();

    try {
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=r');

        expect($result['status'])->toBe(200);
        expect($result['body'])->toBe(str_repeat('P', 2048));
    } finally {
        @unlink($repFile);
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

/**
 * @param  list<string>  $extraHeaders
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function actionCurlGet(string $path, array $extraHeaders = []): array
{
    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), ...$extraHeaders]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (! is_string($raw)) {
        throw new RuntimeException('curl_exec failed');
    }
    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $headers = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

it('sends 304 Not Modified for part=e when If-Modified-Since matches the file\'s own mtime', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller 304 Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller 304 Photo');
    @unlink($image);

    try {
        $first = actionCurlGet('/action.php?id=' . $imageId . '&part=e');
        expect($first['status'])->toBe(200);
        $lastModified = $first['headers']['last-modified'] ?? null;
        expect($lastModified)->not->toBeNull();

        $second = actionCurlGet('/action.php?id=' . $imageId . '&part=e', ['If-Modified-Since: ' . $lastModified]);
        expect($second['status'])->toBe(304);
        expect($second['body'])->toBe('');
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

it('denies HD download of an oversized original to a guest with no HD access', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Oversized Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // Bigger than the XXLARGE (1656x1242) box in both dimensions -- forces
    // DerivativeImage::same_as_source() to be false for a non-HD user,
    // exercising the 401 "Access denied e" branch. `piwigo_user_infos.
    // enabled_high` defaults to 1 in the schema (and the fixture's guest
    // row, user_id 2, is no exception -- confirmed live) -- a guest only
    // has enabledHigh=false when a webmaster has explicitly disabled it
    // for that account, so this test flips it for real rather than
    // assuming a default that doesn't hold.
    $db = actionDbConnect();
    $db->query(sprintf('UPDATE %suser_infos SET enabled_high = 0 WHERE user_id = 2', actionDbPrefix()));
    $db->close();

    $img = imagecreatetruecolor(2000, 1500);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_action_oversized_') . '.jpg';
    imagejpeg($img, $tmpPath, 80);
    $imageId = H::uploadPhotoViaApi($tmpPath, $albumId, 'Action Controller Oversized Photo');
    @unlink($tmpPath);

    try {
        $guestPage = H::visitPwg($this, '/index.php');
        H::assertNoServerErrors($guestPage, 'guest gallery home');

        $result = H::rawGet($guestPage, '/action.php?id=' . $imageId . '&part=e');

        expect($result['status'])->toBe(401);
        expect($result['body'])->toContain('Access denied e');
    } finally {
        $restoreDb = actionDbConnect();
        $restoreDb->query(sprintf('UPDATE %suser_infos SET enabled_high = 1 WHERE user_id = 2', actionDbPrefix()));
        $restoreDb->close();
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
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

    try {
        $guestPage = H::visitPwg($this, '/index.php');
        H::assertNoServerErrors($guestPage, 'guest gallery home');

        $result = H::rawGet($guestPage, '/action.php?id=' . $imageId . '&part=e');

        expect($result['status'])->toBe(401);
        expect($result['body'])->toContain('Access denied');
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});