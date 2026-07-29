<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\ImageDerivativeController (i.php) -- the derivative
 * image server. tests/Browser/DerivativePermissionTest.php already covers
 * the SEC-33 permission-check fast path (cached and not-yet-cached
 * derivatives alike) via ImageDerivativeControllerTest.php's own sibling
 * fixture-image tests; this file covers the remaining real-HTTP branches
 * this class's own __invoke()/sendDerivative() have: the conditional-GET
 * 304 Not Modified response, the ajaxload JSON payload (used by the JS
 * lightbox instead of a raw image response), and the "0 changes needed"
 * 301 redirect to the true original via action.php.
 */

function idcDerivativePath(string $imagePath, string $suffix): string
{
    $withoutExt = preg_replace('/\.\w+$/', '', $imagePath);
    if (! is_string($withoutExt)) {
        throw new RuntimeException("idcDerivativePath(): preg_replace() failed for '{$imagePath}'");
    }

    return $withoutExt . '-' . $suffix . '.jpg';
}

function idcCreateTestPhoto(object $test, string $albumName): int
{
    $page = H::loginAsAdmin($test);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => $albumName . ' ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, $albumName . ' Photo');
    @unlink($image);

    return $imageId;
}

/**
 * @param  list<string>  $extraHeaders
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function idcGet(string $path, array $extraHeaders = []): array
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

/**
 * Uploads a freshly-generated, custom-dimensioned/-colored test photo via
 * the WS API (same login+album+upload+emptyLounge flow as
 * idcCreateTestPhoto() above, factored out for scenarios that need
 * specific pixel dimensions H::makeTestImage()'s fixed 200x150 canvas
 * can't provide -- candidate-derivative reuse math and the rotation
 * dimension-swap test both depend on exact source dimensions/aspect).
 *
 * @param  array{int<0, 255>, int<0, 255>, int<0, 255>}  $fillRgb
 */
function idcUploadSizedPhoto(object $test, string $albumName, int $width, int $height, array $fillRgb): int
{
    assert($width >= 1 && $height >= 1);
    $page = H::loginAsAdmin($test);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => $albumName . ' ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $img = imagecreatetruecolor($width, $height);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $color = imagecolorallocate($img, $fillRgb[0], $fillRgb[1], $fillRgb[2]);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefill($img, 0, 0, $color);
    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_idc_sized_');
    if ($tmpPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    $path = $tmpPath . '.jpg';
    imagejpeg($img, $path, 90);

    $imageId = H::uploadPhotoViaApi($path, $albumId, $albumName . ' Photo');
    @unlink($path);

    return $imageId;
}

/**
 * Writes piwigo_images.rotation directly -- ImageDerivativeController's
 * __invoke() reads this column (via ImageRepository::findByPath()) to set
 * $this->rotationAngle *without* any live EXIF re-read whenever it's
 * already non-null (PwgImage::get_rotation_angle_from_code(): 0=0deg,
 * 1=90deg, 2=180deg, 3=270deg) -- writing it directly, before the first
 * derivative request for the photo, exercises that "already resolved"
 * branch deterministically, without depending on a GD-generated JPEG
 * happening to carry a real EXIF Orientation tag (it doesn't).
 */
function idcSetImageRotationCode(int $imageId, int $rotationCode): void
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf('UPDATE %simages SET rotation = %d WHERE id = %d', $prefix, $rotationCode, $imageId));
    $db->close();
}

/**
 * The on-disk path a derivative of $imagePath (with the given suffix, e.g.
 * 'sm'/'xs') is cached at -- ImageDerivativeController resolves derivative
 * paths against CurrentPaths::get()->root (the repo root, one level above
 * public/ -- same root idcCreateTestPhoto()'s own orphan-file test above
 * uses) joined with CurrentConfig::derivativeDir() ('_data/i/').
 */
function idcDerivativeDiskPath(string $imagePath, string $suffix): string
{
    return dirname(__DIR__, 2) . '/_data/i/' . idcDerivativePath($imagePath, $suffix);
}

/** @return array{red: int, green: int, blue: int} */
function idcPixel(\GdImage $image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);
    if ($rgb === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $colors = imagecolorsforindex($image, $rgb);

    return ['red' => $colors['red'], 'green' => $colors['green'], 'blue' => $colors['blue']];
}

/**
 * @param  array<string, mixed>  $fields
 * @param  non-empty-string  $cookieJar
 * @return array{status: int, body: string}
 */
