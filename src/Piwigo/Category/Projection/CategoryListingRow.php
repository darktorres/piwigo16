<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCategoriesByIds()}'s own
 * row shape -- a deliberately narrower 6-column contract than the full
 * {@see Category} projection, see that repository method's own docblock.
 *
 * `toArray()` preserves the exact original snake_case shape: several real
 * consumers (through {@see \Piwigo\Category\CategoryService}'s own
 * `getCategoriesByIds()`/`getRelatedCategoriesMenu()`) splice new keys onto
 * each row or pass the whole set through `array_column()`, both of which
 * need a plain array, not this DTO.
 */
final readonly class CategoryListingRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
        public ?int $idUppercat,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    /**
     * @return array{id: int, name: string, permalink: ?string, id_uppercat: ?int, uppercats: string, global_rank: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permalink' => $this->permalink,
            'id_uppercat' => $this->idUppercat,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
        ];
    }
}
