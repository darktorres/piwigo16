<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Album creation via the WS API, driven through a real authenticated
 * browser session (proves cookie-based auth end-to-end). Response-shape
 * assertions belong to WsCategoriesMutationTest — this only proves the
 * browser-established session can drive the API, which contract tests
 * (curl-based login) don't exercise.
 */
it('creates an album via the web service API using the browser session', function (): void {
    $page = H::loginAsAdmin($this);

    $response = H::wsCall($page, 'pwg.categories.add', ['name' => 'Browser Test Album ' . uniqid()]);

    expect($response['stat'])->toBe('ok');
    expect($response['result']['id'])->toBeGreaterThan(0);
});
