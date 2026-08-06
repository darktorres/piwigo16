<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Image\ImageStdParams;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-4:
 * replaces the deleted `ImageStdParams::current()` transitional shim for
 * test call sites -- reproduces its exact behavior, including the one
 * genuinely risky part: unlike every other closed shim in this campaign,
 * the pre-boot fallback here is a MEMOIZED instance (not a fresh one per
 * call), eagerly populated via a real `load_from_db()` read on first
 * access. Several real Unit tests (e.g. DerivativeParamsTest's own
 * "will_watermark" cases) call `set_watermark(...)` on one call's return
 * value and expect a LATER call to read the same mutation back -- a fresh,
 * independent instance per call would silently break that coupling. All
 * real test call sites route through this one factory now, so they all
 * share this one memoized instance, exactly matching the shim's own former
 * single `private static ?self $fallback` behavior.
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
            self::$fallback->load_from_db();
        }

        return self::$fallback;
    }
}
