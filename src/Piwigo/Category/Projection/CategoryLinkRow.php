<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (id, name, uppercats, global_rank) row from CategoryRepository::executeListingQuery(). */
final readonly class CategoryLinkRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string $uppercats,
        public ?string $globalRank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryLinkRow: missing `id`');
        }
        return new self(
            id:         (int) $idRaw,
            name:       is_string($row['name'] ?? null) ? $row['name'] : '',
            uppercats:  is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
        );
    }
}
