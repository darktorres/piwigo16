<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\RequestMountDepth;

/**
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3) -- each test constructs its own fresh instance
 * directly; no reset() needed for the instance API. currentStatic()'s own
 * Kernel::isBooted() branches are covered separately below.
 */
test('current reflects the value given at construction', function (): void {
    expect(new RequestMountDepth()->current())->toBe(0)
        ->and(new RequestMountDepth(0)->current())->toBe(0)
        ->and(new RequestMountDepth(1)->current())->toBe(1)
        ->and(new RequestMountDepth(2)->current())->toBe(2);
});

test('currentStatic falls back to 0 when Kernel has not booted', function (): void {
    expect(Kernel::isBooted())->toBeFalse();

    expect(RequestMountDepth::currentStatic())->toBe(0);
});

test('currentStatic delegates to the container-shared instance once Kernel has booted', function (): void {
    Kernel::boot(mountDepth: 1);

    expect(RequestMountDepth::currentStatic())->toBe(1);

    Kernel::reset();
});
