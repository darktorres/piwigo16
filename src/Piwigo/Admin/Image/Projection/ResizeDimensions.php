<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image\Projection;

/**
 * {@see \Piwigo\Admin\Image\PwgImage::get_resize_dimensions()}'s own
 * fixed result shape.
 */
final readonly class ResizeDimensions
{
    public function __construct(
        public int|float $width,
        public int|float $height,
        public ?ResizeCrop $crop = null,
    ) {}
}
