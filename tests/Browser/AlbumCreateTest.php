<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Album creation via `POST /api/v1/categories`, driven through a real
 * authenticated browser session (proves cookie-based auth end-to-end) --
 * `H::wsCall('pwg.categories.add', ...)` is this suite's own dispatcher
 * onto that real endpoint. No dedicated Unit/Integration test exists for
 * `CategoryCreateController` (see `tests/Browser/HistoryPageRendererTest.php`'s
 * own docblock for why the Browser suite is this codebase's established net
 * for `/api/v1` controllers), so this is also the response-shape coverage,
 * not just the session/auth proof.
 */
it('creates an album via the web service API using the browser session', function (): void {
    $page = H::asAdmin($this);

    $response = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Browser Test Album ' . uniqid(),
    ]);

    expect($response['stat'])->toBe('ok');

    $result = $response['result'];
    if (! is_array($result)) {
        throw new RuntimeException('Expected pwg.categories.add "result" to be an array, got ' . get_debug_type($result));
    }

    expect($result['id'])->toBeGreaterThan(0);
});
