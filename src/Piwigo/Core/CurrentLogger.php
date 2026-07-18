<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Static accessor for the current request's `Logger` instance -- Legacy
 * Coupling Retirement Track A gap-fill batch G5, replacing the legacy
 * `global $logger;` bridge. Same shape as this codebase's other
 * per-request singleton facades (`Piwigo\Template\CurrentTemplate`,
 * `Piwigo\Users\CurrentUser`): a self-managed static instance with
 * `get()`/`set()`, not constructor-injected DI -- consistent with the
 * established pattern for "the current request's X" rather than
 * introducing a second competing shape for the same problem.
 *
 * Two construction sites, matching the two independent bootstrap paths
 * that each build their own Logger: `Piwigo\Bootstrap\RequestBootstrap::
 * connect()` (the normal request pipeline) and
 * `Piwigo\Controller\ImageDerivativeController::serve()` (the fast
 * derivative-image path, which deliberately never runs RequestBootstrap).
 */
final class CurrentLogger
{
    private static ?Logger $instance = null;

    public static function get(): Logger
    {
        if (! self::$instance instanceof Logger) {
            throw new \LogicException('CurrentLogger not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() or Piwigo\Controller\ImageDerivativeController::serve() first.');
        }

        return self::$instance;
    }

    public static function set(Logger $logger): void
    {
        self::$instance = $logger;
    }

    public static function isInitialized(): bool
    {
        return self::$instance instanceof Logger;
    }

    /**
     * Test-only, for test-isolation between requests -- mirrors
     * CurrentTemplate's/CurrentUser's own reset() methods.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
