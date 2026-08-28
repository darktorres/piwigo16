<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One row of `picture_formats.latte`'s `$formats` list, built by
 * {@see \Piwigo\Admin\PictureFormatsPageRenderer::render()} from a real
 * {@see \Piwigo\Image\Projection\ImageFormat} row plus 3 view-only
 * computed fields (`downloadUrl`/`label`/`filesize`, the last a
 * KB-rounded override of the DB row's own byte count).
 */
final readonly class PictureFormatRow
{
    public function __construct(
        public int $formatId,
        public float $filesize,
        public string $downloadUrl,
        public string $label,
    ) {}
}
