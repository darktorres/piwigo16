<?php

declare(strict_types=1);

use Piwigo\Core\AppInfo;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * `GET /api/v1/version` -- the real end-to-end proof that
 * public/.htaccess's `^api/v1/` rewrite, public/api.php, RouteDefinitions's
 * `api_v1_version` route, and Piwigo\Controller\Api\VersionController all
 * actually wire up together over a real HTTP request (P27's thin slice).
 */
/**
 * @return array{status: int, body: string, content_type: string}
 */
function apiVersionGet(): array
{
    $ch = curl_init(H::baseUrl() . '/api/v1/version');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    unset($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'content_type' => is_string($contentType) ? $contentType : '',
    ];
}

it('returns the real app version as JSON, no envelope, no auth required', function (): void {
    $result = apiVersionGet();

    expect($result['status'])
        ->toBe(200);
    expect($result['content_type'])
        ->toBe('application/json');
    expect(json_decode($result['body'], true))
        ->toBe([
            'version' => AppInfo::VERSION,
        ]);

    // Same real request, wrapped as PSR-7 for the OpenAPI contract check --
    // no Playwright page exists in this file at all, so this goes through
    // curlApiPsr7() (curlApi()'s own PSR-7 counterpart), not apiFetchPsr7().
    // The 404/405 tests below deliberately aren't wired the same way: they
    // exercise routing behavior for an unmatched path/method, neither of
    // which has a matching operation in the spec to validate against.
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_openapi_version_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }
    $exchange = H::curlApiPsr7($cookieJar, 'GET', '/api/v1/version');
    @unlink($cookieJar);
    $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange['request'], $exchange['response']);
});

it('returns an RFC 9457 problem+json 404 for an unmatched /api/v1 path', function (): void {
    $ch = curl_init(H::baseUrl() . '/api/v1/this-resource-does-not-exist');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    unset($ch);

    expect($status)
        ->toBe(404);
    expect($contentType)
        ->toBe('application/problem+json');
    $decoded = is_string($body) ? json_decode($body, true) : null;
    expect(is_array($decoded) ? $decoded['title'] ?? null : null)
        ->toBe('Not Found');
});

it('returns an RFC 9457 problem+json 405 for a POST to the GET-only version endpoint', function (): void {
    $ch = curl_init(H::baseUrl() . '/api/v1/version');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    unset($ch);

    expect($status)
        ->toBe(405);
    expect($contentType)
        ->toBe('application/problem+json');
    $decoded = is_string($body) ? json_decode($body, true) : null;
    expect(is_array($decoded) ? $decoded['title'] ?? null : null)
        ->toBe('Method Not Allowed');
});
