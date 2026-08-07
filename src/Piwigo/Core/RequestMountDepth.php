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
 * Lives at L1Infrastructure (not alongside `Router` in L3Presentation or
 * `RootPathOverride` in L2bExtendedDomain) specifically so both can depend
 * on it: `Router::pathInfo()` (L3) and `Piwigo\Url\UrlService` (L2b) both
 * need this same fact, and deptrac.yaml only allows each layer to depend
 * downward -- L1 is the lowest layer that covers both real callers.
 *
 * Container-shared, immutable value: fixed once, at container-build time
 * (`Piwigo\Core\Container`'s own build() method, threaded from the one
 * entry-shell file that knows its own real mount depth --
 * `public/admin/popuphelp.php`), never mutated afterward during a
 * request. `Piwigo\Auth\CookieService` resolves this via its own private
 * lazy requestMountDepth() helper.
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
}
