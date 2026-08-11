<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image\Projection;

/**
 * {@see \Piwigo\Admin\Image\PwgImage::getResizeDimensions()}'s own
 * optional crop-rectangle sub-shape.
 */
final readonly class ResizeCrop
{
    public function __construct(
        public int|float $width,
        public int|float $height,
        public int|float $x,
        public int|float $y,
    ) {}
}
