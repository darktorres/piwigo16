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
        public string $type,
        public bool $hasAnimation,
        public bool $hasTransparent,
    ) {}
}
