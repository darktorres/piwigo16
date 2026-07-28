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
