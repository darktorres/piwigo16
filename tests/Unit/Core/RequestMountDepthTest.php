<?php

declare(strict_types=1);

use Piwigo\Core\RequestMountDepth;

/**
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3) -- each test constructs its own fresh instance
 * directly; no reset() needed for the instance API. Its own former
 * currentStatic() transitional shim (and this file's own 2 dedicated tests
 * for its Kernel::isBooted() branches) is gone -- Piwigo\Auth\CookieService
 * (its one remaining caller) now resolves this via its own private lazy
 * requestMountDepth() helper instead (singleton/service-locator elimination
 * campaign, Phase 11 sub-phase 11G).
 */
test('current reflects the value given at construction', function (): void {
    expect(new RequestMountDepth()->current())->toBe(0)
        ->and(new RequestMountDepth(0)->current())->toBe(0)
        ->and(new RequestMountDepth(1)->current())->toBe(1)
        ->and(new RequestMountDepth(2)->current())->toBe(2);
});
