<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Boot skeleton, wired via Container. Also threads Paths.
 * `CurrentUser::attachGlobals()` (guest-user init) is deliberately NOT
 * called from here -- `Piwigo\Users\` is L2aCoreDomain, and deptrac's
 * ruleset only lets L1Infrastructure (this class's own layer) depend on
 * L0Data, not upward on L2a. `RequestBootstrap`/`CliBootstrap` (both
 * L4Integration, already allowed to depend on L2aCoreDomain) call it
 * instead, once the real per-request user is resolved.
 * `PageState` is ALSO not resolved from here, despite `Piwigo\Core\` being
 * this class's own layer -- it holds HTTP-only per-request state (no
 * `$page` concept on the CLI path that also calls `Kernel::boot()`).
 * `RequestBootstrap::configure()`/`finalize()` resolve it instead, right
 * alongside `CurrentUser::attachGlobals()`.
 *
 * Deliberately does NOT run the middleware pipeline -- that's
 * Piwigo\Bootstrap\RequestPipeline's job. Kernel must stay
 * infrastructure-only (L1Infrastructure in deptrac.yaml, which only allows
 * depending on L0Data); orchestrating Http/Routing/Container together is
 * genuinely an integration concern, the same reasoning that makes
 * RequestBootstrap itself L4Integration rather than living here.
 *
 * The `self::$booted` guard makes boot() idempotent — a second call within
 * the same request (e.g. from a nested include, or a caller that reaches
 * this method without knowing whether an earlier one already ran) must
 * not re-wire or corrupt state. A second call that names a Paths root
 * *different* from one already bound throws instead of silently keeping
 * the stale binding -- a caller passing a real root only to have it
 * ignored is exactly the shape that let a fixture-rooted test silently
 * keep an *earlier* test's real-repo-root Paths bound, so its own
 * filesystem-mutating code operated against the real repo instead of its
 * own disposable fixture directory. Only a root-vs-root mismatch throws;
 * boot(null) establishes no root to conflict with, so a later real Paths
 * completing the wiring (e.g. `Tests\Support\KernelContainerOverride`'s
 * reflection-installed container, deliberately Paths-less) is not an
 * error.
 *
 * $paths, once given, is bound as `Paths::class` inside
 * Container::build() -- the one point every real bootstrap path (HTTP,
 * CLI, install) already converges on a real Paths.
 */
final class Kernel
{
    private static bool $booted = false;

    private static ?ContainerInterface $container = null;

    private static ?Paths $boundPaths = null;

    public static function boot(?Paths $paths = null, int $mountDepth = 0, bool $isWs = false, bool $isAdmin = false): void
    {
        if (self::$booted) {
            if ($paths instanceof Paths && self::$boundPaths instanceof Paths && $paths->root !== self::$boundPaths->root) {
                throw new LogicException(
                    'Kernel already booted with a different Paths root ('
                    . self::$boundPaths->root
                    . ') -- call Kernel::reset() first to rebind it (e.g. between tests).'
                );
            }
            return;
        }
        self::$booted = true;
        self::$boundPaths = $paths;

        self::$container = Container::build(paths: $paths, mountDepth: $mountDepth, isWs: $isWs, isAdmin: $isAdmin);
    }

    /**
     * Restricted to `Bootstrap/` and `index.php` by an arch test — services
     * must receive dependencies via constructor injection, never look them
     * up through this locator.
     */
    public static function container(): ContainerInterface
    {
        if (! self::$container instanceof ContainerInterface) {
            throw new LogicException('Kernel not booted — call Kernel::boot() first.');
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
        self::$boundPaths = null;
    }
}