function idcAdminCurl(string $url, array $fields, string $cookieJar): array
{
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

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/**
 * Logs in as the fixture admin over $cookieJar and returns a fresh pwg_token.
 *
 * @param  non-empty-string  $cookieJar
 */
function idcAdminPwgToken(string $cookieJar): string
{
    $baseUrl = H::baseUrl();
    idcAdminCurl($baseUrl . '/identification.php', [], $cookieJar);
    idcAdminCurl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ], $cookieJar);

    $statusResult = idcAdminCurl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus'], $cookieJar);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    if ($pwgToken === '') {
        throw new RuntimeException('idcAdminPwgToken(): pwg.session.getStatus did not return a pwg_token');
    }

    return $pwgToken;
}

/**
 * Saves the watermark config tab with $fields (merged over the
 * pwg_token/submit/w[file] baseline), uploading $watermarkPngPath as the
 * new watermark image -- factors out the hand-rolled upload+save+
 * assert-saved dance the existing "composites an opaque watermark" test
 * below already does inline, since 2 more tests need the same flow with
 * different w[...] values.
 *
 * @param  non-empty-string  $cookieJar
 * @param  array<string, string>  $fields
 * @return string the on-disk path of the just-uploaded watermark file (for test cleanup)
 */
function idcSaveWatermarkConfig(string $cookieJar, string $pwgToken, array $fields, string $watermarkPngPath, string $uploadedName): string
{
    $baseUrl = H::baseUrl();
    $result = idcAdminCurl($baseUrl . '/admin.php?page=configuration&section=watermark', [
        'pwg_token' => $pwgToken,
        'submit' => '1',
        'w[file]' => '',
        ...$fields,
        'watermarkImage' => new CURLFile($watermarkPngPath, 'image/png', $uploadedName),
    ], $cookieJar);
    if (! str_contains($result['body'], 'Your configuration settings are saved')) {
        throw new RuntimeException('idcSaveWatermarkConfig(): watermark config save failed: ' . $result['body']);
    }

    $derivatives = H::configValue('derivatives');
    if (! is_string($derivatives) || preg_match('/s:4:"file";s:\d+:"([^"]*)"/', $derivatives, $matches) !== 1) {
        throw new RuntimeException('idcSaveWatermarkConfig(): could not find the uploaded watermark file entry');
    }

    return dirname(__DIR__, 2) . '/' . ltrim($matches[1], '/');
}

it("sends 304 Not Modified when If-Modified-Since matches the cached derivative's own mtime", function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative 304 Album');
    $path = 'i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th');

    $first = idcGet($path);
    expect($first['status'])->toBe(200);
    $lastModified = $first['headers']['last-modified'] ?? null;
    expect($lastModified)->not->toBeNull();

    $second = idcGet($path, ['If-Modified-Since: ' . $lastModified]);
    expect($second['status'])->toBe(304);
    expect($second['body'])->toBe('');
});

it('returns a JSON url payload for an ajaxload request instead of the raw image bytes', function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative Ajax Album');
    $path = 'i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th') . '&ajaxload=true';

    $result = idcGet($path);
    expect($result['status'])->toBe(200);
    $decoded = json_decode($result['body'], true);
    expect($decoded)->toBeArray();
    expect(is_array($decoded) ? ($decoded['url'] ?? null) : null)->toBeString();
});

it('redirects to the original via action.php when the requested size needs no real change', function (): void {
    // H::makeTestImage()'s own canvas is 200x150 -- 'xl' (extra large,
    // default max far above that) makes SizingParams::compute() produce
    // zero crop/scale changes, so the "0 changes" redirect-to-source
    // branch fires instead of a real resize (matches
    // DerivativePermissionTest.php's own comment: "a size at or above
    // 200x150 ... redirect[s] to the true original via action.php instead
    // of generating").
    $imageId = idcCreateTestPhoto($this, 'Derivative NoChange Album');
    $path = 'i.php?/' . idcDerivativePath(H::imagePath($imageId), 'xl');

    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    expect($status)->toBe(301);
    expect($location)->toContain('action.php');
});

it('rejects a malformed derivative request with no type/extension separator', function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative Malformed Album');
    $imagePath = H::imagePath($imageId);
    // No '-{type}' suffix before the extension at all -- parseRequest()'s
    // own `strrpos($req, '-')` guard fails closed with a 400, distinct
    // from the "unknown parsing type" case (a real '-xx' token that just
    // doesn't match any known derivative type).
    $path = 'i.php?/' . $imagePath;

    expect(H::httpStatus($path))->toBe(400);
});

it('ierrors 404 "Source not found" when the underlying file does not exist at all', function (): void {
    $path = 'i.php?/upload/ct_missing_' . uniqid() . '-th.jpg';

    expect(H::httpStatus($path))->toBe(404);
});

