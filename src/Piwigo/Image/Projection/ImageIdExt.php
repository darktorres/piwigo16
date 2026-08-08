<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * Shared `image_id`/`ext` row shape for
 * {@see \Piwigo\Image\ImageRepository::findFormatsByImageIds()},
 * {@see \Piwigo\Image\ImageRepository::findImageIdsAndExtsByFormatIds()},
 * and {@see \Piwigo\Image\ImageRepository::findAllImageIdsAndExts()} --
 * all 3 select the same 2 `image_format` columns, filtered differently.
 */
final readonly class ImageIdExt
{
    public function __construct(
        public int $imageId,
        public string $ext,
    ) {}
}
