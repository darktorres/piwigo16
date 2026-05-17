<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use DI\ContainerBuilder;
use Piwigo\Core\Paths;
use Psr\Container\ContainerInterface;

final class Container
{
    /**
     * Build the DI container for one install.
     *
     * The Paths binding is overridden here (not in config/container.php) so
     * the install-root information arrives from the calling entry point —
     * the file that physically knows its own location — rather than
     * round-tripping through a global constant.
     *
     * @param array<string,mixed> $extraDefinitions
     */
    public static function build(Paths $paths, array $extraDefinitions = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions($paths->config . 'container.php');
        $builder->addDefinitions([Paths::class => $paths]);
        if ($extraDefinitions !== []) {
            $builder->addDefinitions($extraDefinitions);
        }
        return $builder->build();
    }
}
