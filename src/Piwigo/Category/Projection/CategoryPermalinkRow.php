<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (id, permalink, uppercats, global_rank) row from CategoryRepository::findCategoriesWithPermalink(). */
final readonly class CategoryPermalinkRow
{
    public function __construct(
        public int $id,
        public string $permalink,
        public string $uppercats,
        public ?string $globalRank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryPermalinkRow: missing `id`');
        }
        return new self(
            id:         (int) $idRaw,
            permalink:  is_string($row['permalink'] ?? null) ? $row['permalink'] : '',
            uppercats:  is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
        );
    }
}
