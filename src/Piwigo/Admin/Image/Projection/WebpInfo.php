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
        // Real, tested webp_info() facts -- the one current caller only
        // needs $hasAnimation, but this models the complete result shape.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public string $type,
        public bool $hasAnimation,
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public bool $hasTransparent,
    ) {}
}
