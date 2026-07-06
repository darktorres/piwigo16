<?php

declare(strict_types=1);

// P0 baseline: verify pest-plugin-arch is wired.
// Real structural rules land as src/Piwigo/ is built out from P6 onward.
test('arch plugin is available', function (): void {
    expect(class_exists(\Pest\Arch\PendingArchExpectation::class))->toBeTrue();
});
