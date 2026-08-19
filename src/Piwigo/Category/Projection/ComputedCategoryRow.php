<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\Contract\HasGlobalRank;

/**
 * {@see \Piwigo\Category\CategoryService::getComputedCategories()}'s own
 * per-category rollup row, merged with `name`/`permalink` by
 * {@see \Piwigo\Category\CategoryTreeCache::getForUser()}. Deliberately
 * mutable (not `final readonly` like every other row Projection in this
 * codebase): `getComputedCategories()`'s tree-walk increments
 * `nbCategories`/`countCategories`/`countImages`/`maxDateLast` on parent
 * rows while iterating -- see that method's own body. Object identity
 * (not a by-ref array trick) is what makes those in-place updates visible
 * through the `array<int, ComputedCategoryRow>` map that holds them.
 */
final class ComputedCategoryRow implements HasGlobalRank
{
    public function __construct(
        public readonly int $catId,
        public readonly ?int $idUppercat,
        public readonly ?string $globalRank,
        public readonly ?int $rank,
        public readonly ?string $dateLast,
        public readonly int $nbImages,
        public readonly int $userId,
        public int $nbCategories = 0,
        public int $countCategories = 0,
        public int $countImages = 0,
        public ?string $maxDateLast = null,
        public ?string $name = null,
        public ?string $permalink = null,
    ) {}

    public function getGlobalRank(): ?string
    {
        return $this->globalRank;
    }

    /**
     * The `array<string, mixed>` template-variable-bag boundary --
     * {@see \Piwigo\Category\CategoryService::getCategoriesMenu()} splices
     * NAME/TITLE/URL/LEVEL/SELECTED/IS_UPPERCAT/icon_ts onto this shape
     * before handing rows to the template layer.
     *
     * @return array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: int, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string, name: ?string, permalink: ?string, id: int}
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
            'user_id' => $this->userId,
            'nb_categories' => $this->nbCategories,
            'count_categories' => $this->countCategories,
            'count_images' => $this->countImages,
            'max_date_last' => $this->maxDateLast,
            'name' => $this->name,
            'permalink' => $this->permalink,
            'id' => $this->catId,
        ];
    }
}
