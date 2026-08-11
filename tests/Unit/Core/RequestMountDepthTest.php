<?php

declare(strict_types=1);

use Piwigo\Core\RequestMountDepth;

/**
 * Container-shared, immutable value -- each test constructs its own fresh
 * instance directly; no reset() needed for the instance API.
 * Piwigo\Auth\CookieService is the only caller, resolving it via its own
 * private lazy requestMountDepth() helper.
 */
test('current reflects the value given at construction', function (): void {
    expect(new RequestMountDepth()->current())
        ->toBe(0)
        ->and(new RequestMountDepth(0)->current())
        ->toBe(0)
        ->and(new RequestMountDepth(1)->current())
        ->toBe(1)
        ->and(new RequestMountDepth(2)->current())
        ->toBe(2);
});
