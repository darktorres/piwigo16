<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use LogicException;
use Piwigo\Cache\Event\InvalidateUserCache;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\PluginConfig\EventDispatcher;

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
        self::permissionsCachePool()->clear();
        self::effectivePermissionsCachePool()->clear();
        self::currentConfigService()->get()->confDeleteParam('count_orphans');
        self::eventDispatcher()->dispatch(new InvalidateUserCache());
    }

    /**
     * Same "container resolve, not a constructor param" reasoning as
     * Core/Logger.php's own pageState() -- ~30 real call sites across
     * Admin/Ws/Group/Users rule out threading CurrentConfigService as an
     * explicit param through every one. When `Kernel::isBooted()` is
     * false, the plain `new CurrentConfigService()` fallback's own get()
     * throws unconditionally (its `configService` is never `set()`),
     * exactly like the container-resolved instance would if reached
     * before Http\Middleware\ConfigBootstrapMiddleware has run -- no
     * observable behavior difference either way.
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

    /**
     * Same "container resolve, not a constructor param" reasoning as
     * currentConfigService() above -- but unlike that one, a pre-boot
     * fallback here stays genuinely functional rather than deferring to
     * an unconditional throw: pool identity carries no correctness risk
     * (see AbstractNamedCachePool's own docblock), so a fresh, throwaway
     * instance pointed at the same namespace still clears the real
     * shared backing.
     */
    private static function permissionsCachePool(): PermissionsCachePool
    {
        if (Kernel::isBooted()) {
            $permissionsCachePool = Kernel::container()->get(PermissionsCachePool::class);
            if (! $permissionsCachePool instanceof PermissionsCachePool) {
                throw new LogicException('Container returned an unexpected type for ' . PermissionsCachePool::class);
            }

            return $permissionsCachePool;
        }

        return new PermissionsCachePool(CacheFactory::create(namespace: 'piwigo.permissions', defaultLifetime: 30));
    }

    /**
     * Same reasoning as permissionsCachePool() above.
     */
    private static function effectivePermissionsCachePool(): EffectivePermissionsCachePool
    {
        if (Kernel::isBooted()) {
            $effectivePermissionsCachePool = Kernel::container()->get(EffectivePermissionsCachePool::class);
            if (! $effectivePermissionsCachePool instanceof EffectivePermissionsCachePool) {
                throw new LogicException('Container returned an unexpected type for ' . EffectivePermissionsCachePool::class);
            }

            return $effectivePermissionsCachePool;
        }

        return new EffectivePermissionsCachePool(CacheFactory::create(namespace: 'piwigo.effective_permissions', defaultLifetime: 30));
    }

    /**
     * Same reasoning as permissionsCachePool() above: a pre-boot fallback
     * stays genuinely functional (a fresh dispatcher with nothing
     * registered yet dispatches inertly, which is correct pre-boot -- no
     * real listener should exist that early anyway) rather than throwing.
     */
    private static function eventDispatcher(): EventDispatcher
    {
        if (Kernel::isBooted()) {
            $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
            if (! $eventDispatcher instanceof EventDispatcher) {
                throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
            }

            return $eventDispatcher;
        }

        return new EventDispatcher();
    }
}
