<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;
use Psr\Container\ContainerExceptionInterface;

/**
 * `Paths` is a plain, already-container-bound immutable value (see its own
 * docblock and `Piwigo\Core\Container`'s own conditional `Paths::class =>
 * $paths` binding) -- most consumers should take it via constructor
 * injection directly, not through this class.
 *
 * Singleton/service-locator elimination campaign, Phase 3: this class used
 * to be a genuine self-managed static singleton (its own `private static
 * ?Paths $instance`, written by `Kernel::boot()`, read by `get()`). It is
 * now a pure `@deprecated` transitional bridge for the static-utility
 * classes (e.g. `Lang`, `Piwigo\Ws\PwgCore`/`PwgImages`, `ThemeCatalog`,
 * `ImagePathHelper`, `Image\SrcImage`) that have no constructor at all to
 * receive `Paths` through, and for the handful of instance classes still
 * manually `new`'d at dozens of call sites (`Template`, `MailService`,
 * `Image\DerivativeImage`, `Template\ScriptLoader`, Phase 6) -- both
 * categories can't take constructor injection yet. `get()`/`isSet()` keep
 * their original names (no `Static` suffix): unlike every other shimmed
 * class in this campaign, there is no competing real instance method to
 * disambiguate from, since this class was never converted to an instance
 * itself -- `Paths` is the real target.
 *
 * Delete once `grep -rn "CurrentPaths::"` outside tests/ returns nothing
 * (i.e. every remaining caller has been converted to constructor-injected
 * `Paths`).
 */
final class CurrentPaths
{
    /**
     * @deprecated transitional bridge -- see this class's own docblock.
     */
    public static function get(): Paths
    {
        if (! Kernel::isBooted()) {
            throw new LogicException('CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.');
        }

        // NOT has() (PSR-11) -- confirmed live: PHP-DI's has() returns true
        // for ANY concrete, existing class name (it reports "autowiring
        // could theoretically attempt this", not "a real definition/value
        // is bound"), unlike the interface-typed collaborators every other
        // shim in this campaign checks with has() (an interface can never
        // be autowired, so has() is reliable there). Paths::class is only
        // ever bound to a real value when Kernel::boot() actually received
        // one (see Piwigo\Core\Container's own conditional binding) -- when
        // it wasn't, get() attempts to autowire Paths's all-required-string
        // constructor and throws PHP-DI's own InvalidDefinition, caught
        // here and converted into this method's own clear message instead.
        try {
            $paths = Kernel::container()->get(Paths::class);
        } catch (ContainerExceptionInterface) {
            throw new LogicException('CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.');
        }

        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    /**
     * Lets a caller that can tolerate "not booted yet" (e.g.
     * DeploymentPolicy::current(), reached from a pure-Unit test that
     * never bootstraps a real Paths) check before get() would throw --
     * mirrors CurrentConfigService's own isSet().
     *
     * @deprecated transitional bridge -- see this class's own docblock.
     */
    public static function isSet(): bool
    {
        if (! Kernel::isBooted()) {
            return false;
        }

        // See get()'s own comment -- has() is unreliable for a concrete
        // class like Paths, so this checks the same way: a real get()
        // attempt, catching PHP-DI's own exception when nothing was bound.
        try {
            return Kernel::container()->get(Paths::class) instanceof Paths;
        } catch (ContainerExceptionInterface) {
            return false;
        }
    }
}
