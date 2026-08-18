<?php

declare(strict_types=1);

use Piwigo\Core\AppInfo;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * `GET /api/v1/info` -- `pwg.getInfos`'s real replacement, admin only.
 */
it('returns a 401 problem+json for an anonymous visitor (no session at all)', function (): void {
    $page = H::visitPwg($this, '/identification.php');

    $exchange = H::apiFetchPsr7($page, 'GET', '/api/v1/info');
    $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange['request'], $exchange['response']);

    expect($exchange['response']->getStatusCode())
        ->toBe(401);
    $decoded = json_decode((string) $exchange['response']->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];
    expect($body['title'] ?? null)
        ->toBe('Unauthorized');
});

it('returns real installation counts as correctly-typed JSON for a logged-in admin', function (): void {
    $page = H::loginAsAdmin($this);

    $exchange = H::apiFetchPsr7($page, 'GET', '/api/v1/info');
    $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange['request'], $exchange['response']);

    expect($exchange['response']->getStatusCode())
        ->toBe(200);
    $decoded = json_decode((string) $exchange['response']->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];
    expect($body['version'])->toBe(AppInfo::VERSION)
        ->and($body['nbElements'])->toBeInt()
        ->and($body['nbCategories'])->toBeInt()
        ->and($body['nbUsers'])->toBeInt()
        ->and($body['nbGroups'])->toBeInt()
        ->and($body['nbComments'])->toBeInt()
        ->and($body)
        ->toHaveKey('cacheSize')
        ->and($body['cacheSize'])->toBeNull();
});
