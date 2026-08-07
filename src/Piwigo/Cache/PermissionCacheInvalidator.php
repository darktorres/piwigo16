<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use LogicException;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;

/**
 * Clears the permission-related PSR-6 pools so a real access-affecting
 * mutation (category/group/image access change) takes effect on the very
 * next request, plus invalidates the cached orphan-image count
 * (`Image\ImageService::emptyLounge()`'s own `confUpdateParam`) since the
 * same mutations can affect it too. Lives here, not `Piwigo\Users`: real
 * callers span every layer, including `Piwigo\Group\GroupService`
 * (L2aCoreDomain) -- this class is L1Infrastructure, reachable from every
 * layer at once.
 */
final class PermissionCacheInvalidator
{
    public static function invalidate(): void
    {
        CachePools::permissions()->clear();
        CachePools::effectivePermissions()->clear();
        self::currentConfigService()->get()->confDeleteParam('count_orphans');
    }

    /**
     * Same "container resolve, not a constructor param" reasoning as
     * Core/Logger.php's own pageState() -- ~30 real call sites across
     * Admin/Ws/Group/Users rule out threading CurrentConfigService as an
     * explicit param through every one. When `Kernel::isBooted()` is
     * false, the plain `new CurrentConfigService()` fallback's own get()
     * throws unconditionally (its `configService` is never `set()`),
     * exactly like the container-resolved instance would if reached
     * before RequestBootstrap::connect() has run -- no observable
     * behavior difference either way.
     */
    private static function currentConfigService(): CurrentConfigService
    {
        if (Kernel::isBooted()) {
            $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
            if (! $currentConfigService instanceof CurrentConfigService) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
            }

            return $currentConfigService;
        }

        return new CurrentConfigService();
    }
}
