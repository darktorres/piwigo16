<?php

declare(strict_types=1);

namespace Piwigo\Http;

/**
 * Holds the {@see RequestContext} for the current request. Entry-point
 * controllers (`AdminController`, `WsController`, `UpgradeController`,
 * `ImageDerivativeController`) call {@see self::set()} at the top of their
 * `__invoke`; consumers compare `self::current()` against the enum cases to
 * branch on request type.
 *
 * Matches the established `FilterContextRegistry` / `SectionContextRegistry`
 * pattern: a thin static registry, one instance per SAPI request.
 */
final class RequestContextRegistry
{
    private static ?RequestContext $instance = null;

    public static function set(RequestContext $ctx): void
    {
        self::$instance = $ctx;
    }

    public static function current(): RequestContext
    {
        return self::$instance ?? RequestContext::Gallery;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
