<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Static accessor for the current request's `Paths` instance -- same
 * shape as this codebase's other per-request singleton facades
 * (`Piwigo\Config\CurrentConfigService`, `Piwigo\Core\CurrentLogger`,
 * `Piwigo\Template\CurrentTemplate`, `Piwigo\Users\CurrentUser`): a
 * self-managed static instance with `get()`/`set()`, not
 * constructor-injected DI.
 *
 * Legacy Coupling Retirement gap-closure (entry-shell define()/include
 * round): exists for the static-utility classes that used to read the raw
 * `PHPWG_ROOT_PATH`/`PWG_LOCAL_DIR` constants (e.g. `ThemeCatalog`,
 * `ImagePathHelper`) -- these have no constructor at all, so a required
 * `Paths` constructor or method parameter is impossible at those call
 * sites -- but every real entry point already computes a `Paths` before
 * `Kernel::boot()` runs, which is where this gets set (the one place
 * every bootstrap path -- HTTP, CLI, install -- already converges on a
 * real `Paths`).
 */
final class CurrentPaths
{
    private static ?Paths $instance = null;

    public static function get(): Paths
    {
        if (! self::$instance instanceof Paths) {
            throw new \LogicException('CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.');
        }

        return self::$instance;
    }

    public static function set(Paths $paths): void
    {
        self::$instance = $paths;
    }

    /**
     * Lets a caller that can tolerate "not booted yet" (e.g.
     * DeploymentPolicy::current(), reached from a pure-Unit test that
     * never bootstraps a real Paths) check before get() would throw --
     * mirrors CurrentConfigService's own isSet().
     */
    public static function isSet(): bool
    {
        return self::$instance instanceof Paths;
    }

    /**
     * Test-only, for test-isolation between requests -- mirrors
     * CurrentConfigService's/CurrentLogger's/CurrentTemplate's own
     * reset() methods.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
