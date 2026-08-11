<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Image\ImageStdParams;

/**
 * Returns the container-shared instance once Kernel has booted. Before
 * boot, returns a memoized fallback instance, eagerly populated via a
 * real `loadFromDb()` read on first access, rather than a fresh
 * instance per call. Tests that call `setWatermark(...)` on one call's
 * return value (e.g. DerivativeParamsTest's "willWatermark" cases) rely
 * on a later call reading the same mutation back; a fresh, independent
 * instance per call would silently break that.
 */
final class ImageStdParamsTestFactory
{
    private static ?ImageStdParams $fallback = null;

    public static function get(): ImageStdParams
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(ImageStdParams::class);
            if (! $instance instanceof ImageStdParams) {
                throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
            }

            return $instance;
        }

        if (! self::$fallback instanceof ImageStdParams) {
            self::$fallback = new ImageStdParams();
            self::$fallback->loadFromDb();
        }

        return self::$fallback;
    }
}
