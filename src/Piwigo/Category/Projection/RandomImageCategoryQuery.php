<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * {@see \Piwigo\Category\CategoryService::getRandomImageInCategory()}'s
 * parameter object. The 4 real call sites (2 in `CategoryCatsRenderer`, 2
 * in `Controller\Api\Categories\CategoryAvailableListController`) each build
 * this from a differently-sourced row that already carries or can trivially
 * extract these 3 fields -- four pipelines that never got unified onto one
 * shape, not genuinely dynamic data.
 */
final readonly class RandomImageCategoryQuery
{
    public function __construct(
        public CategoryId $id,
        public string $uppercats,
        public int $countImages,
    ) {}
}
