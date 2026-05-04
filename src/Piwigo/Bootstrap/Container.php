<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final class Container
{
    /** @param array<string,mixed> $extraDefinitions */
    public static function build(array $extraDefinitions = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(PHPWG_ROOT_PATH . 'config/container.php');
        if ($extraDefinitions !== []) {
            $builder->addDefinitions($extraDefinitions);
        }
        return $builder->build();
    }
}
