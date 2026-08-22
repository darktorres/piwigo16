<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * A width/height pair -- the same two-element positional tuple
 * previously written out independently as `SizingParams::$ideal_size`/
 * `$min_size`/`compute()`'s own `$in_size` param and `$scale_size`
 * by-ref out-param, `ImageRect`'s own constructor param, and
 * `DerivativeUrlCodec::urlToSize()`'s return type.
 *
 * `int|float`, not just `int`: `$ideal_size`/`$min_size`/`$in_size` are
 * always genuinely int (per their own former docblocks), but
 * `compute()`'s `$scale_size` out-param is derived from `ImageRect`'s
 * float-typed rectangle math and can genuinely hold a float on some
 * paths -- the same "genuinely holds either type at runtime" duality
 * {@see SizingParams::$max_crop}'s own docblock already documents for
 * the same reason. Callers that need a guaranteed int (e.g.
 * `DerivativeParams::maxWidth()`) cast explicitly at the read site.
 */
final readonly class Dimensions
{
    public function __construct(
        public int|float $width,
        public int|float $height,
    ) {}
}
