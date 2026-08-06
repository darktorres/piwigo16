<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-1:
 * replaces the deleted `CurrentLogger::getStatic()` transitional shim for
 * test call sites -- reproduces its exact behavior (a fresh, no-op
 * `severity => OFF` Logger when Kernel hasn't booted, the real
 * container-shared instance's own Logger otherwise). No shared-fallback-
 * instance test coupling to worry about here: the shim's own pre-boot
 * fallback was always a brand-new Logger per call, never memoized.
 */
final class CurrentLoggerTestFactory
{
    public static function getStatic(): Logger
    {
        if (! Kernel::isBooted()) {
            return new Logger([
                'severity' => Logger::OFF,
            ]);
        }

        $instance = Kernel::container()->get(CurrentLogger::class);
        if (! $instance instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        return $instance->get();
    }
}
