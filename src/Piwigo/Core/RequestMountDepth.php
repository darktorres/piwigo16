<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * How many directory levels deeper than a normal root-level entry file the
 * *current* request's entry point sits (0 for every entry file except
 * `admin/popuphelp.php`, which sets 1) -- the same fact
 * `Piwigo\Routing\Router::MOUNT_DEPTH_ATTRIBUTE` already carries as a
 * request attribute for inbound route matching (see that class's own
 * docblock for why it exists and why it can't be derived from real
 * filesystem paths).
 *
 * Legacy Coupling Retirement gap-closure (entry-shell define()/include
 * round): `Piwigo\Url\UrlService::getRootUrl()` used to fall back to
 * reading the raw `PHPWG_ROOT_PATH` constant for exactly this "how many
 * `../` do I prepend to reach the app's root from here" question --
 * coincidentally correct only because `admin/popuphelp.php`'s filesystem
 * depth (`PHPWG_ROOT_PATH` = `'../'`) happened to numerically match its
 * URL mount depth too, not a guaranteed general mechanism. This is the
 * real, request-scoped, URL-only equivalent, independent of wherever
 * `PHPWG_ROOT_PATH` doesn't exist any more.
 *
 * Lives at L1Infrastructure (not alongside `Router` in L3Presentation or
 * `RootPathOverride` in L2bExtendedDomain) specifically so both can depend
 * on it: `Router::pathInfo()` (L3) and `Piwigo\Url\UrlService` (L2b) both
 * need this same fact, and deptrac.yaml only allows each layer to depend
 * downward -- L1 is the lowest layer that covers both real callers.
 *
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3): the value is fixed once, at container-build time
 * (`Piwigo\Core\Container`'s own build() method, threaded from the one
 * entry-shell file that knows its own real mount depth --
 * `public/admin/popuphelp.php`),
 * never mutated afterward during a request -- no "current instance"
 * concept needed at all (same lesson as the Phase 0 `CurrentPersistentCache`
 * pilot). currentStatic() is a `@deprecated` transitional bridge for
 * callers not yet converted to constructor injection.
 */
final class RequestMountDepth
{
    public function __construct(
        private readonly int $depth = 0,
    ) {}

    public function current(): int
    {
        return $this->depth;
    }

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection -- Piwigo\Url\UrlService and Piwigo\Auth\
     * CookieService are both still manually `new`'d at dozens of call
     * sites each (singleton/service-locator elimination campaign, Phase 6),
     * so neither can take RequestMountDepth via constructor injection yet.
     * Gracefully falls back to 0 (the same value an unset instance already
     * defaults to) when Kernel hasn't booted -- both callers are reached by
     * many Unit tests that never boot a container at all, matching the
     * `InstallationFlag::isActiveStatic()` shim's own established
     * reasoning. Delete once `grep -rn "RequestMountDepth::currentStatic("`
     * outside tests/ returns nothing.
     */
    public static function currentStatic(): int
    {
        if (! Kernel::isBooted()) {
            return 0;
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->current();
    }
}
