<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Psr\Container\ContainerExceptionInterface;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase
 * 12F-10: replaces the deleted `CurrentPaths::get()`/`CurrentPaths::isSet()`
 * transitional shim methods for test call sites -- reproduces their exact
 * behavior, including the real-`get()`-attempt-not-`has()` pattern (PHP-DI's
 * `has()` is unreliable for a concrete class like `Paths`, see the former
 * shim's own docblock).
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
