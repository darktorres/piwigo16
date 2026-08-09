<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * {@see \Piwigo\Category\CategoryService::getRandomImageInCategory()}'s
 * parameter object. The 4 real callers (`CategoryCatsRenderer`,
 * `Ws\PwgCategories`) each build this from a differently-sourced row (a
 * tree-cache rollup merge, a WS listing row) that already carries or can
 * trivially extract these 3 fields -- four pipelines that never got
 * unified onto one shape, not genuinely dynamic data.
 */
final readonly class RandomImageCategoryQuery
{
    public function __construct(
        public CategoryId $id,
        public string $uppercats,
        public int $countImages,
    ) {}
}
