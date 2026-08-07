<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;

/**
 * Returns the container-shared CurrentConfig instance once Kernel has
 * booted; falls back to a memoized (not fresh-per-call) instance
 * otherwise. Every property already carries a real, sensible hardcoded
 * default, so the not-booted fallback is safe to memoize the same way.
 */
final class CurrentConfigTestFactory
{
    private static ?CurrentConfig $fallback = null;

    public static function get(): CurrentConfig
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(CurrentConfig::class);
            if (! $instance instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }

            return $instance;
        }

        return self::$fallback ??= new CurrentConfig();
    }
}
