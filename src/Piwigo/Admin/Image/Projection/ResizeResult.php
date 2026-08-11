<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image\Projection;

/**
 * {@see \Piwigo\Admin\Image\ImageBackend::pwgResize()}'s own fixed result
 * shape.
 */
final readonly class ResizeResult
{
    public function __construct(
        public string $source,
        public string $destination,
        public int|float $width,
        public int|float $height,
        public string $size,
        public ?string $time,
        public string|false $library,
    ) {}
}
