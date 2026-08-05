<?php

declare(strict_types=1);

use Piwigo\Core\AdminContext;

/**
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3) -- each test constructs its own fresh instance
 * directly; no reset() needed for the instance API. Its own former
 * isActiveStatic() transitional shim (and this file's own 2 dedicated
 * tests for its Kernel::isBooted() branches) is gone -- Page\
 * PageHeaderRenderer/Bootstrap\RedirectService (its remaining callers) now
 * resolve this via their own private lazy helpers instead (singleton/
 * service-locator elimination campaign, Phase 11 sub-phase 11G).
 */
test('isActive reflects the value given at construction', function (): void {
    expect(new AdminContext()->isActive())->toBeFalse()
        ->and(new AdminContext(false)->isActive())->toBeFalse()
        ->and(new AdminContext(true)->isActive())->toBeTrue();
});
