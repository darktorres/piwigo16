<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is dispatched through ws.php" marker -- Legacy Coupling
 * Retirement gap-closure (entry-shell define()/include round, Part 0b),
 * typed replacement for the raw IN_WS constant (`defined('IN_WS')`
 * reads). Same shape as Piwigo\Core\AdminContext -- see AdminContext's
 * own docblock for why reset() exists.
 *
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3): the value is fixed once, at container-build time
 * (`Piwigo\Core\Container::build()`, threaded from `public/ws.php`, the one
 * entry-shell file that knows it's really being dispatched through ws.php),
 * never mutated afterward during a request -- no "current instance"
 * concept needed at all (same lesson as the Phase 0 `CurrentPersistentCache`
 * pilot). isActiveStatic() is a `@deprecated` transitional bridge for
 * callers not yet converted to constructor injection.
 */
final class WsContext
{
    public function __construct(
        private readonly bool $active = false,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection -- Piwigo\Admin\PluginMaintain (a base class
     * extended by every third-party plugin's own maintain class -- its
     * constructor signature isn't this campaign's to change),
     * Piwigo\Url\UrlService (still manually `new`'d at dozens of call
     * sites, Phase 6), and Piwigo\Admin\Upload\UploadService::
     * addUploadedFile() (reachable from the still-static Ws\PwgImages
     * dispatch layer, Phase 10) all keep using this shim. Gracefully falls
     * back to false (the same value an unset instance already defaults
     * to) when Kernel hasn't booted -- these callers are reached by many
     * Unit tests that never boot a container at all, matching the
     * `InstallationFlag::isActiveStatic()` shim's own established
     * reasoning. Delete once `grep -rn "WsContext::isActiveStatic("`
     * outside tests/ returns nothing.
     */
    public static function isActiveStatic(): bool
    {
        if (! Kernel::isBooted()) {
            return false;
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->isActive();
    }
}
