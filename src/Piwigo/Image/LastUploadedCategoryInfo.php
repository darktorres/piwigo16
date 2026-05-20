<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** (category_id, uppercats) pair returned by ImageRepository::findLastUploadedCategoryInfo(). */
final readonly class LastUploadedCategoryInfo
{
    public function __construct(
        public int    $categoryId,
        public string $uppercats,
    ) {
    }
}
