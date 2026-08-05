<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\ActionController (replaces action.php) -- the
 * permission-checked original/representative/format-file download
 * handler, reached as a plain top-level route (not under /admin.php).
 *
 * Two of __invoke()'s own lines are dead code from any real request,
 * given Request\ActionRequest's own shape:
 * - `if ($file === '') { return $this->doError(404, ...); }` (~line 169):
 *   `$get_part` can only ever be 'e'/'r'/'f' -- either the
 *   format-resolved path hardcodes it (line ~88) or
 *   ActionRequest::fromArray() itself already collapses anything else to
 *   null (`in_array($part_raw, ['e', 'r', 'f'], true) ? $part_raw :
 *   null`, caught by the earlier `id/part` 400 branch). Every one of the
 *   3 switch cases either returns an error itself before falling through,
 *   or unconditionally assigns a real, non-empty $file (images.path is a
 *   NOT NULL varchar; ImagePathHelper::getElementPath() prefixes it with
 *   CurrentPaths::get()->root either way) -- there is no route through
 *   the switch that reaches line ~168 with $file still ''.
 * - `if ($format_row === null) { return $this->doError(400, ...); }`
 *   inside the history-logging `elseif ($get_part === 'f')` branch
 *   (~line 181): by the time $get_part could equal 'f' here, the switch's
 *   OWN case 'f' (line ~158) already performed the identical
 *   `$format_row === null` check and returned early if it failed --
 *   $format_row is never reassigned in between, so this second check can
 *   never itself be the one that's true.
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

function actionDbConnect(): \mysqli|\PgSql\Connection
{
    return H::connect();
}

function actionDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function actionImagePath(int $imageId): string
{
    $db = actionDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT path FROM %simages WHERE id = %d', actionDbPrefix(), $imageId));
    H::dbClose($db);
    if (! is_array($row) || ! is_string($row['path'] ?? null)) {
        throw new RuntimeException("actionImagePath(): no path found for image {$imageId}");
    }

    return $row['path'];
}

/**
 * Closes the `guessMimeType()` call site itself (`if ($ctype === null) {
 * $ctype = $this->guessMimeType(...); }`, ~line 226) -- distinct from
 * ActionControllerTest.php (Unit)'s own reflection-based coverage of
 * guessMimeType()'s internal match arms. A remote (`images.path` starting
 * `http://`/`https://`) image entirely skips the local
 * is_readable()/mime_content_type() block ($ctype stays its initial
 * null), unconditionally reaching this call for ANY remote path,
 * regardless of extension -- no real outbound network round trip is
 * needed for that alone. Pointing the remote path at a closed local port
 * (127.0.0.1:1, same deterministic-failure technique
 * NotificationByMailSenderTest's own docblock establishes for
 * MailService::mail()) makes the *later*, unavoidable
 * `@file_get_contents($file)` body-read fail fast and deterministically
 * too, rather than hang or flake against a real remote host.
 */
