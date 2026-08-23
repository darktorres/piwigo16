<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Permission\PermissionService;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\CoreDomainAccessor -- userService() and
 * permissionService() are its only accessors with real, remaining
 * callers: userService()'s only caller is Admin/Install/{InstallWizard,
 * InstallService}.php's genuinely-static-context install flow;
 * permissionService()'s only caller is config/messenger.php (outside
 * `src/Piwigo`, and deliberately outside the `Kernel::container()`
 * arch-test boundary too).
 *
 * The container always resolves the right type at every real call site, so
 * the "unexpected type" \LogicException guard on each accessor is only
 * reachable via KernelContainerOverride's deliberate wrong-type binding
 * (see AdminAccessorTest.php's own docblock for that rationale).
 */
afterEach(function (): void {
    Kernel::reset();
});

test('userService resolves a real UserService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    // userService()'s own return type already guarantees the resolved
    // instance's class (a mismatch would throw a TypeError first) -- what's
    // actually under test is that the call doesn't hit its internal
    // "Container returned an unexpected type" guard.
    expect(static fn (): UserService => CoreDomainAccessor::userService())->not->toThrow(Throwable::class);
});

test('userService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UserService::class,
        static fn (): UserService => CoreDomainAccessor::userService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UserService::class);

test('permissionService resolves a real PermissionService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    // Same rationale as userService() above: the return type already
    // guarantees the class, so the real thing under test is that this
    // doesn't hit the internal "unexpected type" guard.
    expect(static fn (): PermissionService => CoreDomainAccessor::permissionService())->not->toThrow(Throwable::class);
});

test('permissionService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PermissionService::class,
        static fn (): PermissionService => CoreDomainAccessor::permissionService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PermissionService::class);
