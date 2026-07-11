<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
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
