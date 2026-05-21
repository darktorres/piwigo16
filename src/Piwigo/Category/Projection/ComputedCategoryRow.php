<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (cat_id, id_uppercat, global_rank, date_last, nb_images) aggregate row from CategoryRepository::findComputedCategoryAggregates(). */
final readonly class ComputedCategoryRow
{
    public function __construct(
        public int $catId,
        public ?int $idUppercat,
        public ?string $globalRank,
        public ?string $dateLast,
        public int $nbImages,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $catIdRaw = $row['cat_id'] ?? null;
        if (!is_numeric($catIdRaw)) {
            throw new \InvalidArgumentException('ComputedCategoryRow: missing `cat_id`');
        }
        return new self(
            catId:      (int) $catIdRaw,
            idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            dateLast:   is_string($row['date_last'] ?? null) ? $row['date_last'] : null,
            nbImages:   is_numeric($row['nb_images'] ?? null) ? (int) $row['nb_images'] : 0,
        );
    }
}
