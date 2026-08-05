<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\Kernel;
use Piwigo\Image\ImageService;
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
 *
 * Singleton/service-locator elimination campaign, Phase 10: PwgImages's
 * own conversion (the last Ws/Pwg* class using this accessor) emptied
 * permissionService()/categoryService()/tagService() -- deleted along with
 * their dedicated tests. userService() and imageService() both stay:
 * userService()'s only remaining callers are Admin/Install/{InstallService,
 * InstallWizard}.php's own genuinely-static-context install flow;
 * imageService()'s only remaining caller is config/messenger.php (outside
 * `src/Piwigo`, and deliberately outside the `Kernel::container()`
 * arch-test boundary too, per that file's own docblock) -- a real, missed
 * caller the initial "grep src/" sweep for this trim didn't catch, found
 * only once the full-repo PHPStan pass ran.
 */
final class CoreDomainAccessor
{
    public static function userService(): UserService
    {
        $service = Kernel::container()->get(UserService::class);
        if (! $service instanceof UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . UserService::class);
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

    public static function accessControl(): AccessControl
    {
        $service = Kernel::container()->get(AccessControl::class);
        if (! $service instanceof AccessControl) {
            throw new \LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }
        return $service;
    }
}
