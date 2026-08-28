<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Image\DerivativeParams;

/**
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}'s own return
 * value -- the raw category-thumbnail-grid data a caller threads into its
 * own {@see \Piwigo\Controller\Projection\CategoryCatsView} construction.
 * Null when there is nothing to show (`render()`'s own "at least one
 * category survived filtering" gate) -- the caller must skip both the
 * render and the ambient `CATEGORIES` assign in that case, same as today.
 */
final readonly class CategoryCatsResult
{
    /**
     * @param list<CategoryThumbnail> $categoryThumbnails
     */
    public function __construct(
        public int $maxRequests,
        public array $categoryThumbnails,
        public DerivativeParams $derivativeParams,
    ) {}
}
