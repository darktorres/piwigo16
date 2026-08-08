<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findIdsAndImageOrderWithConditions()}'s
 * own row shape -- {@see \Piwigo\Ws\PwgCategories::getImages()}'s own
 * "which categories are we fetching images for" step, its real (and only)
 * consumer.
 */
final readonly class CategoryIdImageOrder
{
    public function __construct(
        public int $id,
        public ?string $imageOrder,
    ) {}
}
