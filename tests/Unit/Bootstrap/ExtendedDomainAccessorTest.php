<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityService;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Metadata\MetadataService;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\ExtendedDomainAccessor -- activityService() and
 * metadataService() are its only accessors with real, remaining callers:
 * activityService()'s only caller is Admin/Install/{InstallWizard,
 * InstallService}.php's genuinely-static-context install flow;
 * metadataService()'s only caller is config/messenger.php (outside
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

test('activityService resolves a real ActivityService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    // activityService()'s own return type already guarantees the resolved
    // instance's class (a mismatch would throw a TypeError first) -- what's
    // actually under test is that the call doesn't hit its internal
    // "Container returned an unexpected type" guard.
    expect(static fn () => ExtendedDomainAccessor::activityService())->not->toThrow(Throwable::class);
});

test('activityService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ActivityService::class,
        static fn () => ExtendedDomainAccessor::activityService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ActivityService::class);

test('metadataService resolves a real MetadataService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    // Same rationale as activityService() above: the return type already
    // guarantees the class, so the real thing under test is that this
    // doesn't hit the internal "unexpected type" guard.
    expect(static fn () => ExtendedDomainAccessor::metadataService())->not->toThrow(Throwable::class);
});

test('metadataService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MetadataService::class,
        static fn () => ExtendedDomainAccessor::metadataService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MetadataService::class);
