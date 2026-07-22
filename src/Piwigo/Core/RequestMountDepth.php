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
 * A self-managed registry, not constructor-injected DI, because this is
 * genuinely per-request *state* known only by the one entry file that sets
 * it (same shape as `CurrentTemplate`/`CurrentUser`/`RootPathOverride`),
 * not a stateless service with real dependencies of its own.
 */
final class RequestMountDepth
{
    private static int $depth = 0;

    public static function set(int $depth): void
    {
        self::$depth = $depth;
    }

    public static function current(): int
    {
        return self::$depth;
    }

    /**
     * Test-only -- production code never needs to clear this mid-request.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
