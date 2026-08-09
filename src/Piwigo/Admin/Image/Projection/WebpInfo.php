<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image\Projection;

/**
 * {@see \Piwigo\Admin\Image\PwgImage::webp_info()}'s own fixed result
 * shape.
 */
final readonly class WebpInfo
{
    public function __construct(
        // $type/$hasTransparent: real, correctly-computed, extensively
        // tested WebP header facts (PwgImageTest.php's own webp_info()
        // cases assert exact values for both) -- ImageExtImagick.php's
        // one current caller only needs $hasAnimation, but this models
        // the complete, real webp_info() result shape (the class's own
        // purpose per its docblock), not speculative extra fields.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public string $type,
        public bool $hasAnimation,
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public bool $hasTransparent,
    ) {}
}
