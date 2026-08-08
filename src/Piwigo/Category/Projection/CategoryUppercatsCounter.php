<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCommonCategories()}'s own
 * row shape -- {@see \Piwigo\Category\CategoryService::getCommonCategories()}'s
 * real (and only) consumer, its own pass-through contract.
 *
 * `toArray()` exists for that consumer: `getCommonCategories()` keeps its
 * existing plain-array return shape, and
 * {@see \Piwigo\Category\CategoryService::getRelatedCategoriesMenu()}
 * reads `uppercats` off each row directly from that plain-array result.
 */
final readonly class CategoryUppercatsCounter
{
    public function __construct(
        public int $id,
        public string $uppercats,
        public int $counter,
    ) {}

    /**
     * @return array{id: int, uppercats: string, counter: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uppercats' => $this->uppercats,
            'counter' => $this->counter,
        ];
    }
}
