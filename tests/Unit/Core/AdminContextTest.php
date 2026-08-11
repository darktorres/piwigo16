<?php

declare(strict_types=1);

use Piwigo\Core\AdminContext;

/**
 * Container-shared, immutable value -- each test constructs its own
 * fresh instance directly; no reset() needed for the instance API.
 */
test('isActive reflects the value given at construction', function (): void {
    expect(new AdminContext()->isActive())
        ->toBeFalse()
        ->and(new AdminContext(false)->isActive())
        ->toBeFalse()
        ->and(new AdminContext(true)->isActive())
        ->toBeTrue();
});
