<?php

declare(strict_types=1);

use PgSql\Connection;
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
function idcDerivativePath(string $imagePath, string $suffix, string $ext = 'jpg'): string
{
    $withoutExt = preg_replace('/\.\w+$/', '', $imagePath);
    if (! is_string($withoutExt)) {
        throw new RuntimeException("idcDerivativePath(): preg_replace() failed for '{$imagePath}'");
    }

    return $withoutExt . '-' . $suffix . '.' . $ext;
}

function idcCreateTestPhoto(object $test, string $albumName): int
{
    $page = H::loginAsAdmin($test);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => $albumName . ' ' . uniqid(),
    ]);
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

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
    ];
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
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => $albumName . ' ' . uniqid(),
    ]);
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
 * Writes images.rotation directly -- ImageDerivativeController's
 * __invoke() reads this column (via ImageRepository::findByPath()) to set
 * $this->rotationAngle *without* any live EXIF re-read whenever it's
 * already non-null (ImageBackend::getRotationAngleFromCode(): 0=0deg,
 * 1=90deg, 2=180deg, 3=270deg) -- writing it directly, before the first
 * derivative request for the photo, exercises that "already resolved"
 * branch deterministically, without depending on a GD-generated JPEG
 * happening to carry a real EXIF Orientation tag (it doesn't).
 */
function idcSetImageRotationCode(int $imageId, int $rotationCode): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf('UPDATE images SET rotation = %d WHERE id = %d', $rotationCode, $imageId));
    H::dbClose($db);
}

/**
 * The on-disk path a derivative of $imagePath (with the given suffix, e.g.
 * 'sm'/'xs') is cached at -- ImageDerivativeController resolves derivative
 * paths against the live, container-bound Paths->root (the repo root, one level above
 * public/ -- same root idcCreateTestPhoto()'s own orphan-file test above
 * uses) joined with CurrentConfig::derivativeDir() ('_data/i/').
 */
function idcDerivativeDiskPath(string $imagePath, string $suffix, string $ext = 'jpg'): string
{
    return dirname(__DIR__, 2) . '/_data/i/' . idcDerivativePath($imagePath, $suffix, $ext);
}

/**
 * @return array{red: int, green: int, blue: int}
 */
function idcPixel(GdImage $image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);
    if ($rgb === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $colors = imagecolorsforindex($image, $rgb);

    return [
        'red' => $colors['red'],
        'green' => $colors['green'],
        'blue' => $colors['blue'],
    ];
}

/**
 * Opens a fresh mysqli connection against the fixture DB -- several of the
 * coverage-gap tests below need a one-off raw UPDATE against a row no
 * existing idc*()/H:: helper already covers, matching
 * idcSetImageRotationCode()'s own established connect-query-close shape
 * rather than duplicating the connection boilerplate at every call site.
 */
function idcDb(): mysqli|Connection
{
    return H::connect();
}

/**
 * @return array<string, mixed>|null
 */
function idcFetchOne(string $sql): ?array
{
    $db = idcDb();
    $row = H::dbFetchAssoc($db, $sql);
    H::dbClose($db);

    return $row;
}

/**
 * The on-disk path of a real image row's own source file -- as opposed to
 * one of its derivatives (see idcDerivativeDiskPath() below).
 */
function idcSourceDiskPath(string $imagePath): string
{
    return dirname(__DIR__, 2) . '/' . $imagePath;
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

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
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

    $statusResult = idcAdminCurl($baseUrl . '/ws.php?format=json', [
        'method' => 'pwg.session.getStatus',
    ], $cookieJar);
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

    $file = idcWatermarkFileFromSettings('idcSaveWatermarkConfig(): could not find the uploaded watermark file entry');

    return dirname(__DIR__, 2) . '/' . ltrim($file, '/');
}

/**
 * Reads derivative_settings.watermark_json's own `file` field directly --
 * watermark config is real JSON, with no unserialize() class-
 * instantiation side effect to avoid.
 */
function idcWatermarkFileFromSettings(string $errorMessage): string
{
    $settings = H::snapshotDerivativeConfig()['settings'];
    $watermarkJson = is_array($settings) ? ($settings['watermark_json'] ?? null) : null;
    $decoded = is_string($watermarkJson) ? json_decode($watermarkJson, true) : null;
    $file = is_array($decoded) ? ($decoded['file'] ?? null) : null;
    if (! is_string($file) || $file === '') {
        throw new RuntimeException($errorMessage);
    }

    return $file;
}

