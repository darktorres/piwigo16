<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findAllIdsAndFiles()}'s own row
 * shape -- `Ws\Images::formatsSearchImage()`'s own "build a
 * filename-without-extension index of every photo" scan.
 */
final readonly class ImageIdFile
{
    public function __construct(
        public int $id,
        public string $file,
    ) {}
}
