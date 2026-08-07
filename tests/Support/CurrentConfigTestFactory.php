<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase
 * 12F-12: replaces the deleted `CurrentConfig::current()` transitional
 * shim for test call sites -- reproduces its exact behavior (the real
 * container-shared instance once Kernel has booted, a MEMOIZED, not
 * fresh-per-call, instance otherwise -- every property already carries
 * its own real, sensible hardcoded default, so the not-booted fallback is
 * safe to memoize the same way).
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
