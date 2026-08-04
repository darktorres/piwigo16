<?php

declare(strict_types=1);

use Piwigo\Audit\AuditService;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Category\CategoryService;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\CoreDomainAccessor -- same "26/26 AdminAccessor" gap
 * shape, but for all 11 of this class's own remaining accessors (Phase 6
 * CoreDomainAccessor sub-batch converted every real caller of
 * imageVisibilityChecker()/categoryDefaultRenderer()/categoryCatsRenderer()
 * to constructor injection, leaving those 3 methods with zero remaining
 * callers -- deleted along with their own dedicated tests below): the
 * container really does resolve the right type on every real call site
 * elsewhere in the codebase, so the "unexpected type" \LogicException
 * guard itself had zero coverage. See AdminAccessorTest.php's own
 * docblock for the KernelContainerOverride rationale.
 */
afterEach(function (): void {
    \Piwigo\Core\Kernel::reset();
    \Piwigo\Template\CurrentTemplate::current()->reset();
    \Piwigo\Config\CurrentConfig::reset();
});

test('every accessor returns its real, correctly-typed instance from a real container, without throwing', function (): void {
    // Same "wrong-type tests below only prove the throw side" gap as
    // AdminAccessorTest.php's own identical test -- see that file's
    // docblock for the full rationale, including why Kernel::boot() needs
    // a real Paths passed in.
    \Piwigo\Core\Kernel::reset();
    \Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));
    \Piwigo\Config\CurrentConfig::setDataLocation('data/');
    \Piwigo\Config\CurrentConfig::setDataDirChecked('1');
    \Piwigo\Template\CurrentTemplate::current()->set(new \Piwigo\Template\Template(sys_get_temp_dir()));

    expect(CoreDomainAccessor::permissionService())->toBeInstanceOf(PermissionService::class);
    expect(CoreDomainAccessor::categoryService())->toBeInstanceOf(CategoryService::class);
    expect(CoreDomainAccessor::tagService())->toBeInstanceOf(TagService::class);
    expect(CoreDomainAccessor::imageService())->toBeInstanceOf(ImageService::class);
    expect(CoreDomainAccessor::userService())->toBeInstanceOf(UserService::class);
    expect(CoreDomainAccessor::groupService())->toBeInstanceOf(GroupService::class);
    expect(CoreDomainAccessor::passwordService())->toBeInstanceOf(PasswordService::class);
    expect(CoreDomainAccessor::authService())->toBeInstanceOf(AuthService::class);
    expect(CoreDomainAccessor::preferencesService())->toBeInstanceOf(PreferencesService::class);
    expect(CoreDomainAccessor::apiKeyService())->toBeInstanceOf(ApiKeyService::class);
    expect(CoreDomainAccessor::auditService())->toBeInstanceOf(AuditService::class);
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

test('groupService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        GroupService::class,
        static fn () => CoreDomainAccessor::groupService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . GroupService::class);

test('passwordService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PasswordService::class,
        static fn () => CoreDomainAccessor::passwordService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PasswordService::class);

test('authService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        AuthService::class,
        static fn () => CoreDomainAccessor::authService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . AuthService::class);

test('preferencesService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PreferencesService::class,
        static fn () => CoreDomainAccessor::preferencesService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PreferencesService::class);

test('apiKeyService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ApiKeyService::class,
        static fn () => CoreDomainAccessor::apiKeyService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ApiKeyService::class);

test('auditService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        AuditService::class,
        static fn () => CoreDomainAccessor::auditService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . AuditService::class);
