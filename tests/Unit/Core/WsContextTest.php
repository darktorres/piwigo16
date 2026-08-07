<?php

declare(strict_types=1);

use Piwigo\Core\WsContext;

/**
 * Container-shared, immutable value -- each test constructs its own fresh
 * instance directly; no reset() needed for the instance API.
 */
test('isActive reflects the value given at construction', function (): void {
    expect(new WsContext()->isActive())->toBeFalse()
        ->and(new WsContext(false)->isActive())->toBeFalse()
        ->and(new WsContext(true)->isActive())->toBeTrue();
});
