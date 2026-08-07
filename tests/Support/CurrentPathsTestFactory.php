<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Psr\Container\ContainerExceptionInterface;

/**
 * get()/isSet() attempt a real container `get()` rather than checking
 * `has()` first: PHP-DI's `has()` is unreliable for a concrete class
 * like `Paths`.
 */
final class CurrentPathsTestFactory
{
    public static function get(): Paths
    {
        if (! Kernel::isBooted()) {
            throw new LogicException('CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.');
        }

        try {
            $paths = Kernel::container()->get(Paths::class);
        } catch (ContainerExceptionInterface) {
            throw new LogicException('CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.');
        }

        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    public static function isSet(): bool
    {
        if (! Kernel::isBooted()) {
            return false;
        }

        try {
            return Kernel::container()->get(Paths::class) instanceof Paths;
        } catch (ContainerExceptionInterface) {
            return false;
        }
    }
}
