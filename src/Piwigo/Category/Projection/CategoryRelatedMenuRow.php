<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryService::getRelatedCategoriesMenu()}'s
 * own row shape: `CategoryListingRow`'s 6 base fields plus `level`
 * (always set) and `countImages`/`countCategories` (set only when
 * applicable). Deliberately mutable (not `final readonly` like most
 * Projections in this codebase) -- `getRelatedCategoriesMenu()`'s parent-
 * counter accumulation loop increments `countCategories` on ancestor rows
 * in place while iterating, the same "object identity replaces a by-ref
 * array trick" reasoning as {@see ComputedCategoryRow}.
 */
final class CategoryRelatedMenuRow
{
    public function __construct(
        public readonly int $id,
        public string $name,
        public readonly ?string $permalink,
        public readonly ?int $idUppercat,
        public readonly string $uppercats,
        public readonly ?string $globalRank,
        public int $level = 0,
        public ?int $countImages = null,
        public ?int $countCategories = null,
    ) {}

    /**
     * The `array<string, mixed>` boundary --
     * {@see \Piwigo\Category\CategoryService::getRelatedCategoriesMenuWithUrls()}
     * splices a `url` key onto this shape and hands rows onward into
     * {@see \Piwigo\Core\UrlServiceInterface::makeIndexUrl()}'s own
     * deliberately-generic `array $params` sink; {@see \Piwigo\Category\
     * Event\RenderCategoryName}'s `$context` takes the same shape for the
     * same reason.
     *
     * @return array{id: int, name: string, permalink: ?string, id_uppercat: ?int, uppercats: string, global_rank: ?string, LEVEL: int, count_images?: int, count_categories?: int}
     */
    public function toArray(): array
    {
        $row = [
            'id' => $this->id,
            'name' => $this->name,
            'permalink' => $this->permalink,
            'id_uppercat' => $this->idUppercat,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
            'LEVEL' => $this->level,
        ];
        if ($this->countImages !== null) {
            $row['count_images'] = $this->countImages;
        }

        if ($this->countCategories !== null) {
            $row['count_categories'] = $this->countCategories;
        }

        return $row;
    }
}
