<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Image\ImageService;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\CoreDomainAccessor -- userService() and imageService()
 * are its only accessors with real, remaining callers: userService()'s
 * only caller is Admin/Install/{InstallWizard,InstallService}.php's
 * genuinely-static-context install flow; imageService()'s only caller is
 * config/messenger.php (outside `src/Piwigo`, and deliberately outside the
 * `Kernel::container()` arch-test boundary too).
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

    expect(CoreDomainAccessor::userService())->toBeInstanceOf(UserService::class);
});

test('userService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UserService::class,
        static fn () => CoreDomainAccessor::userService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UserService::class);

test('imageService resolves a real ImageService from the container', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    expect(CoreDomainAccessor::imageService())->toBeInstanceOf(ImageService::class);
});

test('imageService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ImageService::class,
        static fn () => CoreDomainAccessor::imageService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ImageService::class);
