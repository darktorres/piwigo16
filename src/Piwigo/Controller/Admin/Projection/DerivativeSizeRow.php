<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `configuration_sizes.latte`'s multiple-size table, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController} at both of the
 * two places that hand a derivative set to that template: the fresh render,
 * from `ImageStdParams`, and `processSizes()`'s validation-failure
 * redisplay, from the raw POST.
 *
 * `$width`/`$height`/`$sharpen` carry `int|float|string` because of that
 * second path: a failed save echoes back exactly what was typed, invalid values
 * included -- that is the point of the redisplay -- so these are the
 * submitted strings there, and on a fresh render whatever `Dimensions`
 * and `DerivativeParams` hold, which is `int|float`. `null` is the
 * third case, a type present in neither the enabled nor the disabled map,
 * where the keys used to be absent from the array outright and the template
 * read straight through them (an "Undefined array key" warning, and an
 * empty field).
 *
 * `$cropped` is a bool rather than the crop percentage it replaces. The
 * percentage is real -- 0 or 100 from the POST path, `round(100 *
 * max_crop)` on a fresh render -- but the template never uses it as a
 * number: all four reads ask whether cropping is on. The number stays where
 * it is used, inside the producer's own validation.
 */
final readonly class DerivativeSizeRow
{
    public function __construct(
        public bool $mustSquare,
        public bool $mustEnable,
        public bool $enabled,
        public bool $cropped,
        public int|float|string|null $width = null,
        public int|float|string|null $height = null,
        public int|float|string|null $sharpen = null,
    ) {}
}
