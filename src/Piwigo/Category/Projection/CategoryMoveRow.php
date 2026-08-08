<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCategoriesForMove()}'s own
 * row shape -- {@see \Piwigo\Category\CategoryService::moveCategories()}'s
 * real (and only) consumer.
 */
final readonly class CategoryMoveRow
{
    public function __construct(
        public int $id,
        public ?int $idUppercat,
        public string $status,
        public string $uppercats,
    ) {}
}
