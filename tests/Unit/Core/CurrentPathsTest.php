<?php

declare(strict_types=1);

use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Pure transitional shim now (singleton/service-locator elimination
 * campaign, Phase 3) -- no more independent static state of its own, so
 * "not initialised" now means "Kernel hasn't booted (with a real Paths)",
 * not "CurrentPaths::set() was never called".
 */
test('get throws when Kernel has not booted at all', function (): void {
    expect(Kernel::isBooted())->toBeFalse();
    expect(CurrentPaths::isSet())->toBeFalse();

    expect(fn () => CurrentPaths::get())->toThrow(
        \LogicException::class,
        'CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.',
    );
});

test('get throws when Kernel has booted without a real Paths', function (): void {
    Kernel::boot();

    try {
        expect(CurrentPaths::isSet())->toBeFalse();

        expect(fn () => CurrentPaths::get())->toThrow(
            \LogicException::class,
            'CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.',
        );
    } finally {
        Kernel::reset();
    }
});

test('get returns the Paths bound in the container, and isSet reflects it', function (): void {
    $paths = Paths::fromRoot('/tmp/piwigo-current-paths-test');
    Kernel::boot($paths);

    try {
        expect(CurrentPaths::isSet())->toBeTrue()
            ->and(CurrentPaths::get())->toBe($paths);
    } finally {
        Kernel::reset();
    }
});

test('isSet/get go back to the unbooted behavior after Kernel::reset()', function (): void {
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-current-paths-test'));
    expect(CurrentPaths::isSet())->toBeTrue();

    Kernel::reset();

    expect(CurrentPaths::isSet())->toBeFalse();
    expect(fn () => CurrentPaths::get())->toThrow(\LogicException::class);
});
