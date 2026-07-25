<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Container\ContainerInterface;

/**
 * P7 boot skeleton, growing via P8's Container. P16 adds Paths threading.
 * `CurrentUser::attachGlobals()` (guest-user init) is deliberately NOT
 * called from here -- `Piwigo\Users\` is L2aCoreDomain, and deptrac's
 * ruleset only lets L1Infrastructure (this class's own layer) depend on
 * L0Data, not upward on L2a. `RequestBootstrap`/`CliBootstrap` (both
 * L4Integration, already allowed to depend on L2aCoreDomain) call it
 * instead, once the real per-request user is resolved.
 * `PageState::attachGlobals()` is ALSO not called from here, despite
 * `Piwigo\Core\` being this class's own layer -- it seeds an HTTP-only
 * per-request singleton (no `$page` concept on the CLI path that also
 * calls `Kernel::boot()`). `RequestBootstrap::finalize()` calls it
 * instead, right alongside `CurrentUser::attachGlobals()`.
 *
 * Deliberately does NOT run the P9 middleware pipeline -- that's
 * Piwigo\Bootstrap\RequestPipeline's job. Kernel must stay
 * infrastructure-only (L1Infrastructure in deptrac.yaml, which only allows
 * depending on L0Data); orchestrating Http/Routing/Container together is
 * genuinely an integration concern, the same reasoning that makes
 * RequestBootstrap itself L4Integration rather than living here.
 *
 * The `self::$booted` guard makes boot() idempotent — a second call within
 * the same request (e.g. from a nested include, or a caller that reaches
 * this method without knowing whether an earlier one already ran) must
 * not re-wire or corrupt state.
 *
 * Legacy Coupling Retirement gap-closure (entry-shell define()/include
 * round): also publishes $paths to CurrentPaths::set() here -- the one
 * point every real bootstrap path (HTTP, CLI, install) already converges
 * on a real Paths, for the handful of static-utility classes that can't
 * take constructor-injected Paths at all. See CurrentPaths's own
 * docblock.
 */
final class Kernel
{
    private static bool $booted = false;

    private static ?ContainerInterface $container = null;

    public static function boot(?Paths $paths = null): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if ($paths instanceof Paths) {
            CurrentPaths::set($paths);
        }

        self::$container = Container::build(paths: $paths);
    }

    /**
     * Restricted to `Bootstrap/` and `index.php` by an arch test — services
     * must receive dependencies via constructor injection, never look them
     * up through this locator.
     */
    public static function container(): ContainerInterface
    {
        if (! self::$container instanceof \Psr\Container\ContainerInterface) {
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
        CurrentPaths::reset();
    }
}