it('ierrors 404 "Db file path not found" for a real file never registered as an image', function (): void {
    // A physically-real file (so is_file() succeeds and this isn't just
    // re-testing the "source not found" case above) under upload/, but
    // with no matching piwigo_images.path row -- ImageRepository::
    // findByPath() returns null and this is the *other* 404 branch,
    // distinct from a missing file. Placed inside a directory an earlier
    // real API upload just created, so the web server user (Apache runs
    // as www-data, not this shell's own user) can already read/traverse
    // it without any extra chmod dance.
    $imageId = idcCreateTestPhoto($this, 'Derivative Orphan Setup Album');
    $realImagePath = H::imagePath($imageId);
    // ImageDerivativeController resolves paths against
    // CurrentPaths::get()->root, which public/i.php builds as
    // Paths::fromRoot(dirname(__DIR__)) -- the repo root, one level
    // *above* public/, not public/ itself (same fix as
    // ActionControllerTest.php's format/representative tests).
    $uploadDir = dirname(__DIR__, 2) . '/' . dirname($realImagePath);
    $orphanName = 'ct_orphan_' . uniqid() . '.jpg';
    $orphanDiskPath = $uploadDir . '/' . $orphanName;

    $orphanSource = H::makeTestImage('CT Orphan');
    copy($orphanSource, $orphanDiskPath);
    @unlink($orphanSource);
    @chmod($orphanDiskPath, 0644);

    try {
        $path = 'i.php?/' . dirname($realImagePath) . '/' . str_replace('.jpg', '-th.jpg', $orphanName);

        expect(H::httpStatus($path))->toBe(404);
    } finally {
        @unlink($orphanDiskPath);
    }
});

it('serves a theme asset through the derivative pipeline and redirects to the raw source (no image_id) when no resize is needed', function (): void {
    // A real static theme file (public/themes is a symlink to ../themes),
    // matched by the `str_contains($this->srcLocation, 'themes/')` branch
    // -- entirely skips the DB image lookup/permission check (rotation
    // angle forced to 0, $image_id stays null). light-default.jpg is
    // 1000x565 -- well within the xxlarge (1656x1242) box, so classic
    // sizing computes 0 change and this hits the *other* "no change"
    // redirect target: the raw $this->srcUrl link, not action.php?part=e
    // (that branch is $image_id!==null only, already covered by the
    // existing 'xl' test above for a real DB-backed photo).
    //
    // Deliberately xxlarge ('xx'), not 4xlarge ('4x') despite 4xlarge's
    // bigger box also fitting: 4xlarge is disabled by default (the
    // fixture's own `disabled_derivatives` config row), and
    // ImageStdParams::load_from_db() self-heals disabled_derivatives back
    // to its default set (3xlarge/4xlarge) the moment it reads back empty
    // -- confirmed live, clearing the config row doesn't stick past the
    // next request. xxlarge needs no config change at all.
    $path = 'i.php?/themes/standard_pages/skins/light-default-xx.jpg';

    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    expect($status)->toBe(301);
    expect($location)->not->toContain('action.php');
    expect($location)->toContain('light-default.jpg');
});

it('sends a no-store, short-lived Expires header for a cache-busted (b=) request', function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative CacheBust Album');
    $path = 'i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th') . '&b=' . uniqid();

    $result = idcGet($path);

    expect($result['status'])->toBe(200);
    expect($result['headers']['cache-control'] ?? null)->toBe('no-store, max-age=100');
    expect($result['headers']['expires'] ?? null)->not->toBeNull();
});

it('rejects a custom-size request with no size token at all', function (): void {
    // '-cu' (derivativeToUrl(CUSTOM) === 'cu') with absolutely nothing
    // after it -- parseCustomParams() gets a genuinely empty token array.
    $path = 'i.php?/ct_custom_empty_' . uniqid() . '-cu.jpg';

    expect(H::httpStatus($path))->toBe(400);
});

it('rejects a 3-part custom-size request missing its crop/min-size tokens', function (): void {
    // A single plain WxH token (no leading 's'/'e') demands 2 more tokens
    // (crop fraction, min-size) that were never sent.
    $path = 'i.php?/ct_custom_short_' . uniqid() . '-cu_150x100.jpg';

    expect(H::httpStatus($path))->toBe(400);
});

it('rejects a custom-size request whose ideal size is below the 20px floor', function (): void {
    $path = 'i.php?/ct_custom_tiny_' . uniqid() . '-cu_s10.jpg';

    expect(H::httpStatus($path))->toBe(400);
});

