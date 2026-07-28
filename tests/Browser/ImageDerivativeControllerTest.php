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
