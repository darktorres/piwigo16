<?php

declare(strict_types=1);

namespace Piwigo\Core;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * Builds the DI container. Lands in L1Infrastructure (Core), not
 * Bootstrap/L4Integration as in the reference implementation -- Kernel
 * (also L1) must call this before boot, and deptrac.yaml only allows
 * L1Infrastructure to depend on L0Data. Container is genuinely
 * infrastructure (the raw DI-wiring mechanism), not integration/
 * orchestration like RequestBootstrap. (See deptrac.yaml's own comment for
 * why the layer names have no hyphens -- a real deptrac 4.6.2 parsing bug,
 * found while verifying this placement.)
 *
 * P16 adds an optional `Paths` parameter -- when the caller has already
 * minted one at the entry point (`index.php`/`bin/piwigo`), it's
 * registered as a container instance so any service can receive it via
 * constructor injection. Still loads `config/container.php` from a fixed
 * relative path (its own location, not Paths -- Container itself must be
 * constructible without Paths for the rare caller that doesn't have one
 * yet, e.g. not-yet-updated tests). No compilation caching yet either --
 * that needs both Paths and enough real definitions to be worth caching;
 * premature with zero entries.
 */
final class Container
{
    /**
     * @param array<string, mixed> $extraDefinitions test-time overrides,
     *   merged after config/container.php so they win. $extraDefinitions
     *   stays the first (positional-compatible) parameter -- callers
     *   passing $paths use the named-argument form (`build(paths: $paths)`).
     * $mountDepth/$isWs/$isAdmin -- singleton/service-locator elimination
     *   campaign, Phase 3: RequestMountDepth/WsContext/AdminContext are
     *   immutable per-request facts known only by the one entry-shell file
     *   that sets them, threaded through here the same way $paths already
     *   is, and always bound (unlike $paths, these 3 have safe zero-value
     *   defaults, so there's no "caller doesn't have one yet" case to
     *   guard against).
     */
    public static function build(array $extraDefinitions = [], ?Paths $paths = null, int $mountDepth = 0, bool $isWs = false, bool $isAdmin = false): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 3) . '/config/container.php');
        if ($paths instanceof \Piwigo\Core\Paths) {
            $builder->addDefinitions([
                Paths::class => $paths,
            ]);
        }
        $builder->addDefinitions([
            RequestMountDepth::class => new RequestMountDepth($mountDepth),
            WsContext::class => new WsContext($isWs),
            AdminContext::class => new AdminContext($isAdmin),
        ]);
        if ($extraDefinitions !== []) {
            $builder->addDefinitions($extraDefinitions);
        }
        return $builder->build();
    }
}
