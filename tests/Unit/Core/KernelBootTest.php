<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Psr\Container\ContainerInterface;

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
    // container()'s own return type already guarantees the class; what's
    // actually under test is that its "not booted" LogicException guard
    // doesn't fire once boot() has run.
    expect(static fn (): ContainerInterface => Kernel::container())->not->toThrow(LogicException::class);
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

test('boot is idempotent when re-called with the same Paths root by value', function (): void {
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test'));
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test')); // same root, different instance -- must not throw

    expect(Kernel::isBooted())->toBeTrue();
});

test('boot throws instead of silently keeping a stale Paths binding when re-called with a different root', function (): void {
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test-a'));

    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test-b'));
})->throws(LogicException::class, 'Kernel already booted with a different Paths root (/tmp/piwigo-kernel-boot-test-a/) -- reset the Kernel (call reset() first) to rebind it (e.g. between tests).');

test('boot does not throw when a Paths-less boot is later followed by a real Paths root', function (): void {
    // Mirrors Tests\Support\KernelContainerOverride::with(), which
    // installs an already-booted, deliberately Paths-less container via
    // reflection -- real bootstrap code running inside that override may
    // still legitimately call boot() with a genuine root afterward.
    Kernel::boot();
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test'));

    expect(Kernel::isBooted())->toBeTrue();
});

test('a rejected reboot leaves the original Paths binding intact, not corrupted', function (): void {
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test-a'));

    try {
        Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test-b'));
    } catch (LogicException) {
        // Expected -- see the test above for the throw itself.
    }

    $paths = CurrentPathsTestFactory::get();
    expect($paths->root)
        ->toBe('/tmp/piwigo-kernel-boot-test-a/');
});

test('reset also resets CurrentPaths, not just its own booted/container state', function (): void {
    // CurrentPaths is a pure shim reading Paths::class straight out of the
    // live container -- proves Kernel::reset() nulling the container is
    // enough on its own to make CurrentPathsTestFactory::get() throw again
    // too, with no separate cascade call needed.
    Kernel::boot(Paths::fromRoot('/tmp/piwigo-kernel-boot-test'));
    // Confirms the baseline works before reset -- proves the throw below
    // is really caused by reset(), not some pre-existing issue.
    expect(static fn (): Paths => CurrentPathsTestFactory::get())->not->toThrow(LogicException::class);

    Kernel::reset();

    expect(fn (): Paths => CurrentPathsTestFactory::get())->toThrow(LogicException::class);
});
