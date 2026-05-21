<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

/** Dimensions and filesize (in KB) for an uploaded image file. */
final readonly class ImageFileInfo
{
    public function __construct(
        public int $width,
        public int $height,
        public int|float $filesize,
    ) {
    }
}
