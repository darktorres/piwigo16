<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentPathsTestFactory;

/**
 * CurrentPathsTestFactory reproduces CurrentPaths::get()/isSet() behavior
 * for test call sites (see its own docblock). "not initialised" means
 * "Kernel hasn't booted (with a real Paths)", not "CurrentPaths::set() was
 * never called" -- this class has no independent static state of its own.
 */
test('CurrentPathsTestFactory::get throws when Kernel has not booted at all', function (): void {
    expect(Kernel::isBooted())->toBeFalse();
    expect(CurrentPathsTestFactory::isSet())->toBeFalse();

    expect(fn () => CurrentPathsTestFactory::get())->toThrow(
        LogicException::class,
        'CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.',
    );
});

test('CurrentPathsTestFactory::get throws when Kernel has booted without a real Paths', function (): void {
    Kernel::boot();

    try {
        expect(CurrentPathsTestFactory::isSet())->toBeFalse();

        expect(fn () => CurrentPathsTestFactory::get())->toThrow(
            LogicException::class,
            'CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.',
        );
    } finally {
        Kernel::reset();
    }
});

test('CurrentPathsTestFactory::get returns the Paths bound in the container, and isSet reflects it', function (): void {
    $paths = Paths::fromRoot('/tmp/piwigo-current-paths-test');
    Kernel::boot($paths);

    try {
        expect(CurrentPathsTestFactory::isSet())->toBeTrue()
            ->and(CurrentPathsTestFactory::get())->toBe($paths);
    } finally {
        Kernel::reset();
    }
});

test('CurrentPathsTestFactory::isSet/get go back to the unbooted behavior after Kernel::reset()', function (): void {
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-current-paths-test'));
    expect(CurrentPathsTestFactory::isSet())->toBeTrue();

    Kernel::reset();

    expect(CurrentPathsTestFactory::isSet())->toBeFalse();
    expect(fn () => CurrentPathsTestFactory::get())->toThrow(LogicException::class);
});
