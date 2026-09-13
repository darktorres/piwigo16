<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

use Piwigo\Image\DerivativeParams;

/**
 * Category/album-grid counterpart to {@see GetIndexDerivativeParams} --
 * legacy never dispatched a filter around the category-grid derivative
 * size at all (it was always the hardcoded `ImageStdParams::THUMB` fit,
 * unlike the image grid's own session-overridable size), so this has no
 * legacy hook name to trace back to. Added for a real caller (gdThumb,
 * `docs/plugin-porting/gdthumb-port-analysis.md` §3a): its own default
 * config height is larger than `THUMB`'s 144px, an unconditional forced
 * CSS-upscale at defaults with no override path before this existed.
 * Kept a separate class from `GetIndexDerivativeParams`, not a shared
 * one, for the same reason `IndexThumbnailsRendered`/
 * `IndexCategoryThumbnailsRendered` are already separate: a plugin may
 * legitimately want to size the two grids differently.
 */
final class GetCategoryDerivativeParams
{
    public function __construct(
        public DerivativeParams $params,
    ) {}
}
