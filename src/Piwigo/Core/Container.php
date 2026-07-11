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
 * orchestration like CommonBootstrap. (See deptrac.yaml's own comment for
 * why the layer names have no hyphens -- a real deptrac 4.6.2 parsing bug,
 * found while verifying this placement.)
 *
 * No Paths parameter yet -- Paths doesn't exist until P16. Loads
 * config/container.php from a fixed relative path instead. No compilation
 * caching yet either -- that needs both Paths and enough real definitions
 * to be worth caching; premature with zero entries.
 */
final class Container
{
    /**
     * @param array<string, mixed> $extraDefinitions test-time overrides,
     *   merged after config/container.php so they win
     */
    public static function build(array $extraDefinitions = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 3) . '/config/container.php');
        if ($extraDefinitions !== []) {
            $builder->addDefinitions($extraDefinitions);
        }
        return $builder->build();
    }
}
