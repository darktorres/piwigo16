<?php

declare(strict_types=1);

use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Psr\Container\ContainerInterface;

// P7-scoped: Kernel::boot() only builds a bare, zero-definition container at
// this phase -- no Config/PageState/Lang/CurrentUser to assert against yet
// (those land in P16). This intentionally does NOT mirror the reference
// repo's fully-evolved KernelBootTest.php.

beforeEach(function (): void {
    Kernel::reset();
});

afterEach(function (): void {
    Kernel::reset();
});

test('isBooted is false before boot', function (): void {
    expect(Kernel::isBooted())->toBeFalse();
});

test('container throws before boot', function (): void {
    Kernel::container();
})->throws(LogicException::class, 'Kernel not booted — call Kernel::boot() first.');

test('boot is idempotent', function (): void {
    Kernel::boot();
    Kernel::boot(); // second call must not throw or corrupt state
    expect(Kernel::isBooted())->toBeTrue();
});

test('container returns a ContainerInterface after boot', function (): void {
    Kernel::boot();
    expect(Kernel::container())->toBeInstanceOf(ContainerInterface::class);
});

test('reset clears the booted flag', function (): void {
    Kernel::boot();
    expect(Kernel::isBooted())->toBeTrue();

    Kernel::reset();
    expect(Kernel::isBooted())->toBeFalse();
});

test('reset makes container throw again', function (): void {
    Kernel::boot();
    Kernel::reset();
    Kernel::container();
})->throws(LogicException::class);

test('reset also resets CurrentPaths, not just its own booted/container state', function (): void {
    // CurrentPaths (singleton/service-locator elimination campaign, Phase
    // 3) is a pure shim reading Paths::class straight out of the live
    // container -- proves Kernel::reset() nulling the container is enough
    // on its own to make CurrentPathsTestFactory::get() throw again too, with no
    // separate cascade call needed.
    Kernel::boot(Paths::fromRoot('/home/torres/piwigo17-rewrite'));
    expect(CurrentPathsTestFactory::get())->toBeInstanceOf(Paths::class);

    Kernel::reset();

    expect(fn () => CurrentPathsTestFactory::get())->toThrow(LogicException::class);
});
