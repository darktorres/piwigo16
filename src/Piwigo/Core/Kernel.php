<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Container\ContainerInterface;

/**
 * P7 boot skeleton, growing via P8's Container. Still no
 * Config/PageState/Lang/CurrentUser wiring (P16). This class is
 * retrofitted incrementally by those later phases rather than written once
 * complete now.
 *
 * Deliberately does NOT run the P9 middleware pipeline -- that's
 * Piwigo\Bootstrap\RequestPipeline's job. Kernel must stay
 * infrastructure-only (L1Infrastructure in deptrac.yaml, which only allows
 * depending on L0Data); orchestrating Http/Routing/Container together is
 * genuinely an integration concern, the same reasoning that makes
 * CommonBootstrap itself L4Integration rather than living here.
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

        self::$container = Container::build();
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
