<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;

/**
 * Returns a fresh, no-op `severity => OFF` Logger when Kernel hasn't
 * booted; returns the container-shared CurrentLogger instance's own
 * Logger otherwise. The pre-boot fallback is a brand-new Logger per
 * call, never memoized.
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
