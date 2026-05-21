<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (id, name, permalink, dir, rank, status) row from CategoryRepository::findCategoryListing(). */
final readonly class CategoryListingBrief
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
        public ?string $dir,
        public ?int $rank,
        public string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw   = $row['id'] ?? null;
        $rankRaw = $row['rank'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryListingBrief: missing `id`');
        }
        return new self(
            id:        (int) $idRaw,
            name:      is_string($row['name'] ?? null) ? $row['name'] : '',
            permalink: is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            dir:       is_string($row['dir'] ?? null) ? $row['dir'] : null,
            rank:      is_numeric($rankRaw) ? (int) $rankRaw : null,
            status:    is_string($row['status'] ?? null) ? $row['status'] : 'public',
        );
    }
}