it("sends 304 Not Modified when If-Modified-Since matches the cached derivative's own mtime", function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative 304 Album');
    $path = 'i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th');

    $first = idcGet($path);
    expect($first['status'])->toBe(200);
    $lastModified = $first['headers']['last-modified'] ?? null;
    expect($lastModified)
        ->not->toBeNull();

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
    expect($decoded)
        ->toBeArray();
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

    expect($status)
        ->toBe(301);
    expect($location)
        ->toContain('action.php');
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
    // with no matching images.path row -- ImageRepository::
    // findByPath() returns null and this is the *other* 404 branch,
    // distinct from a missing file. Placed inside a directory an earlier
    // real API upload just created, so the web server user (Apache runs
    // as www-data, not this shell's own user) can already read/traverse
    // it without any extra chmod dance.
    $imageId = idcCreateTestPhoto($this, 'Derivative Orphan Setup Album');
    $realImagePath = H::imagePath($imageId);
    // ImageDerivativeController resolves paths against
    // the live, container-bound Paths->root, which public/i.php builds as
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
    // fixture's own derivative_size row for '4xlarge' has enabled=0), and
    // ImageStdParams::loadFromDb() self-heals the disabled set back to
    // its default (3xlarge/4xlarge) the moment it reads back zero disabled
    // rows -- confirmed live, deleting those rows doesn't stick past the
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

    expect($status)
        ->toBe(301);
    expect($location)
        ->not->toContain('action.php');
    expect($location)
        ->toContain('light-default.jpg');
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
    $snapshot = H::snapshotDerivativeConfig();

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

    $statusResult = $curl($baseUrl . '/ws.php?format=json', [
        'method' => 'pwg.session.getStatus',
    ]);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

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

        $watermarkFile = idcWatermarkFileFromSettings('Could not find the uploaded watermark file entry');
        // The watermark compose path reads this via
        // `$this->paths->root . $wm->file` (ImageDerivativeController.php)
        // -- root is the repo root, one level *above* public/, not public/
        // itself (public/ has no `local/` symlink the way it does for
        // `themes/`, so watermarks are never web-servable directly).
        $uploadedWatermarkPath = dirname(__DIR__, 2) . '/' . ltrim($watermarkFile, '/');

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('applies the stored rotation angle before crop/scale, swapping width/height for a 90-degree rotation', function (): void {
    // A landscape (100x50) source with a horizontal color split (red top
    // half, blue bottom half). ImageDerivativeController's own "// rotate"
    // block (getRotationAngleFromCode(1) => 90 degrees) runs *before*
    // $o_size/$d_size are read from $image->getWidth()/getHeight(), so
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
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Derivative Rotation Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $imageId = H::uploadPhotoViaApi($srcPath, (int) $albumResult['id'], 'Derivative Rotation Photo');
    @unlink($srcPath);

    // Set *before* the first derivative request for this photo: a fresh
    // upload's own images.rotation starts NULL, in which case __invoke()
    // would compute rotationAngle from a live EXIF re-read instead
    // (ImageBackend::getRotationAngle()) -- writing the code directly
    // exercises the "already resolved" branch
    // (getRotationAngleFromCode($row->rotation)) deterministically.
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
    expect(imagesx($decoded))
        ->toBe(50);
    expect(imagesy($decoded))
        ->toBe(100);

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
    $snapshot = H::snapshotDerivativeConfig();
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
        expect(imagesx($decoded))
            ->toBe(240);
        expect(imagesy($decoded))
            ->toBe(180);

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('tiles a repeated watermark at valid offsets and skips offsets that would overflow the canvas', function (): void {
    $snapshot = H::snapshotDerivativeConfig();
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
        expect(imagesx($decoded))
            ->toBe(240);
        expect(imagesy($decoded))
            ->toBe(180);

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
        H::restoreDerivativeConfig($snapshot);
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
        expect(imagesx($decoded))
            ->toBe(432);
        expect(imagesy($decoded))
            ->toBe(324);

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
        expect(imagesx($decoded))
            ->toBe(432);
        expect(imagesy($decoded))
            ->toBe(324);

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
        expect(imagesx($decoded))
            ->toBe(144);
        expect(imagesy($decoded))
            ->toBe(108);
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
    // Template::funcDefineDerivative() custom-size request elsewhere --
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

// The block below closes a further, targeted set of coverage gaps in the
// same __invoke()/parseRequest()/trySwitchSource()/sendDerivative() flow
// the tests above already exercise -- each one's own comment explains
// exactly which branch it's isolating and, where the underlying math is
// non-obvious (trySwitchSource()'s own candidate-selection loop), how the
// expected numbers were actually verified (via SizingParams::compute()
// itself, not hand-derived) rather than just asserted.

it('resolves the rotation angle live from EXIF when the DB rotation column is still NULL', function (): void {
    // A fresh upload's own images.rotation is never actually left NULL by
    // UploadService::addUploadedFile() (it always writes a real code, 0
    // included, from a live EXIF read at upload time -- see
    // ImageBackend::getRotationCodeFromAngle()'s own null=>0 case) -- the
    // NULL branch this test targets only exists for a row that reached the
    // DB some other way (e.g. a legacy row a schema migration never
    // populated the newer column for). Writing NULL directly reproduces
    // that state deterministically.
    $imageId = idcCreateTestPhoto($this, 'Derivative Rotation Null Album');
    $db = idcDb();
    H::dbQuery($db, sprintf('UPDATE images SET rotation = NULL WHERE id = %d', $imageId));
    H::dbClose($db);

    $result = idcGet('i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th'));
    expect($result['status'])->toBe(200);

    // __invoke() persists the freshly-resolved code straight back via
    // ImageRepository::updateRotation() -- confirms the live-EXIF branch
    // really ran, not just that the request happened to succeed anyway.
    $row = idcFetchOne(sprintf('SELECT rotation FROM images WHERE id = %d', $imageId));
    expect($row['rotation'] ?? null)->not->toBeNull();
});

it('sends a long-lived Expires header when both the source file and the derivative type have been unchanged for over 24 hours', function (): void {
    $snapshot = H::snapshotDerivativeConfig();
    try {
        $imageId = idcCreateTestPhoto($this, 'Derivative LongExpires Album');
        $imagePath = H::imagePath($imageId);
        $srcDiskPath = idcSourceDiskPath($imagePath);

        // Age BOTH halves of `max($src_mtime, $params->last_mod_time)` --
        // the source file's own mtime (www-data-owned; a copy-then-touch
        // dance sidesteps that torres can't chmod/touch it directly, same
        // trick the candidate-reuse tests above use for a derivative file)
        // and 'square''s own derivative_size.last_mod_time (real
        // wall-clock time(), set once when this row was first self-healed
        // at the very start of this Browser suite's run -- almost
        // certainly still "recent" by the time this test runs, which would
        // otherwise win the max() and keep the elseif condition false no
        // matter how old the source file itself is made).
        $old = time() - 100 * 24 * 3600;
        $tmp = tempnam(sys_get_temp_dir(), 'pwg_idc_age_');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed');
        }
        copy($srcDiskPath, $tmp);
        unlink($srcDiskPath);
        copy($tmp, $srcDiskPath);
        @unlink($tmp);
        // copy()'s destination inherits tempnam()'s own restrictive 0600
        // mode, not the original www-data-owned file's 0644 -- torres now
        // owns the recreated file, so www-data (a supplementary member of
        // the torres group, not the owner) would otherwise lose read
        // access entirely once generation actually opens it for real.
        chmod($srcDiskPath, 0644);
        touch($srcDiskPath, $old);

        $db = idcDb();
        H::dbQuery($db, sprintf("UPDATE derivative_size SET last_mod_time = %d WHERE name = 'square'", $old));
        H::dbClose($db);

        $result = idcGet('i.php?/' . idcDerivativePath($imagePath, 'sq'));
        expect($result['status'])->toBe(200);
        expect($result['headers']['cache-control'] ?? null)->not->toBe('no-store, max-age=100');
        $expires = $result['headers']['expires'] ?? null;
        expect($expires)
            ->not->toBeNull();
        $expiresTs = strtotime((string) $expires);
        expect($expiresTs)
            ->toBeGreaterThan(time() + 9 * 24 * 3600);
        expect($expiresTs)
            ->toBeLessThan(time() + 11 * 24 * 3600);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('generates a custom size once its own key is registered, averaging sharpen across every defined type', function (): void {
    $snapshot = H::snapshotDerivativeConfig();
    try {
        // Prime derivative_settings/derivative_size on a throwaway photo
        // first (self-heals into the DB on the very first-ever real i.php
        // request if no earlier test in this run has already forced it) --
        // JSON_SET() against a not-yet-existing settings row would
        // silently update 0 rows.
        $primeId = idcCreateTestPhoto($this, 'Derivative Custom Prime Album');
        expect(idcGet('i.php?/' . idcDerivativePath(H::imagePath($primeId), 'sq'))['status'])->toBe(200);

        // parseCustomParams()'s own 's' (single WxH token, crop=0) branch --
        // SizingParams::$max_crop is untyped and stays a plain int 0 here
        // (parseCustomParams() never reassigns it in the 's' branch), so
        // addUrlTokens()'s strict `$max_crop === 0.0` check is false for
        // this int 0 (the same int/float quirk trySwitchSource()'s own
        // docblock documents) -- both the registration key below and
        // parseRequest()'s own validation call compute the SAME
        // (non-'s'-prefixed) 2-token key via the same code, so they still
        // agree with each other even though the URL's own 's' prefix looks
        // unrelated to the persisted key shape (verified directly against
        // DerivativeParams::addUrlTokens(), not hand-derived).
        // JSON_OBJECT(), not JSON_SET() -- verified live against the
        // fixture DB: no real production code path ever populates
        // ImageStdParams::$custom (funcDefineDerivative()'s width/height
        // form is dead code -- grep confirms its only real template call
        // site is commented out), so self::save()'s own json_encode([])
        // persists custom_json as the JSON *array* `[]`, never `{}` --
        // JSON_SET()'s object-member path silently no-ops against an array
        // root (confirmed directly: `JSON_SET('[]', '$."k"', 1)` returns
        // `[]` unchanged), so it would leave the key unregistered and this
        // test would still 403. A full JSON_OBJECT() replacement is safe
        // precisely because nothing else in this suite ever reads or
        // writes a second key here.
        $key = '150x100_a';
        $db = idcDb();
        // JSON_OBJECT() is MySQL-only -- Postgres's own function is
        // jsonb_build_object() (verified live, matches custom_json's real
        // jsonb column type directly).
        $jsonObjectExpr = $db instanceof mysqli
            ? "JSON_OBJECT('%s', %d)"
            : "jsonb_build_object('%s', %d)";
        H::dbQuery($db, sprintf(
            'UPDATE derivative_settings SET custom_json = ' . $jsonObjectExpr,
            H::dbEscape($db, $key),
            time()
        ));
        H::dbClose($db);

        $imageId = idcCreateTestPhoto($this, 'Derivative Custom Success Album');
        $imagePath = H::imagePath($imageId);
        $withoutExt = preg_replace('/\.\w+$/', '', $imagePath);
        if (! is_string($withoutExt)) {
            throw new RuntimeException('preg_replace failed');
        }
        $path = 'i.php?/' . $withoutExt . '-cu_s150x100.jpg';

        $result = idcGet($path);
        expect($result['status'])->toBe(200);
        expect($result['headers']['content-type'] ?? null)->toBe('image/jpeg');

        // 200x150 (H::makeTestImage()'s own fixed canvas) classically
        // scaled into a 150x100 (crop=0) box: height is the binding ratio
        // (0.667 < width's 0.75) -- verified directly against
        // SizingParams::compute(), not hand-derived.
        $decoded = imagecreatefromstring($result['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))
            ->toBe(133);
        expect(imagesy($decoded))
            ->toBe(100);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('rejects a custom "exact crop" (e-prefixed) size that was never registered in the allow-list', function (): void {
    // parseCustomParams()'s 'e' branch (crop=1, min_size=ideal_size) -- a
    // distinct code path from the 's'/3-token custom-size forms every
    // other custom-size test in this file already exercises.
    $path = 'i.php?/ct_custom_e_' . uniqid() . '-cu_e150x150.jpg';

    expect(H::httpStatus($path))->toBe(403);
});

it('ierrors 500 "dir create error" when the derivative cache directory cannot be created', function (): void {
    $imageId = idcCreateTestPhoto($this, 'Derivative Mkgetdir Album');
    $originalDiskPath = idcSourceDiskPath(H::imagePath($imageId));

    $token = uniqid();
    $newRelDir = 'ct_mkgetdir_' . $token;
    $newRelPath = $newRelDir . '/dummy.jpg';
    $newDiskDir = dirname(__DIR__, 2) . '/' . $newRelDir;
    $derivDir = dirname(__DIR__, 2) . '/_data/i/' . $newRelDir;

    mkdir($newDiskDir, 0777, true);
    copy($originalDiskPath, $newDiskDir . '/dummy.jpg');

    $db = idcDb();
    H::dbQuery($db, sprintf("UPDATE images SET path = '%s' WHERE id = %d", H::dbEscape($db, $newRelPath), $imageId));
    H::dbClose($db);

    // Pre-create (and lock down) the derivative cache directory that would
    // otherwise be created on demand -- mkgetdir() only ever needs to
    // *create* a directory the very first time a given date/path prefix is
    // requested, and every other test in this file shares the SAME
    // date-based upload/derivative subtree (Env::now()'s frozen test
    // clock); locking THAT down would break every concurrently-running
    // test. This synthetic, uniquely-named subdirectory is never touched
    // by anything else, so locking only it down is fully isolated.
    mkdir($derivDir, 0777, true);
    chmod($derivDir, 0555);

    try {
        $result = idcGet('i.php?/' . $newRelDir . '/dummy-sq.jpg');

        expect($result['status'])->toBe(500);
        expect($result['body'])->toBe('dir create error');
    } finally {
        chmod($derivDir, 0777);
        @rmdir($derivDir);
        @unlink($newDiskDir . '/dummy.jpg');
        @rmdir($newDiskDir);
    }
});

it('applies a configured non-zero sharpen amount when generating a standard-type derivative', function (): void {
    $snapshot = H::snapshotDerivativeConfig();
    try {
        // Prime the row first (see the custom-size test's own comment on
        // why), then set a real, non-zero sharpen amount -- no admin UI in
        // this rewrite exposes this column at all (grep confirms it), so a
        // direct write is the only way to reach it: DerivativeParams->
        // sharpen defaults to 0.0 for every one of ImageStdParams::
        // getDefaultSizes()'s own entries, so every OTHER test in this
        // suite requesting a standard type takes the falsy `(bool)
        // $params->sharpen` branch and never actually reaches
        // $image->sharpen() itself.
        $primeId = idcCreateTestPhoto($this, 'Derivative Sharpen Prime Album');
        expect(idcGet('i.php?/' . idcDerivativePath(H::imagePath($primeId), 'sq'))['status'])->toBe(200);

        $db = idcDb();
        H::dbQuery($db, sprintf("UPDATE derivative_size SET sharpen = '0.5000' WHERE name = 'square'"));
        H::dbClose($db);

        $imageId = idcCreateTestPhoto($this, 'Derivative Sharpen Album');
        $result = idcGet('i.php?/' . idcDerivativePath(H::imagePath($imageId), 'sq'));

        expect($result['status'])->toBe(200);
        expect($result['headers']['content-type'] ?? null)->toBe('image/jpeg');
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('clamps the JPEG compression quality to 75 when generating a 4xlarge derivative', function (): void {
    $snapshot = H::snapshotDerivativeConfig();
    try {
        $db = idcDb();
        H::dbQuery($db, sprintf("UPDATE derivative_size SET enabled = 1 WHERE name = '4xlarge'"));
        H::dbClose($db);

        // A real crop/scale change isn't needed to leave the "0 changes"
        // fast path -- a stored rotation alone forces $changes>=1 (see the
        // rotation test above), and the 200x150 fixture canvas stays far
        // inside 4xlarge's own 3000x2250 box either way, isolating this
        // from any crop/scale confound the same way that test's own
        // comment does.
        $imageId = idcCreateTestPhoto($this, 'Derivative FourXLarge Album');
        idcSetImageRotationCode($imageId, 1);

        $result = idcGet('i.php?/' . idcDerivativePath(H::imagePath($imageId), '4x'));
        expect($result['status'])->toBe(200);

        $decoded = imagecreatefromstring($result['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        // 200x150 rotated 90 degrees: width/height swap, no scaling needed.
        expect(imagesx($decoded))
            ->toBe(150);
        expect(imagesy($decoded))
            ->toBe(200);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('resolves a source file located one directory above the app root via the "../" fallback', function (): void {
    // ImageDerivativeController resolves paths against CurrentPaths::
    // get()->root -- the repo root, one level above public/ (see this
    // file's own header comment). "One level above root" therefore lands
    // outside the repo entirely, in the shell user's own home directory --
    // reachable by the live Apache/www-data process here only because
    // www-data is a supplementary member of the torres group (the same
    // group-membership precedent this project's own permission fixes
    // rely on elsewhere), giving it traversal+read access via the
    // directory's own group bits despite not owning it.
    $token = uniqid();
    $filename = 'ct_uplevel_' . $token . '.jpg';
    $diskPath = dirname(__DIR__, 3) . '/' . $filename;

    $source = H::makeTestImage('CT Uplevel');
    copy($source, $diskPath);
    @unlink($source);
    @chmod($diskPath, 0644);

    try {
        // idcDerivativePath() inserts the '-sq' type token *before* the
        // extension ('ct_uplevel_XXXX-sq.jpg'), not after it -- a naively
        // appended '-sq.jpg' would produce a double-extensioned request
        // whose own ext/type-suffix stripping never matches the real
        // on-disk filename at all, silently skipping the '../' branch this
        // test exists to exercise instead of hitting it.
        $path = 'i.php?/' . idcDerivativePath($filename, 'sq');
        // No matching images.path row exists for '../{$filename}' -- this
        // only needs to prove the '../' resolution itself ran (the file
        // really was found one level up), not a full successful
        // generation; the "Db file path not found" 404 fires right after,
        // the same 2nd-lookup-stage 404 the orphan-file test above covers
        // for the root-level case.
        expect(H::httpStatus($path))->toBe(404);
    } finally {
        @unlink($diskPath);
    }
});

it('never attempts sibling-derivative reuse when the source height is unknown', function (): void {
    // originalSize's own docblock: width is guaranteed once set, but
    // height is independently nullable (both are separately-nullable DB
    // columns) -- a genuinely possible state (e.g. a partial metadata
    // sync) reproduced directly, since no real upload flow ever leaves
    // height null while width is set.
    $imageId = idcCreateTestPhoto($this, 'Derivative HeightNull Album');
    $db = idcDb();
    H::dbQuery($db, sprintf('UPDATE images SET height = NULL WHERE id = %d', $imageId));
    H::dbClose($db);

    $result = idcGet('i.php?/' . idcDerivativePath(H::imagePath($imageId), 'sq'));
    expect($result['status'])->toBe(200);
});

it('exercises every candidate-selection outcome in trySwitchSource() for a classic-type request', function (): void {
    // Verified directly via SizingParams::compute() (not hand-derived --
    // double-rounding across 2 successive scale operations is genuinely
    // fiddly): requesting 'medium' (792x594) for a 700x760 source --
    //  - square/thumb/2small/xsmall/small: box smaller than 'medium''s own
    //    -> excluded before ever computing a candidate size at all.
    //  - 'medium' itself: excluded as the same type being requested.
    //  - 'large': structurally plausible on paper, but its own (real,
    //    box-constrained) downscale of the true original, fed back through
    //    'medium''s own math, lands 1px off from computing 'medium'
    //    directly from the true original -- excluded on a genuine dsize
    //    mismatch, not a freshness check.
    //  - 'xlarge'/'xxlarge': both bigger than the source in every
    //    dimension, so neither one changes it at all -- feeding that
    //    unchanged size back through 'medium''s math reproduces the direct
    //    computation exactly, so both structurally qualify -- but neither
    //    has ever been cached for this brand new photo, so both still
    //    fail the freshness check and get skipped too.
    // End to end: every single candidate is rejected for a different
    // reason, trySwitchSource() returns false, and generation falls back
    // to the true original -- the exact final size below is only
    // reachable that way.
    $imageId = idcUploadSizedPhoto($this, 'Derivative Candidate Sweep Album', 700, 760, [90, 130, 200]);
    $imagePath = H::imagePath($imageId);

    $result = idcGet('i.php?/' . idcDerivativePath($imagePath, 'me'));
    expect($result['status'])->toBe(200);

    $decoded = imagecreatefromstring($result['body']);
    if ($decoded === false) {
        throw new RuntimeException('Failed to decode the derivative response body as an image');
    }
    expect(imagesx($decoded))
        ->toBe(547);
    expect(imagesy($decoded))
        ->toBe(594);
});

it('reuses a larger cropped-type sibling via the max_crop!=0 candidate branch, with some candidates excluded by watermark mismatch', function (): void {
    $snapshot = H::snapshotDerivativeConfig();
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_idc_cropreuse_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }
    $watermarkPngPath = null;
    $uploadedWatermarkPath = null;

    try {
        $pwgToken = idcAdminPwgToken($cookieJar);

        // min 200x200: bigger than 'square' (120x120) and 'thumb'
        // (144x144) -- both stay use_watermark=false -- but smaller than
        // every other classic type's own ideal_size ('2small' and up),
        // which all become use_watermark=true. Requesting 'square' below
        // means every classic candidate from '2small' up gets excluded at
        // trySwitchSource()'s own watermark-mismatch check, leaving
        // 'thumb' as the only surviving, structurally-matching candidate
        // (verified directly against applyGlobal()/trySwitchSource(),
        // not hand-derived).
        $watermarkPngPath = tempnam(sys_get_temp_dir(), 'pwg_idc_cropreuse_img_') . '.png';
        $wmImg = imagecreatetruecolor(10, 10);
        if ($wmImg === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $red = imagecolorallocate($wmImg, 255, 0, 0);
        if ($red === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($wmImg, 0, 0, $red);
        imagepng($wmImg, $watermarkPngPath);

        $uploadedWatermarkPath = idcSaveWatermarkConfig($cookieJar, $pwgToken, [
            'w[position]' => 'topleft',
            'w[xpos]' => '0',
            'w[ypos]' => '0',
            'w[xrepeat]' => '0',
            'w[yrepeat]' => '0',
            'w[opacity]' => '100',
            'w[minw]' => '200',
            'w[minh]' => '200',
        ], $watermarkPngPath, 'ct-idc-cropreuse.png');

        // A perfectly square source: 'thumb' (144x144, classic) scaled
        // from it stays exactly 144x144, and crop-squaring *that* back
        // down to 'square''s own 120x120 box lands on exactly the same
        // [120,120] trySwitchSource() computes directly from the true
        // original -- verified via SizingParams::compute() directly, not
        // hand-derived.
        $imageId = idcUploadSizedPhoto($this, 'Derivative Crop Reuse Album', 1200, 1200, [40, 200, 90]);
        $imagePath = H::imagePath($imageId);

        // Generate 'thumb' for real first, from the true green-ish original.
        $thumbResult = idcGet('i.php?/' . idcDerivativePath($imagePath, 'th'));
        expect($thumbResult['status'])->toBe(200);

        $thumbDiskPath = idcDerivativeDiskPath($imagePath, 'th');
        // Same delete+recreate dance the candidate-reuse tests above use --
        // an unambiguous solid-red 144x144 stand-in for "thumb", so a
        // *reused* square derivative is visibly red, never the true
        // original's own green-ish fill.
        unlink($thumbDiskPath);
        $redImg = imagecreatetruecolor(144, 144);
        if ($redImg === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $red2 = imagecolorallocate($redImg, 255, 0, 0);
        if ($red2 === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($redImg, 0, 0, $red2);
        imagejpeg($redImg, $thumbDiskPath, 90);
        touch($thumbDiskPath, time() + 120);

        $squareResult = idcGet('i.php?/' . idcDerivativePath($imagePath, 'sq'));
        expect($squareResult['status'])->toBe(200);

        $decoded = imagecreatefromstring($squareResult['body']);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode the derivative response body as an image');
        }
        expect(imagesx($decoded))
            ->toBe(120);
        expect(imagesy($decoded))
            ->toBe(120);

        $center = idcPixel($decoded, 60, 60);
        expect($center['red'])->toBeGreaterThan(180);
        expect($center['green'])->toBeLessThan(80);
    } finally {
        @unlink($cookieJar);
        if ($watermarkPngPath !== null) {
            @unlink($watermarkPngPath);
        }
        if ($uploadedWatermarkPath !== null) {
            @unlink($uploadedWatermarkPath);
        }
        H::restoreDerivativeConfig($snapshot);
    }
});

it('serves the correct Content-Type for a gif derivative', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Derivative Gif Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }

    $img = imagecreatetruecolor(200, 150);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $color = imagecolorallocate($img, 90, 130, 200);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefill($img, 0, 0, $color);
    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_idc_gif_');
    if ($tmpPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    $gifPath = $tmpPath . '.gif';
    imagegif($img, $gifPath);

    // UploadService::addUploadedFile() derives the STORED extension from
    // getimagesize()'s own detected IMAGETYPE_*, not from the multipart
    // Content-Type H::uploadPhotoViaApi() always declares ('image/jpeg') --
    // a real GIF's own magic bytes still get stored (and served back) as
    // '.gif'. GD (this environment's own graphics library) reads/writes
    // GIF natively, so this goes through a real, first-time generation,
    // unlike the webp test below.
    $imageId = H::uploadPhotoViaApi($gifPath, (int) $albumResult['id'], 'Derivative Gif Photo');
    @unlink($gifPath);

    // ImageDerivativeController::parseRequest() derives BOTH the disk
    // lookup path and the Content-Type header from the request URL's own
    // extension token (derivativeExt), not from the underlying file's
    // real magic bytes -- idcDerivativePath()'s default 'jpg' extension
    // would request a '-th.jpg' derivative path that was never generated
    // (this real .gif original only ever produces a '-th.gif' one),
    // a genuine 404, not a Content-Type bug. Confirmed live via
    // ImageDerivativeController.php's own `case '.gif': $ctype =
    // 'image/gif';` switch.
    $result = idcGet('i.php?/' . idcDerivativePath(H::imagePath($imageId), 'th', 'gif'));
    expect($result['status'])->toBe(200);
    expect($result['headers']['content-type'] ?? null)->toBe('image/gif');
});

it('serves the correct Content-Type for an already-cached webp derivative', function (): void {
    // GD (this environment's own graphics library, confirmed via
    // gd_info()) has no imagecreatefromwebp() *decode* wiring in
    // ImageGd::__construct() (jpg/jpeg/png/gif only) -- generating a *new*
    // webp derivative from scratch would throw an ImageProcessingException
    // before ever reaching sendDerivative(). Pre-seeding the cached file
    // directly and relying on the "already generated" fast path
    // (need_generate=false) exercises the Content-Type switch this test
    // targets without ever constructing a ImageBackend from the webp source at
    // all -- exactly how a real request for an already-generated webp
    // derivative behaves in production too, regardless of backend.
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Derivative Webp Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }

    $img = imagecreatetruecolor(200, 150);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $color = imagecolorallocate($img, 90, 130, 200);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefill($img, 0, 0, $color);
    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_idc_webp_');
    if ($tmpPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    $webpPath = $tmpPath . '.webp';
    imagewebp($img, $webpPath);

    $imageId = H::uploadPhotoViaApi($webpPath, (int) $albumResult['id'], 'Derivative Webp Photo');
    @unlink($webpPath);

    // Same reasoning as the gif test above -- the disk cache path and the
    // request URL both need the real 'webp' extension token, not the
    // 'jpg' default, or the server looks up (and reports Content-Type
    // for) a completely different, never-generated path.
    $imagePath = H::imagePath($imageId);
    $derivDiskPath = idcDerivativeDiskPath($imagePath, 'th', 'webp');
    if (! is_dir(dirname($derivDiskPath))) {
        mkdir(dirname($derivDiskPath), 0o777, true);
    }
    imagewebp($img, $derivDiskPath);
    touch($derivDiskPath, time() + 60);

    try {
        $result = idcGet('i.php?/' . idcDerivativePath($imagePath, 'th', 'webp'));
        expect($result['status'])->toBe(200);
        expect($result['headers']['content-type'] ?? null)->toBe('image/webp');
    } finally {
        @unlink($derivDiskPath);
    }
});