it('rejects a custom-size request whose derived crop fraction falls outside [0, 1]', function (): void {
    // charToFraction() is (ord(char) - ord('a')) / 25 -- any digit char
    // (ord below 'a') produces a negative fraction, out of the valid
    // [0, 1] crop range this 3-token form allows arbitrary user input for.
    $path = 'i.php?/ct_custom_badcrop_' . uniqid() . '-cu_150x100_5_80x60.jpg';

    expect(H::httpStatus($path))->toBe(400);
});

it('composites an opaque watermark onto a freshly-generated derivative', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_idc_watermark_');
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

    // A small, fully-opaque pure-red PNG -- composited at xpos=0/ypos=0
    // (top-left) with opacity=100 and a 1x1 min_size threshold so it
    // applies to virtually any derivative type, making a corner pixel of
    // the *generated* derivative unambiguously distinguishable from
    // H::makeTestImage()'s own solid blue-ish (90, 130, 200) fill.
    $watermarkPath = tempnam(sys_get_temp_dir(), 'pwg_idc_wm_') . '.png';
    $wmImg = imagecreatetruecolor(10, 10);
    if ($wmImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $red = imagecolorallocate($wmImg, 255, 0, 0);
    if ($red === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefill($wmImg, 0, 0, $red);
    imagepng($wmImg, $watermarkPath);

    $watermarkUrl = $baseUrl . '/admin.php?page=configuration&section=watermark';
    $uploadedWatermarkPath = null;

    try {
        $uploadResult = $curl($watermarkUrl, [
            'pwg_token' => $pwgToken,
            'submit' => '1',
            'w[file]' => '',
            'w[position]' => 'topleft',
            'w[xpos]' => '0',
            'w[ypos]' => '0',
            'w[xrepeat]' => '0',
            'w[yrepeat]' => '0',
            'w[opacity]' => '100',
            'w[minw]' => '1',
            'w[minh]' => '1',
            'watermarkImage' => new CURLFile($watermarkPath, 'image/png', 'ct-idc-watermark.png'),
        ]);
        expect($uploadResult['status'])->toBe(200);
        expect($uploadResult['body'])->toContain('Your configuration settings are saved');

        $derivatives = H::configValue('derivatives');
        expect($derivatives)->not->toBeNull();
        assert(is_string($derivatives));
        if (preg_match('/s:4:"file";s:\d+:"([^"]*)"/', $derivatives, $matches) !== 1) {
            throw new RuntimeException('Could not find the uploaded watermark file entry');
        }
        // The watermark compose path reads this via
        // `$this->paths->root . $wm->file` (ImageDerivativeController.php)
        // -- root is the repo root, one level *above* public/, not public/
        // itself (public/ has no `local/` symlink the way it does for
        // `themes/`, so watermarks are never web-servable directly).
        $uploadedWatermarkPath = dirname(__DIR__, 2) . '/' . ltrim($matches[1], '/');

        $imageId = idcCreateTestPhoto($this, 'Derivative Watermark Album');
        $path = 'i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th');

        $result = idcGet($path);
        expect($result['status'])->toBe(200);

        $decodedImage = imagecreatefromstring($result['body']);
        if ($decodedImage === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }

        // Top-left corner: inside the fully-opaque red watermark.
        $cornerRgb = imagecolorat($decodedImage, 2, 2);
        if ($cornerRgb === false) {
            throw new RuntimeException('imagecolorat failed');
        }
        $cornerColors = imagecolorsforindex($decodedImage, $cornerRgb);
        expect($cornerColors['red'])->toBeGreaterThan(180);
        expect($cornerColors['green'])->toBeLessThan(80);
        expect($cornerColors['blue'])->toBeLessThan(80);

        // Bottom-right area of the 144x108 thumb: far from the 10x10
        // top-left watermark, still the original blue-ish fill.
        $farRgb = imagecolorat($decodedImage, 130, 95);
        if ($farRgb === false) {
            throw new RuntimeException('imagecolorat failed');
        }
        $farColors = imagecolorsforindex($decodedImage, $farRgb);
        expect($farColors['blue'])->toBeGreaterThan(140);
        expect($farColors['red'])->toBeLessThan(150);
    } finally {
        @unlink($cookieJar);
        @unlink($watermarkPath);
        if ($uploadedWatermarkPath !== null) {
            @unlink($uploadedWatermarkPath);
        }
        H::restoreConfig($snapshot);
    }
});

