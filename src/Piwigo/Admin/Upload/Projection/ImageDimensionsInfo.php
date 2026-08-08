<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload\Projection;

/**
 * {@see \Piwigo\Admin\Upload\UploadService::pwgImageInfos()}'s own fixed
 * result shape.
 */
final readonly class ImageDimensionsInfo
{
    public function __construct(
        public ?int $width,
        public ?int $height,
        public float $filesize,
    ) {}
}
