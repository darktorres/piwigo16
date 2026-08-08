<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

use Piwigo\Common\ValueObject\ImageId;

/**
 * {@see \Piwigo\Image\ImageRepository::findByIdOrFilePattern()}'s own row
 * shape -- {@see \Piwigo\Controller\PictureController}'s "resolve the
 * requested picture, by id or by filename" lookup.
 */
final readonly class ImageLookupRow
{
    public function __construct(
        public ImageId $id,
        public string $file,
        public int $level,
    ) {}
}
