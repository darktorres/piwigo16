<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\WsContext;

/**
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3) -- each test constructs its own fresh instance
 * directly; no reset() needed for the instance API. isActiveStatic()'s own
 * Kernel::isBooted() branches are covered separately below.
 */
test('isActive reflects the value given at construction', function (): void {
    expect(new WsContext()->isActive())->toBeFalse()
        ->and(new WsContext(false)->isActive())->toBeFalse()
        ->and(new WsContext(true)->isActive())->toBeTrue();
});

test('isActiveStatic falls back to false when Kernel has not booted', function (): void {
    expect(Kernel::isBooted())->toBeFalse();

    expect(WsContext::isActiveStatic())->toBeFalse();
});

test('isActiveStatic delegates to the container-shared instance once Kernel has booted', function (): void {
    Kernel::boot(isWs: true);

    expect(WsContext::isActiveStatic())->toBeTrue();

    Kernel::reset();
});
