<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Audit\AuditService;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Category\CategoryService;
use Piwigo\Core\Kernel;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;

/**
 * DI-migration follow-on to gap-closure Stage 4: typed accessors to
 * container-resolved L2aCoreDomain services, for L4Integration callers
 * (Admin/Command/Controller/Ws) that can't call `Kernel::container()`
 * directly (arch-test-restricted to `Bootstrap/` + root `index.php`,
 * `tests/Arch/StructuralTest.php`) -- same shape as
 * `Bootstrap\RedirectService::userService()` and this session's own
 * `Bootstrap\PresentationAccessor`.
 *
 * Deliberately NOT consumed by L2a/L2b/L3-namespace classes themselves --
 * `Bootstrap` is L4Integration, and deptrac's ruleset only allows a layer
 * to depend on layers *below* it, so an L2a/L2b/L3 class calling into this
 * accessor would be a real upward-dependency violation. Same-layer or
 * downward construction within those layers (e.g. `Category\
 * CategoryService` constructing `Permission\PermissionService` directly)
 * was already deptrac-compliant before this initiative and stays as
 * plain `new()`.
 */
final class CoreDomainAccessor
{
    public static function permissionService(): PermissionService
    {
        $service = Kernel::container()->get(PermissionService::class);
        if (! $service instanceof PermissionService) {
            throw new \LogicException('Container returned an unexpected type for ' . PermissionService::class);
        }
        return $service;
    }

    public static function categoryService(): CategoryService
    {
        $service = Kernel::container()->get(CategoryService::class);
        if (! $service instanceof CategoryService) {
            throw new \LogicException('Container returned an unexpected type for ' . CategoryService::class);
        }
        return $service;
    }

    public static function tagService(): TagService
    {
        $service = Kernel::container()->get(TagService::class);
        if (! $service instanceof TagService) {
            throw new \LogicException('Container returned an unexpected type for ' . TagService::class);
        }
        return $service;
    }

    public static function imageService(): ImageService
    {
        $service = Kernel::container()->get(ImageService::class);
        if (! $service instanceof ImageService) {
            throw new \LogicException('Container returned an unexpected type for ' . ImageService::class);
        }
        return $service;
    }

    public static function userService(): UserService
    {
        $service = Kernel::container()->get(UserService::class);
        if (! $service instanceof UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . UserService::class);
        }
        return $service;
    }

    public static function groupService(): GroupService
    {
        $service = Kernel::container()->get(GroupService::class);
        if (! $service instanceof GroupService) {
            throw new \LogicException('Container returned an unexpected type for ' . GroupService::class);
        }
        return $service;
    }

    public static function passwordService(): PasswordService
    {
        $service = Kernel::container()->get(PasswordService::class);
        if (! $service instanceof PasswordService) {
            throw new \LogicException('Container returned an unexpected type for ' . PasswordService::class);
        }
        return $service;
    }

    public static function authService(): AuthService
    {
        $service = Kernel::container()->get(AuthService::class);
        if (! $service instanceof AuthService) {
            throw new \LogicException('Container returned an unexpected type for ' . AuthService::class);
        }
        return $service;
    }

    public static function preferencesService(): PreferencesService
    {
        $service = Kernel::container()->get(PreferencesService::class);
        if (! $service instanceof PreferencesService) {
            throw new \LogicException('Container returned an unexpected type for ' . PreferencesService::class);
        }
        return $service;
    }

    public static function apiKeyService(): ApiKeyService
    {
        $service = Kernel::container()->get(ApiKeyService::class);
        if (! $service instanceof ApiKeyService) {
            throw new \LogicException('Container returned an unexpected type for ' . ApiKeyService::class);
        }
        return $service;
    }

    public static function auditService(): AuditService
    {
        $service = Kernel::container()->get(AuditService::class);
        if (! $service instanceof AuditService) {
            throw new \LogicException('Container returned an unexpected type for ' . AuditService::class);
        }
        return $service;
    }
}
