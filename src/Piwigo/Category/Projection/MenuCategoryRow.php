<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;

/**
 * Category meta joined with the per-user `user_cache_categories` count.
 * Returned by `CategoryRepository::findCategoriesMenuRows` for the
 * sidebar menu builder.
 */
final readonly class MenuCategoryRow
{
    public function __construct(
        public CategoryId $id,
        public string $name,
        public ?Permalink $permalink,
        public int $nbImages,
        public ?string $globalRank,
        public ?string $dateLast,
        public ?string $maxDateLast,
        public int $countImages,
        public int $countCategories,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw            = $row['id'] ?? null;
        $nameRaw          = $row['name'] ?? null;
        $nbImagesRaw      = $row['nb_images'] ?? null;
        $globalRankRaw    = $row['global_rank'] ?? null;
        $dateLastRaw      = $row['date_last'] ?? null;
        $maxDateLastRaw   = $row['max_date_last'] ?? null;
        $countImagesRaw   = $row['count_images'] ?? null;
        $countCatsRaw     = $row['count_categories'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('MenuCategoryRow is missing required `id` field');
        }
        return new self(
            id:              CategoryId::from((int) $idRaw),
            name:            is_string($nameRaw) ? $nameRaw : '',
            permalink:       Permalink::tryFrom($row['permalink'] ?? null),
            nbImages:        is_numeric($nbImagesRaw) ? (int) $nbImagesRaw : 0,
            globalRank:      is_string($globalRankRaw) ? $globalRankRaw : null,
            dateLast:        is_string($dateLastRaw) ? $dateLastRaw : null,
            maxDateLast:     is_string($maxDateLastRaw) ? $maxDateLastRaw : null,
            countImages:     is_numeric($countImagesRaw) ? (int) $countImagesRaw : 0,
            countCategories: is_numeric($countCatsRaw) ? (int) $countCatsRaw : 0,
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id'               => $this->id->value,
            'name'             => $this->name,
            'permalink'        => $this->permalink?->value,
            'nb_images'        => $this->nbImages,
            'global_rank'      => $this->globalRank,
            'date_last'        => $this->dateLast,
            'max_date_last'    => $this->maxDateLast,
            'count_images'     => $this->countImages,
            'count_categories' => $this->countCategories,
        ];
    }
}
