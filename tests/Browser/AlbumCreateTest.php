<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Album creation via `POST /api/v1/categories`, driven through a real
 * authenticated browser session (proves cookie-based auth end-to-end) --
 * `H::createCategory()` is this suite's own helper onto that real
 * endpoint. No dedicated Unit/Integration test exists for
 * `CategoryCreateController` (see `tests/Browser/HistoryPageRendererTest.php`'s
 * own docblock for why the Browser suite is this codebase's established net
 * for `/api/v1` controllers), so this is also the response-shape coverage,
 * not just the session/auth proof.
 */
it('creates an album via the web service API using the browser session', function (): void {
    $page = H::asAdmin($this);

    $result = H::createCategory($page, [
        'name' => 'Browser Test Album ' . uniqid(),
    ]);

    expect($result['id'])->toBeGreaterThan(0);
});
