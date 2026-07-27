<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\CustomLogoController (logo.php) -- supplements
 * CustomLogoTest.php (which already covers the 404-when-unconfigured and
 * real-<img>-src-resolves-end-to-end cases). This file covers the 2
 * branches that one doesn't: the conditional-GET (If-Modified-Since) 304
 * short-circuit, and the exact Content-Type/Content-Disposition/
 * Last-Modified headers a real 200 response carries.
 */

const CUSTOM_LOGO_CONDITIONAL_PATH = 'logo/browser-test-logo-conditional.png';

afterEach(function (): void {
    H::clearCustomLogo(CUSTOM_LOGO_CONDITIONAL_PATH);
});

/** @return array{status: int, headers: string} */
function customLogoRawGet(?string $ifModifiedSince = null): array
{
    $ch = curl_init(H::baseUrl() . '/logo.php');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    $requestHeaders = H::testHeaders();
    if ($ifModifiedSince !== null) {
        $requestHeaders[] = 'If-Modified-Since: ' . $ifModifiedSince;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    $response = curl_exec($ch);
    if (! is_string($response)) {
        throw new RuntimeException('curl_exec returned no response');
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    return ['status' => $status, 'headers' => $response];
}

it('serves the real PNG content-type, disposition and Last-Modified headers on a plain GET', function (): void {
    $png = H::makeTestPng();
    H::setCustomLogo(CUSTOM_LOGO_CONDITIONAL_PATH, $png);

    $result = customLogoRawGet();
    expect($result['status'])->toBe(200);

    $headerBlock = strtolower(explode("\r\n\r\n", $result['headers'], 2)[0]);
    expect($headerBlock)->toContain('content-type: image/png')
        ->and($headerBlock)->toContain('content-disposition: inline; filename="browser-test-logo-conditional.png"')
        ->and($headerBlock)->toContain('last-modified:');
});

it('returns 304 with an empty body when If-Modified-Since is present, regardless of its value', function (): void {
    H::setCustomLogo(CUSTOM_LOGO_CONDITIONAL_PATH, H::makeTestPng());

    // CustomLogoController's own conditional-GET branch only checks that
    // the header is *present* (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])),
    // not that its value actually matches the file's real last-modified
    // time -- a real behavioral quirk (not a full conditional-GET
    // implementation), confirmed by reading the class: any value at all
    // short-circuits to 304.
    $result = customLogoRawGet('Thu, 01 Jan 1970 00:00:00 GMT');
    expect($result['status'])->toBe(304);

    $body = explode("\r\n\r\n", $result['headers'], 2)[1] ?? '';
    expect(trim($body))->toBe('');
});
