<?php

declare(strict_types=1);

namespace Piwigo\Core;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * P7 boot skeleton. Builds a bare, zero-definition DI container and nothing
 * else — no Config/PageState/Lang/CurrentUser wiring (P16), no middleware
 * pipeline (P9), no real service definitions (P8's config/container.php).
 * This class is retrofitted incrementally by those later phases rather than
 * written once complete now.
 *
 * The `self::$booted` guard makes boot() idempotent — CommonBootstrap::run()
 * calling it more than once per request (e.g. from a nested include) must
 * not re-wire or corrupt state.
 */
final class Kernel
{
    private static bool $booted = false;

    private static ?ContainerInterface $container = null;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::$container = new ContainerBuilder()->build();
    }

    /**
     * Restricted to `Bootstrap/` and `index.php` by an arch test — services
     * must receive dependencies via constructor injection, never look them
     * up through this locator.
     */
    public static function container(): ContainerInterface
    {
        if (self::$container === null) {
            throw new \LogicException('Kernel not booted — call Kernel::boot() first.');
        }
        return self::$container;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    /**
     * Test-only — restricted to tests/ by an arch test.
     */
    public static function reset(): void
    {
        self::$booted = false;
        self::$container = null;
    }
}
