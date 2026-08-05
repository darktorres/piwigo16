<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Category\CategoryService;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\CoreDomainAccessor -- same "26/26 AdminAccessor" gap
 * shape, but for this class's own remaining accessors. Phase 6's own
 * CoreDomainAccessor sub-batch converted every real caller of
 * imageVisibilityChecker()/categoryDefaultRenderer()/categoryCatsRenderer()
 * to constructor injection, leaving those 3 methods with zero remaining
 * callers -- deleted along with their own dedicated tests. Phase 10's
 * PwgUsers sub-batch emptied 6 more (groupService()/passwordService()/
 * authService()/preferencesService()/apiKeyService()/auditService()) once
 * PwgUsers converted its own manual UserService rebuild + xService()
 * helpers to real constructor injection -- deleted the same way, leaving
 * the 5 below (imageService()/tagService() still have a real
 * Ws/PwgImages.php-locked caller; userService() still has real
 * Admin/Install/{InstallWizard,InstallService}.php callers;
 * permissionService()/categoryService() still have real
 * Ws/PwgImages.php-locked callers too).
 *
 * The container really does resolve the right type on every real call site
 * elsewhere in the codebase, so the "unexpected type" \LogicException
 * guard itself had zero coverage. See AdminAccessorTest.php's own
 * docblock for the KernelContainerOverride rationale.
 */
afterEach(function (): void {
    \Piwigo\Core\Kernel::reset();
    \Piwigo\Template\CurrentTemplate::current()->reset();
    \Piwigo\Config\CurrentConfig::current()->reset();
});

test('every accessor returns its real, correctly-typed instance from a real container, without throwing', function (): void {
    // Same "wrong-type tests below only prove the throw side" gap as
    // AdminAccessorTest.php's own identical test -- see that file's
    // docblock for the full rationale, including why Kernel::boot() needs
    // a real Paths passed in.
    \Piwigo\Core\Kernel::reset();
    \Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));
    $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
    if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
        throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
    }
    $currentConfig->setDataLocation('data/');
    $currentConfig->setDataDirChecked('1');
    \Piwigo\Template\CurrentTemplate::current()->set(new \Piwigo\Template\Template(sys_get_temp_dir()));

    expect(CoreDomainAccessor::permissionService())->toBeInstanceOf(PermissionService::class);
    expect(CoreDomainAccessor::categoryService())->toBeInstanceOf(CategoryService::class);
    expect(CoreDomainAccessor::tagService())->toBeInstanceOf(TagService::class);
    expect(CoreDomainAccessor::imageService())->toBeInstanceOf(ImageService::class);
    expect(CoreDomainAccessor::userService())->toBeInstanceOf(UserService::class);
});

test('permissionService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PermissionService::class,
        static fn () => CoreDomainAccessor::permissionService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PermissionService::class);

test('categoryService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CategoryService::class,
        static fn () => CoreDomainAccessor::categoryService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CategoryService::class);

test('tagService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        TagService::class,
        static fn () => CoreDomainAccessor::tagService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . TagService::class);

test('imageService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ImageService::class,
        static fn () => CoreDomainAccessor::imageService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ImageService::class);

test('userService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UserService::class,
        static fn () => CoreDomainAccessor::userService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UserService::class);
