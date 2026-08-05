<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Image\ImageService;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\CoreDomainAccessor -- same "26/26 AdminAccessor" gap
 * shape, but for this class's own remaining 2 accessors. Phase 6's own
 * CoreDomainAccessor sub-batch converted every real caller of
 * imageVisibilityChecker()/categoryDefaultRenderer()/categoryCatsRenderer()
 * to constructor injection, leaving those 3 methods with zero remaining
 * callers -- deleted along with their own dedicated tests. Phase 10's
 * PwgUsers sub-batch emptied 6 more (groupService()/passwordService()/
 * authService()/preferencesService()/apiKeyService()/auditService());
 * Phase 10's PwgImages sub-batch emptied 3 more
 * (permissionService()/categoryService()/tagService()) once PwgImages,
 * their last remaining `src/Piwigo` caller, converted to real constructor
 * injection -- deleted the same way. userService() and imageService() both
 * stay: userService()'s only remaining callers are
 * Admin/Install/{InstallWizard,InstallService}.php's own
 * genuinely-static-context install flow; imageService()'s only remaining
 * caller is config/messenger.php (outside `src/Piwigo`, and deliberately
 * outside the `Kernel::container()` arch-test boundary too) -- a real,
 * missed caller the initial PwgImages-sub-batch trim didn't catch (its own
 * "grep src/" sweep doesn't reach `config/`), found only once the full-repo
 * PHPStan pass ran.
 *
 * The container really does resolve the right type on every real call site
 * elsewhere in the codebase, so the "unexpected type" \LogicException
 * guard itself had zero coverage. See AdminAccessorTest.php's own
 * docblock for the KernelContainerOverride rationale.
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
