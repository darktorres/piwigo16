<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is dispatched through admin.php/admin/popuphelp.php"
 * marker -- Legacy Coupling Retirement gap-closure (entry-shell
 * define()/include round, Part 0b), typed replacement for the raw
 * IN_ADMIN constant (`defined('IN_ADMIN')`/bare `IN_ADMIN` reads). SEC-60
 * forbids define() inside src/Piwigo, so the constant stays confined to
 * the 2 entry-shell files that set it; everything else reads this instead.
 *
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3): the value is fixed once, at container-build time
 * (`Piwigo\Core\Container::build()`, threaded from `public/admin.php`/
 * `public/admin/popuphelp.php`, the 2 entry-shell files that know they're
 * really being dispatched through the admin area), never mutated
 * afterward during a request -- no "current instance" concept needed at
 * all (same lesson as the Phase 0 `CurrentPersistentCache` pilot).
 * isActiveStatic() is a `@deprecated` transitional bridge for callers not
 * yet converted to constructor injection.
 */
final class AdminContext
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
     * constructor injection -- Piwigo\Page\PageHeaderRenderer and
     * Piwigo\Bootstrap\RedirectService both deliberately have no
     * constructor at all (early-crash-fallback shape, Phase 6), and
     * Piwigo\Template\Template is still manually `new`'d at dozens of call
     * sites (Phase 6/8). Gracefully falls back to false (the same value an
     * unset instance already defaults to) when Kernel hasn't booted --
     * these callers are reached by many Unit tests that never boot a
     * container at all, matching the `InstallationFlag::isActiveStatic()`
     * shim's own established reasoning. Delete once `grep -rn
     * "AdminContext::isActiveStatic("` outside tests/ returns nothing.
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
