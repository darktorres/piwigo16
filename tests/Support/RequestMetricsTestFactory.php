<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\RequestMetrics;

/**
 * Same "container-shared once booted, memoized fallback before" shape
 * as PageStateTestFactory -- see that class's own docblock.
 */
final class RequestMetricsTestFactory
{
    private static ?RequestMetrics $fallback = null;

    public static function get(): RequestMetrics
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(RequestMetrics::class);
            if (! $instance instanceof RequestMetrics) {
                throw new LogicException('Container returned an unexpected type for ' . RequestMetrics::class);
            }

            return $instance;
        }

        return self::$fallback ??= new RequestMetrics();
    }
}
