<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findComputedCategoriesRollup()}'s
 * own row shape -- {@see \Piwigo\Category\CategoryService::
 * getComputedCategories()}'s real (and only) consumer, its tree-rollup
 * algorithm's starting point.
 *
 * `toArray()` exists for that consumer: it splices several new keys
 * (`user_id`/`nb_categories`/`count_categories`/`count_images`/
 * `max_date_last`) onto each row and mutates the result by reference while
 * walking the category tree -- a `readonly` DTO can't support that
 * directly, so the consumer `toArray()`s each row once, up front, and
 * mutates the resulting plain array from there.
 */
final readonly class ComputedCategoryRollupRow
{
    public function __construct(
        public int $catId,
        public ?int $idUppercat,
        public ?string $globalRank,
        public ?int $rank,
        public ?string $dateLast,
        public int $nbImages,
    ) {}

    /**
     * @return array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int}
     */
    public function toArray(): array
    {
        return [
            'cat_id' => $this->catId,
            'id_uppercat' => $this->idUppercat,
            'global_rank' => $this->globalRank,
            'rank' => $this->rank,
            'date_last' => $this->dateLast,
            'nb_images' => $this->nbImages,
        ];
    }
}