it('applies the stored rotation angle before crop/scale, swapping width/height for a 90-degree rotation', function (): void {
    // A landscape (100x50) source with a horizontal color split (red top
    // half, blue bottom half). ImageDerivativeController's own "// rotate"
    // block (get_rotation_angle_from_code(1) => 90 degrees) runs *before*
    // $o_size/$d_size are read from $image->get_width()/get_height(), so
    // a real 90-degree rotation both swaps the derivative's own output
    // dimensions and rotates which axis the color split runs along -- a
    // direction-agnostic way to prove real rotation happened (GD's
    // imagerotate() is documented counter-clockwise; other backends may
    // differ) without depending on which way the active image backend
    // actually rotates.
    $img = imagecreatetruecolor(100, 50);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $red = imagecolorallocate($img, 255, 0, 0);
    $blue = imagecolorallocate($img, 0, 0, 255);
    if ($red === false || $blue === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($img, 0, 0, 99, 24, $red);
    imagefilledrectangle($img, 0, 25, 99, 49, $blue);
    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_idc_rotate_');
    if ($tmpPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    $srcPath = $tmpPath . '.jpg';
    imagejpeg($img, $srcPath, 95);

    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Derivative Rotation Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $imageId = H::uploadPhotoViaApi($srcPath, (int) $albumResult['id'], 'Derivative Rotation Photo');
    @unlink($srcPath);

    // Set *before* the first derivative request for this photo: a fresh
    // upload's own images.rotation starts NULL, in which case __invoke()
    // would compute rotationAngle from a live EXIF re-read instead
    // (PwgImage::get_rotation_angle()) -- writing the code directly
    // exercises the "already resolved" branch
    // (get_rotation_angle_from_code($row->rotation)) deterministically.
    idcSetImageRotationCode($imageId, 1);

    $imagePath = H::imagePath($imageId);
    // 'th' (144x144 box): both 100x50 and its 90-degree-rotated 50x100 fit
    // entirely inside the box, so rotate() is the *only* real change
    // ($changes stays 1 from the "// rotate" block alone, no crop/scale)
    // -- isolates the rotation step from any crop/scale confound.
    $path = 'i.php?/' . idcDerivativePath($imagePath, 'th');

    $result = idcGet($path);
    expect($result['status'])->toBe(200);

    $decoded = imagecreatefromstring($result['body']);
    if ($decoded === false) {
        throw new RuntimeException('Failed to decode the derivative response body as an image');
    }
    expect(imagesx($decoded))->toBe(50);
    expect(imagesy($decoded))->toBe(100);

    // Color varied along Y before rotation (red top, blue bottom, constant
    // across the full width); after a +/-90-degree rotation it varies
    // along X instead and is constant along Y -- checked near both ends of
    // the now-100px-tall axis to prove an actual rotation happened, not
    // just a resize.
    $redSideNear = idcPixel($decoded, 10, 10);
    $redSideFar = idcPixel($decoded, 10, 90);
    $blueSideNear = idcPixel($decoded, 40, 10);
    $blueSideFar = idcPixel($decoded, 40, 90);

    expect($redSideNear['red'])->toBeGreaterThan(180);
    expect($redSideFar['red'])->toBeGreaterThan(180);
    expect($blueSideNear['blue'])->toBeGreaterThan(180);
    expect($blueSideFar['blue'])->toBeGreaterThan(180);
});

it('scales an oversized watermark down to fit the destination before compositing', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_idc_wmscale_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }
    $watermarkPngPath = null;
    $uploadedWatermarkPath = null;

    try {
        $pwgToken = idcAdminPwgToken($cookieJar);

        // 300x60 -- wider than 'xxsmall' (2small)'s own 240x180
        // destination box computed below, so ImageDerivativeController's
        // own `$d_size[0] < $wm_size[0]` guard is true and it must scale
        // the watermark down via SizingParams::classic($d_size[0],
        // $d_size[1]) before compositing, rather than compositing it at
        // its native size.
        $tmpWmPath = tempnam(sys_get_temp_dir(), 'pwg_idc_wmscale_img_');
        if ($tmpWmPath === false) {
            throw new RuntimeException('tempnam failed');
        }
        $watermarkPngPath = $tmpWmPath . '.png';
        $wmImg = imagecreatetruecolor(300, 60);
        if ($wmImg === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $green = imagecolorallocate($wmImg, 0, 255, 0);
        if ($green === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($wmImg, 0, 0, $green);
        imagepng($wmImg, $watermarkPngPath);

        $uploadedWatermarkPath = idcSaveWatermarkConfig($cookieJar, $pwgToken, [
            'w[position]' => 'topleft',
            'w[xpos]' => '0',
            'w[ypos]' => '0',
            'w[xrepeat]' => '0',
            'w[yrepeat]' => '0',
            'w[opacity]' => '100',
            'w[minw]' => '1',
            'w[minh]' => '1',
        ], $watermarkPngPath, 'ct-idc-wmscale.png');

        // 1200x900 (4:3) -- comfortably bigger than xxsmall's 240x240 box
        // in both dimensions, so classic scaling produces a real, exact
        // 240x180 derivative (4:3 aspect preserved) to composite onto.
        $imageId = idcUploadSizedPhoto($this, 'Derivative Watermark Scale Album', 1200, 900, [0, 0, 255]);
        $imagePath = H::imagePath($imageId);
        $path = 'i.php?/' . idcDerivativePath($imagePath, '2s');

        $result = idcGet($path);
        expect($result['status'])->toBe(200);

        $decoded = imagecreatefromstring($result['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))->toBe(240);
        expect(imagesy($decoded))->toBe(180);

        // The scaled watermark (SizingParams::classic(240,180) applied to
        // the 300x60 source -- width is the binding ratio, landing at
        // exactly 240x48) covers only the top strip; well below it the
        // background must be untouched.
        $insideScaledWatermark = idcPixel($decoded, 120, 24);
        $belowScaledWatermark = idcPixel($decoded, 120, 100);

        expect($insideScaledWatermark['green'])->toBeGreaterThan(180);
        expect($insideScaledWatermark['blue'])->toBeLessThan(80);
        expect($belowScaledWatermark['blue'])->toBeGreaterThan(180);
        expect($belowScaledWatermark['green'])->toBeLessThan(80);
    } finally {
        @unlink($cookieJar);
        if ($watermarkPngPath !== null) {
            @unlink($watermarkPngPath);
        }
        if ($uploadedWatermarkPath !== null) {
            @unlink($uploadedWatermarkPath);
        }
        H::restoreConfig($snapshot);
    }
});

it('tiles a repeated watermark at valid offsets and skips offsets that would overflow the canvas', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_idc_wmtile_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }
    $watermarkPngPath = null;
    $uploadedWatermarkPath = null;

    try {
        $pwgToken = idcAdminPwgToken($cookieJar);

        // Small (40x30) relative to the 240x180 destination computed
        // below -- no scaling needed (the previous test already covers
        // that branch), isolating the xrepeat/yrepeat double loop and its
        // in-bounds check. 'custom' position is required here:
        // ConfigurationSubController::processWatermark()'s own switch on
        // w[position] silently overwrites w[xpos]/w[ypos] server-side for
        // every *named* preset ('topleft', 'middle', ...); only 'custom'
        // leaves the explicit xpos=40/ypos=50 below as posted.
        $tmpWmPath = tempnam(sys_get_temp_dir(), 'pwg_idc_wmtile_img_');
        if ($tmpWmPath === false) {
            throw new RuntimeException('tempnam failed');
        }
        $watermarkPngPath = $tmpWmPath . '.png';
        $wmImg = imagecreatetruecolor(40, 30);
        if ($wmImg === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $green = imagecolorallocate($wmImg, 0, 255, 0);
        if ($green === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($wmImg, 0, 0, $green);
        imagepng($wmImg, $watermarkPngPath);

        $uploadedWatermarkPath = idcSaveWatermarkConfig($cookieJar, $pwgToken, [
            'w[position]' => 'custom',
            'w[xpos]' => '40',
            'w[ypos]' => '50',
            'w[xrepeat]' => '2',
            'w[yrepeat]' => '0',
            'w[opacity]' => '100',
            'w[minw]' => '1',
            'w[minh]' => '1',
        ], $watermarkPngPath, 'ct-idc-wmtile.png');

        $imageId = idcUploadSizedPhoto($this, 'Derivative Watermark Tile Album', 1200, 900, [0, 0, 255]);
        $imagePath = H::imagePath($imageId);
        $path = 'i.php?/' . idcDerivativePath($imagePath, '2s');

        $result = idcGet($path);
        expect($result['status'])->toBe(200);

        $decoded = imagecreatefromstring($result['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))->toBe(240);
        expect(imagesy($decoded))->toBe(180);

        // Primary composite: x=round(0.40*(240-40))=100, y=round(0.50*(180-30))=75.
        // xpad = 40 + max(30, round(40/4)) = 70.
        $primary = idcPixel($decoded, 100, 90);
        // i=+1: x2=100+70=170 -- 170+40=210 < 240, in bounds -- must
        // actually be composited by the repeat loop, not just computed.
        $repeatRight = idcPixel($decoded, 170, 90);
        // i=-1: x2=100-70=30 -- 30+40=70 < 240, in bounds too.
        $repeatLeft = idcPixel($decoded, 30, 90);
        // i=+2: x2=100+140=240 -- 240+40=280 not < 240 -- the in-bounds
        // check must skip this compose() call entirely, leaving the
        // background untouched here.
        $skippedRepeat = idcPixel($decoded, 230, 90);

        expect($primary['green'])->toBeGreaterThan(180);
        expect($repeatRight['green'])->toBeGreaterThan(180);
        expect($repeatLeft['green'])->toBeGreaterThan(180);
        expect($skippedRepeat['blue'])->toBeGreaterThan(180);
        expect($skippedRepeat['green'])->toBeLessThan(80);
    } finally {
        @unlink($cookieJar);
        if ($watermarkPngPath !== null) {
            @unlink($watermarkPngPath);
        }
        if ($uploadedWatermarkPath !== null) {
            @unlink($uploadedWatermarkPath);
        }
        H::restoreConfig($snapshot);
    }
});

it('reuses an already-cached, fresher sibling derivative instead of regenerating from the true original', function (): void {
    // 1728x1296 (4:3) -- an exact multiple of both 'small' (576x432) and
    // 'xsmall' (432x324)'s own ideal boxes, so trySwitchSource()'s
    // dsize-equality check (comparing a direct xsmall-from-original
    // computation against an xsmall-from-small-candidate computation)
    // holds with no floor()-rounding slack, and 'small' unambiguously
    // qualifies as a reuse candidate for 'xsmall' (same 4:3 aspect, no
    // cropping on either side, no watermark configured).
    $imageId = idcUploadSizedPhoto($this, 'Derivative Candidate Reuse Album', 1728, 1296, [90, 130, 200]);
    $imagePath = H::imagePath($imageId);

    // Generate 'small' for real first, from the true blue-ish original.
    $smallResult = idcGet('i.php?/' . idcDerivativePath($imagePath, 'sm'));
    expect($smallResult['status'])->toBe(200);

    $candidateDiskPath = idcDerivativeDiskPath($imagePath, 'sm');
    try {
        // The real derivative file on disk is www-data-owned mode 644
        // (Apache generated it) -- torres can't overwrite its *content* in
        // place, but the containing _data/i/... directories are
        // world-writable, so a delete+recreate at the same path works: an
        // unambiguous solid-red 576x432 image standing in for "small", so
        // any pixel sampled from a *reused* xsmall derivative is red,
        // never the true original's blue-ish fill.
        unlink($candidateDiskPath);
        $redImg = imagecreatetruecolor(576, 432);
        if ($redImg === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $red = imagecolorallocate($redImg, 255, 0, 0);
        if ($red === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($redImg, 0, 0, $red);
        imagejpeg($redImg, $candidateDiskPath, 90);
        // Comfortably fresher than both the true original's mtime and
        // 'small'/'xsmall''s own last_mod_time -- trySwitchSource()'s own
        // 2 freshness checks (`$candidate_mtime < $original_mtime`,
        // `$candidate_mtime < $candidate->last_mod_time`) must both pass.
        touch($candidateDiskPath, time() + 120);

        // Never requested before for this photo -- $need_generate is true
        // purely because no 'xs' derivative exists on disk yet, not
        // because of any staleness -- exactly the "must generate, but a
        // fresh sibling candidate exists" case trySwitchSource() is for.
        $xsmallResult = idcGet('i.php?/' . idcDerivativePath($imagePath, 'xs'));
        expect($xsmallResult['status'])->toBe(200);

        $decoded = imagecreatefromstring($xsmallResult['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))->toBe(432);
        expect(imagesy($decoded))->toBe(324);

        $center = idcPixel($decoded, 216, 162);
        expect($center['red'])->toBeGreaterThan(180);
        expect($center['blue'])->toBeLessThan(80);
    } finally {
        @unlink($candidateDiskPath);
        @unlink(idcDerivativeDiskPath($imagePath, 'xs'));
    }
});

it('regenerates from the true original when the only candidate sibling derivative has a stale mtime', function (): void {
    // Same reuse-eligible geometry as the test above (1728x1296 is an
    // exact multiple of both 'small' and 'xsmall''s own ideal boxes) --
    // the only difference is the candidate's own mtime, isolating
    // trySwitchSource()'s `$candidate_mtime < $original_mtime` freshness
    // check from every other qualifying condition.
    $imageId = idcUploadSizedPhoto($this, 'Derivative Candidate Stale Album', 1728, 1296, [90, 130, 200]);
    $imagePath = H::imagePath($imageId);

    $smallResult = idcGet('i.php?/' . idcDerivativePath($imagePath, 'sm'));
    expect($smallResult['status'])->toBe(200);

    $candidateDiskPath = idcDerivativeDiskPath($imagePath, 'sm');
    try {
        unlink($candidateDiskPath);
        $redImg = imagecreatetruecolor(576, 432);
        if ($redImg === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $red = imagecolorallocate($redImg, 255, 0, 0);
        if ($red === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($redImg, 0, 0, $red);
        imagejpeg($redImg, $candidateDiskPath, 90);
        // A deliberately ancient mtime -- trySwitchSource() must reject
        // this candidate despite it otherwise qualifying on every other
        // criterion exactly like the reuse test above (same aspect, no
        // crop, no watermark, same freshly-generated 'small' derivative).
        touch($candidateDiskPath, 100);

        $xsmallResult = idcGet('i.php?/' . idcDerivativePath($imagePath, 'xs'));
        expect($xsmallResult['status'])->toBe(200);

        $decoded = imagecreatefromstring($xsmallResult['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))->toBe(432);
        expect(imagesy($decoded))->toBe(324);

        // Regenerated from the true, blue-ish original -- not the
        // stale red candidate.
        $center = idcPixel($decoded, 216, 162);
        expect($center['blue'])->toBeGreaterThan(150);
        expect($center['red'])->toBeLessThan(150);
    } finally {
        @unlink($candidateDiskPath);
        @unlink(idcDerivativeDiskPath($imagePath, 'xs'));
    }
});

it('parses a PATH_INFO-style request when question_mark_in_urls is disabled', function (): void {
    $snapshot = H::snapshotConfig(['question_mark_in_urls']);

    try {
        H::setConfigValue('question_mark_in_urls', H::jsonEncode(false));

        $imageId = idcCreateTestPhoto($this, 'Derivative PathInfo Album');
        $imagePath = H::imagePath($imageId);
        // No leading '?' at all -- parseRequest()'s own
        // `isset($_SERVER['PATH_INFO'])` branch, not the QUERY_STRING one
        // every other test in this file exercises via the `i.php?/...`
        // form (question_mark_in_urls defaults to true).
        $path = 'i.php/' . idcDerivativePath($imagePath, 'th');

        $result = idcGet($path);
        expect($result['status'])->toBe(200);
        expect($result['headers']['content-type'] ?? null)->toBe('image/jpeg');

        // H::makeTestImage()'s own 200x150 canvas, scaled to fit 'th''s
        // 144x144 box (width binds): 144x108.
        $decoded = imagecreatefromstring($result['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))->toBe(144);
        expect(imagesy($decoded))->toBe(108);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects a syntactically valid custom size that was never registered in the allow-list', function (): void {
    // A large, microtime()-derived size no earlier request (in this test
    // or any concurrently-running one) could plausibly have already
    // registered in ImageStdParams::$custom -- passes every 400-level
    // validation parseCustomParams() itself does (>=20px ideal size, crop
    // fraction in [0, 1]), so this reaches the *separate*
    // `isset(ImageStdParams::$custom[$key])` allow-list check 403s
    // instead. (ImageStdParams::$custom is only ever populated by a real
    // Template::func_define_derivative() custom-size request elsewhere --
    // i.php itself never adds to it.)
    $uniqueWidth = 700 + ((int) (microtime(true) * 1000) % 300);
    $uniqueHeight = $uniqueWidth - 137;
    $path = 'i.php?/ct_custom_unregistered_' . uniqid() . '-cu_s' . $uniqueWidth . 'x' . $uniqueHeight . '.jpg';

    expect(H::httpStatus($path))->toBe(403);
});

it('ierrors 500 when the cached derivative file cannot be opened for reading', function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative Unreadable Album');
    $imagePath = H::imagePath($imageId);
    $diskPath = idcDerivativeDiskPath($imagePath, 'sq');

    // The containing _data/i/... directories are world-writable
    // (FilesystemHelper::mkgetdir() creates them 0777), so torres can
    // create a *new* file there even though every real, Apache-generated
    // derivative is www-data-owned mode 644 -- same delete+recreate trick
    // the candidate-reuse tests above use, but with 0000 permissions
    // instead of forged content. filemtime() (the $need_generate
    // freshness check __invoke() runs first) only needs directory
    // traversal, not read access to the file itself, so this still takes
    // the "already cached" fast path straight into sendDerivative() --
    // where fopen() must fail.
    if (! is_dir(dirname($diskPath))) {
        mkdir(dirname($diskPath), 0o777, true);
    }
    file_put_contents($diskPath, 'not a real jpeg, and unreadable regardless');
    chmod($diskPath, 0000);

    try {
        $result = idcGet('i.php?/' . idcDerivativePath($imagePath, 'sq'));

        expect($result['status'])->toBe(500);
        expect($result['body'])->toBe('Unable to open derivative file');
    } finally {
        @unlink($diskPath);
    }
});
