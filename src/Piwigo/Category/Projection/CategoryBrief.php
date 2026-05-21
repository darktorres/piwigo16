<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (id, name, permalink, id_uppercat, uppercats, global_rank) row from CategoryRepository::findRelatedNavRowsByIds(). */
final readonly class CategoryBrief
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
        public ?int $idUppercat,
        public string $uppercats,
        public ?string $globalRank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryBrief: missing `id`');
        }
        return new self(
            id:         (int) $idRaw,
            name:       is_string($row['name'] ?? null) ? $row['name'] : '',
            permalink:  is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
            uppercats:  is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
        );
    }
}
