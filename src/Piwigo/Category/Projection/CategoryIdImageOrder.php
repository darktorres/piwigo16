<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findIdsAndImageOrderWithConditions()}'s
 * own row shape -- the "which categories are we fetching images for" step
 * of a category-images listing.
 */
final readonly class CategoryIdImageOrder
{
    public function __construct(
        public int $id,
        public ?string $imageOrder,
    ) {}
}