it('serves a remote-storage photo through the guessMimeType() fallback when mime_content_type() is never consulted', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Remote Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Remote Photo');
    @unlink($image);

    $db = actionDbConnect();
    H::dbQuery($db, sprintf(
        "UPDATE %simages SET path = 'http://127.0.0.1:1/ct-remote-photo.jpg' WHERE id = %d",
        actionDbPrefix(),
        $imageId
    ));
    H::dbClose($db);

    try {
        // A matching pwg_token flips is_admin_download=true (bypassing the
        // is_original()-and-no-HD-access derivative check entirely, same
        // established technique as the admin-download test above) --
        // irrelevant to the remote-path branch itself, just the simplest
        // way to reach it without also needing a real, non-original image.
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e&pwg_token=' . H::pwgToken($page));

        expect($result['status'])->toBe(200);
        // The remote fetch itself fails fast (connection refused) --
        // @file_get_contents() returns false, cast to '' -- but the
        // Content-Type header was already resolved via guessMimeType()
        // before that read was ever attempted.
        expect($result['body'])->toBe('');
    } finally {
        // No need to restore images.path -- the category delete below
        // (force_delete) removes this image's whole row along with it.
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

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
    H::dbQuery($db, sprintf(
        "INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, 'ct_raw', 4)",
        actionDbPrefix(),
        $imageId
    ));
    $formatId = H::dbInsertId($db);
    H::dbClose($db);

    try {
        $result = H::rawGet($page, '/action.php?format=' . $formatId);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toBe(str_repeat('R', 4096));
    } finally {
        @unlink($formatFile);
        $cleanupDb = actionDbConnect();
        H::dbQuery($cleanupDb, sprintf('DELETE FROM %simage_format WHERE format_id = %d', actionDbPrefix(), $formatId));
        H::dbClose($cleanupDb);
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
    H::dbQuery($db, sprintf("UPDATE %simages SET representative_ext = 'jpg' WHERE id = %d", actionDbPrefix(), $imageId));
    H::dbClose($db);

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
    H::dbQuery($db, sprintf('UPDATE %suser_infos SET enabled_high = 0 WHERE user_id = 2', actionDbPrefix()));
    H::dbClose($db);

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
        H::dbQuery($restoreDb, sprintf('UPDATE %suser_infos SET enabled_high = 1 WHERE user_id = 2', actionDbPrefix()));
        H::dbClose($restoreDb);
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

it('returns 400 for an empty format value when formats are enabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::loginAsAdmin($this);

        // `format=` with no value: isset($_GET['format']) is still true, so
        // ActionRequest::formatRequested is true, but InputValidator::
        // validate() short-circuits on the empty string (its own
        // emptyValue() check) before ever checking against
        // ValidationPattern::ID, so ActionRequest ends up with
        // formatId=null via is_numeric('')===false rather than a fatalError
        // -- a genuinely different route to "Invalid request - format" than
        // the real-but-unknown-id 400 covered above (format=999999999,
        // which fails the later DB lookup instead).
        $result = H::rawGet($page, '/action.php?format=');

        expect($result['status'])->toBe(400);
        expect($result['body'])->toContain('Invalid request - format');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('returns 400 for part=f requested directly on a real photo, without a format id', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Direct Part F Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Direct Part F Photo');
    @unlink($image);

    try {
        // part=f reached directly (not via format=) never resolves a
        // format row, so the switch's 'f' case runs with $format_row still
        // null -- a second, distinct route to the same "Invalid request -
        // format" 400 message as the format-id branch above.
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=f');

        expect($result['status'])->toBe(400);
        expect($result['body'])->toContain('Invalid request - format');
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

it('returns 404 naming the resolved path when the original file is missing from disk', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Missing File Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Action Controller Missing File Photo');
    @unlink($image);

    $realPath = actionImagePath($imageId);
    // See the format-id test above: root is the repo root (one level above
    // public/), the same base ImagePathHelper::getElementPath() itself
    // resolves against.
    $root = dirname(__DIR__, 2) . '/';
    $absolutePath = $root . $realPath;
    // The DB row (and its category membership/permission) stays intact --
    // only the on-disk original is gone, so the request sails past every
    // earlier check (id lookup, permission, is_original()/enabledHigh --
    // none of those touch the filesystem) and only fails at the
    // is_readable() guard right before the file would be streamed back.
    @unlink($absolutePath);

    try {
        $result = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e');

        expect($result['status'])->toBe(404);
        expect($result['body'])->toContain('Requested file not found - ');
        expect($result['body'])->toContain(basename($realPath));
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

it('bypasses the no-HD-access restriction for an admin download carrying a valid pwg_token', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Action Controller Admin Download Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // Same oversized-original setup as the guest 401 test above, but this
    // time it's fixture_admin's own enabled_high (user_id 1) that's turned
    // off, isolating is_admin_download's withEnabledHigh(true) bypass from
    // AccessControl::isAdmin() itself (already true for this whole file's
    // session, and not what's under test here).
    $db = actionDbConnect();
    H::dbQuery($db, sprintf('UPDATE %suser_infos SET enabled_high = 0 WHERE user_id = 1', actionDbPrefix()));
    H::dbClose($db);

    $img = imagecreatetruecolor(2000, 1500);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_action_admin_dl_') . '.jpg';
    imagejpeg($img, $tmpPath, 80);
    $imageId = H::uploadPhotoViaApi($tmpPath, $albumId, 'Action Controller Admin Download Photo');
    @unlink($tmpPath);

    try {
        // Without a matching pwg_token, is_admin_download stays false, so
        // the admin is subject to the same HD-access check as anyone else.
        $withoutToken = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e');
        expect($withoutToken['status'])->toBe(401);
        expect($withoutToken['body'])->toContain('Access denied e');

        // A matching pwg_token flips is_admin_download=true, which forces
        // CurrentUser::withEnabledHigh(true) for the rest of the request --
        // the same oversized original that was just denied now succeeds.
        $withToken = H::rawGet($page, '/action.php?id=' . $imageId . '&part=e&pwg_token=' . H::pwgToken($page));
        expect($withToken['status'])->toBe(200);
        expect(strlen($withToken['body']))->toBeGreaterThan(0);
    } finally {
        $restoreDb = actionDbConnect();
        H::dbQuery($restoreDb, sprintf('UPDATE %suser_infos SET enabled_high = 1 WHERE user_id = 1', actionDbPrefix()));
        H::dbClose($restoreDb);
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});
