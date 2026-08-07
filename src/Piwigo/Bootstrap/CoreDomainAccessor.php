<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Kernel;
use Piwigo\Image\ImageService;
use Piwigo\Users\UserService;

/**
 * Typed accessors to container-resolved L2aCoreDomain services, for
 * L4Integration callers (Admin/Command/Controller/Ws) that can't call
 * `Kernel::container()` directly (arch-test-restricted to `Bootstrap/` +
 * root `index.php`, `tests/Arch/StructuralTest.php`).
 *
 * Not consumed by L2a/L2b/L3-namespace classes themselves -- `Bootstrap`
 * is L4Integration, and deptrac's ruleset only allows a layer to depend
 * on layers *below* it, so an L2a/L2b/L3 class calling into this accessor
 * would be an upward-dependency violation. Same-layer or downward
 * construction within those layers (e.g. `Category\CategoryService`
 * constructing `Permission\PermissionService` directly) uses plain
 * `new()` instead.
 *
 * userService()'s only callers are Admin/Install/{InstallService,
 * InstallWizard}.php's own static-context install flow; imageService()'s
 * only caller is config/messenger.php (outside `src/Piwigo`, and
 * deliberately outside the `Kernel::container()` arch-test boundary too,
 * per that file's own docblock).
 */
final class CoreDomainAccessor
{
    public static function userService(): UserService
    {
        $service = Kernel::container()->get(UserService::class);
        if (! $service instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }
        return $service;
    }

    public static function imageService(): ImageService
    {
        $service = Kernel::container()->get(ImageService::class);
        if (! $service instanceof ImageService) {
            throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
        }
        return $service;
    }

    public static function accessControl(): AccessControl
    {
        $service = Kernel::container()->get(AccessControl::class);
        if (! $service instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }
        return $service;
    }
}
