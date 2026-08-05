<?php

declare(strict_types=1);

use Piwigo\Core\WsContext;

/**
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3) -- each test constructs its own fresh instance
 * directly; no reset() needed for the instance API. Its own
 * isActiveStatic() transitional bridge was deleted in Phase 11 sub-phase
 * 11G once its last real caller converted to real constructor injection.
 */
test('isActive reflects the value given at construction', function (): void {
    expect(new WsContext()->isActive())->toBeFalse()
        ->and(new WsContext(false)->isActive())->toBeFalse()
        ->and(new WsContext(true)->isActive())->toBeTrue();
});
