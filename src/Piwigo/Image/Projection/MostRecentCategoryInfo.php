<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findMostRecentImageCategoryInfo()}'s
 * own row shape -- `Admin\PhotosAddDirectPageRenderer`'s own "default the
 * upload form to whichever album the last photo went into" lookup.
 */
final readonly class MostRecentCategoryInfo
{
    public function __construct(
        public int $categoryId,
        public string $uppercats,
    ) {}
}
