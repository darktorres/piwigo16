<?php

declare(strict_types=1);

use Piwigo\Core\ApiContext;

/**
 * Container-shared, immutable value -- each test constructs its own fresh
 * instance directly; no reset() needed for the instance API.
 */
test('isActive reflects the value given at construction', function (): void {
    expect(new ApiContext()->isActive())
        ->toBeFalse()
        ->and(new ApiContext(false)->isActive())
        ->toBeFalse()
        ->and(new ApiContext(true)->isActive())
        ->toBeTrue();
});
